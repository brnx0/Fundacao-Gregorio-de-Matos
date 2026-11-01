<?php

namespace App\Http\Controllers\Avaliacao;

use App\Enums\ModalidadeCota;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Avaliacao\SalvarNotasRequest;
use App\Models\EditalCriterio;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use App\Models\InscricaoDocumento;
use App\Support\Avaliacao\AvaliadorDaSessao;
use App\Support\Avaliacao\CriteriosDaInscricao;
use App\Support\Avaliacao\NotaFinal;
use App\Support\Cnpj;
use App\Support\Cpf;
use App\Support\Inscricao\ConferenciaDaInscricao;
use App\Support\Inscricao\PropsDaInscricao;
use App\Support\Inscricao\QuestionarioDaInscricao;
use App\Support\Inscricao\RetratoDoPerfil;
use App\Support\Inscricao\Travas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A inscrição que o avaliador da sessão avalia: só para leitura (escolhas, perfil, documentos e questionário, sem o
 * parecer da IA nem as marcações da homologação), as notas dos critérios que valem para ela e a conclusão. Inscrição
 * que não é dele (ou não está homologada, ou com o edital apagado) dá 404 em tudo. Salvar e concluir travam o edital e
 * depois a inscrição (a mesma ordem da transferência na retaguarda) e conferem de novo na linha relida.
 */
class InscricoesDoAvaliadorController extends Controller
{
    public const string ERRO_CONCLUIDA = 'Esta avaliação já foi concluída.';

    public const string ERRO_FORA_DO_PERIODO = 'Fora do período de avaliação: a inscrição fica só para consulta.';

    public const string ERRO_FALTAM_NOTAS = 'Dê nota a todos os critérios para concluir.';

    public const string AVISO_SEM_PERIODO = 'A chamada desta inscrição não tem período de avaliação.';

    public const string SUCESSO_RASCUNHO = 'Rascunho salvo.';

    public const string SUCESSO_CONCLUSAO = 'Avaliação concluída.';

    public function show(Request $request, Inscricao $inscricao): Response
    {
        $avaliacao = self::avaliacaoDoAvaliador($request, $inscricao);
        $avaliacao->load('notas');

        $inscricao->load(['edital:id,slug,titulo', 'chamada', 'user:id,name', 'organizacao:id,razao_social,nome_fantasia', 'categoria:id,nome']);
        $conferencia = new ConferenciaDaInscricao($inscricao);
        $questionario = new QuestionarioDaInscricao($inscricao);
        $enviados = $inscricao->documentos()->get()
            ->keyBy(fn (InscricaoDocumento $enviado): int => (int) $enviado->edital_documento_id);
        $notas = $avaliacao->notas->keyBy(fn (InscricaoAvaliacaoNota $nota): int => (int) $nota->edital_criterio_id);
        $concluida = $avaliacao->concluida_em !== null;

        return Inertia::render('Avaliacao/Inscricao', [
            'inscricao' => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'edital' => $inscricao->edital->titulo,
                'chamada' => $inscricao->chamada?->nome,
                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                'documento' => $this->documento($inscricao->documento_cadastro),
                'cadastro' => $inscricao->organizacao_id === null ? 'Pessoa física' : 'Pessoa jurídica',
                'categoria' => $inscricao->categoria?->nome,
                'cotas' => $inscricao->cotas?->map(fn (ModalidadeCota $cota): string => $cota->rotulo())->values()->all() ?? [],
            ],
            'perfil' => RetratoDoPerfil::linhas($inscricao->perfil),
            'documentos' => $conferencia->documentos()
                ->map(fn (EditalDocumento $documento): array => [
                    'id' => (int) $documento->id,
                    'nome' => $documento->nome,
                    'etapaRotulo' => $documento->etapa->rotulo(),
                    'obrigatorio' => $documento->obrigatorio,
                    'enviado' => $this->arquivoEnviado($inscricao, $documento, $enviados->get((int) $documento->id)),
                ])
                ->values()
                ->all(),
            'secoes' => PropsDaInscricao::secoes($questionario),
            // Objeto mesmo vazio: a tela lê as respostas pelo id da pergunta.
            'respostas' => (object) $questionario->respostasSalvas(),
            'perguntasVisiveis' => $conferencia->perguntas()->map(fn (EditalPergunta $pergunta): int => (int) $pergunta->id)->values()->all(),
            // Com nota dada, o peso e a pontuação máxima são os copiados nela (o que conta na nota final).
            'criterios' => CriteriosDaInscricao::para($inscricao)
                ->map(function (EditalCriterio $criterio) use ($notas): array {
                    $nota = $notas->get((int) $criterio->id);

                    return [
                        'id' => (int) $criterio->id,
                        'nome' => $criterio->nome,
                        'descricao' => $criterio->descricao,
                        'peso' => self::numero($nota?->peso ?? $criterio->peso ?? NotaFinal::PESO_PADRAO),
                        'pontosMaximos' => ($nota?->pontos_maximos ?? $criterio->pontos_maximos) === null
                            ? null
                            : self::numero($nota?->pontos_maximos ?? $criterio->pontos_maximos),
                    ];
                })
                ->values()
                ->all(),
            // Pelo id do critério, com vírgula decimal, como o avaliador digita.
            'notas' => (object) $notas->map(fn (InscricaoAvaliacaoNota $nota): string => self::numero($nota->nota))->all(),
            'observacao' => $avaliacao->observacao,
            'situacaoRotulo' => $avaliacao->situacao()->rotulo(),
            'notaFinal' => $concluida && $avaliacao->nota_final !== null ? self::numero($avaliacao->nota_final) : null,
            'podeAvaliar' => ! $concluida && $inscricao->naAvaliacao(),
            'aviso' => $this->aviso($inscricao, $concluida),
        ]);
    }

    /** Abre na aba o arquivo que a inscrição enviou para o documento; 404 sem envio ou sem o arquivo no disco. */
    public function arquivo(Request $request, Inscricao $inscricao, EditalDocumento $documento): StreamedResponse
    {
        self::avaliacaoDoAvaliador($request, $inscricao);

        $enviado = $inscricao->documentos()->where('edital_documento_id', $documento->id)->first();

        abort_unless($enviado !== null && Storage::disk(Inscricao::DISCO)->exists($enviado->arquivo), 404);

        return Storage::disk(Inscricao::DISCO)->response($enviado->arquivo, $enviado->arquivo_nome);
    }

    /** Grava o rascunho: as notas dadas, apaga as deixadas em branco, e a observação. */
    public function salvar(SalvarNotasRequest $request, Inscricao $inscricao): RedirectResponse
    {
        $erro = $this->gravar($request, $inscricao, concluir: false);

        return $erro === null ? back()->with('sucesso', self::SUCESSO_RASCUNHO) : back()->with('erro', $erro);
    }

    /** Grava como no rascunho e conclui: exige nota em todos os critérios e grava a nota final. Depois, nada muda. */
    public function concluir(SalvarNotasRequest $request, Inscricao $inscricao): RedirectResponse
    {
        $erro = $this->gravar($request, $inscricao, concluir: true);

        if ($erro !== null) {
            return back()->with('erro', $erro);
        }

        return redirect()->route('avaliacao.inscricoes.show', $inscricao)->with('sucesso', self::SUCESSO_CONCLUSAO);
    }

    /**
     * A avaliação da inscrição, se for do avaliador da sessão e a inscrição estiver homologada com o edital ativo;
     * senão 404.
     */
    public static function avaliacaoDoAvaliador(Request $request, Inscricao $inscricao): InscricaoAvaliacao
    {
        $avaliador = AvaliadorDaSessao::atual($request);
        $avaliacao = $inscricao->avaliacao()->first();

        abort_unless(
            $avaliacao !== null
                && (int) $avaliacao->avaliador_id === (int) $avaliador->id
                && self::homologada($inscricao)
                && $inscricao->edital !== null,
            404,
        );

        return $avaliacao;
    }

    /** Número com 2 casas e vírgula decimal, sem separador de milhar ("8,50"): a nota volta assim para o campo. */
    public static function numero(string|float|int $valor): string
    {
        return number_format((float) $valor, 2, ',', '');
    }

    /**
     * Salva (e conclui) com o edital e a inscrição travados. Relida a avaliação na linha travada: se mudou de avaliador
     * (transferência) ou a inscrição deixou de estar homologada, 404; concluída ou fora do período, o erro.
     */
    private function gravar(SalvarNotasRequest $request, Inscricao $inscricao, bool $concluir): ?string
    {
        $avaliadorId = (int) AvaliadorDaSessao::atual($request)->id;
        $notas = $request->notasEnviadas();
        $observacao = $request->observacao();

        return DB::transaction(function () use ($inscricao, $avaliadorId, $notas, $observacao, $concluir): ?string {
            Travas::edital($inscricao->edital);
            $travada = Travas::inscricao($inscricao);
            $avaliacao = InscricaoAvaliacao::query()->where('inscricao_id', $travada->id)->first();

            abort_unless($avaliacao !== null && (int) $avaliacao->avaliador_id === $avaliadorId && self::homologada($travada), 404);

            if ($avaliacao->concluida_em !== null) {
                return self::ERRO_CONCLUIDA;
            }

            if (! $travada->naAvaliacao()) {
                return self::ERRO_FORA_DO_PERIODO;
            }

            // Os critérios relidos com o edital travado: a edição do edital espera esta transação.
            $criterios = CriteriosDaInscricao::para($travada);
            $erros = SalvarNotasRequest::errosDasNotas($criterios, $notas);

            if ($erros !== []) {
                throw ValidationException::withMessages($erros);
            }

            foreach ($criterios as $criterio) {
                $nota = $notas[(int) $criterio->id] ?? null;

                if ($nota === null) {
                    $avaliacao->notas()->where('edital_criterio_id', $criterio->id)->delete();

                    continue;
                }

                $avaliacao->notas()->updateOrCreate(
                    ['edital_criterio_id' => $criterio->id],
                    [
                        'nota' => $nota,
                        'peso' => $criterio->peso ?? NotaFinal::PESO_PADRAO,
                        'pontos_maximos' => $criterio->pontos_maximos,
                    ],
                );
            }

            $avaliacao->update(['observacao' => $observacao]);

            if (! $concluir) {
                return null;
            }

            $gravadas = $avaliacao->notas()->get();
            $comNota = $gravadas->map(fn (InscricaoAvaliacaoNota $nota): int => (int) $nota->edital_criterio_id)->all();
            $faltaNota = $criterios->isEmpty()
                || $criterios->contains(fn (EditalCriterio $criterio): bool => ! in_array((int) $criterio->id, $comNota, true));

            if ($faltaNota) {
                return self::ERRO_FALTAM_NOTAS;
            }

            $avaliacao->update(['concluida_em' => now(), 'nota_final' => NotaFinal::de($gravadas)]);

            return null;
        });
    }

    /** Finalizada e homologada: só essas são avaliadas. */
    private static function homologada(Inscricao $inscricao): bool
    {
        return $inscricao->situacao === SituacaoInscricao::Finalizada && $inscricao->homologacao === SituacaoHomologacao::Homologada;
    }

    /** Concluída ou fora do período (ou sem ele), por que a inscrição está só para consulta; nulo quando pode avaliar. */
    private function aviso(Inscricao $inscricao, bool $concluida): ?string
    {
        if ($concluida) {
            return self::ERRO_CONCLUIDA;
        }

        if ($inscricao->naAvaliacao()) {
            return null;
        }

        return $inscricao->chamada?->temPeriodoDeAvaliacao() === true ? self::ERRO_FORA_DO_PERIODO : self::AVISO_SEM_PERIODO;
    }

    /**
     * Nome e URL (rota do avaliador) do arquivo enviado; nulo sem envio.
     *
     * @return array{nome: string, url: string}|null
     */
    private function arquivoEnviado(Inscricao $inscricao, EditalDocumento $documento, ?InscricaoDocumento $enviado): ?array
    {
        return $enviado === null ? null : [
            'nome' => $enviado->arquivo_nome,
            'url' => route('avaliacao.inscricoes.arquivo', [$inscricao, $documento]),
        ];
    }

    /** CPF mascarado (11 dígitos) ou CNPJ formatado. */
    private function documento(string $documento): string
    {
        return strlen($documento) === 11 ? Cpf::mascarar($documento) : Cnpj::formatar($documento);
    }
}
