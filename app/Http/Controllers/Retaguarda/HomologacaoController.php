<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\DecisaoRecurso;
use App\Enums\MarcacaoDoItem;
use App\Enums\ModalidadeCota;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Http\Requests\Retaguarda\DecidirHomologacaoRequest;
use App\Http\Requests\Retaguarda\MarcarItemRequest;
use App\Models\Edital;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecursoDocumento;
use App\Support\Cnpj;
use App\Support\Cpf;
use App\Support\Inscricao\ConferenciaDaInscricao;
use App\Support\Inscricao\PropsDaInscricao;
use App\Support\Inscricao\QuestionarioDaInscricao;
use App\Support\Inscricao\RetratoDoPerfil;
use App\Support\Inscricao\Travas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Homologação das inscrições finalizadas: a lista do gestor, com o que pede atenção primeiro, e a conferência de cada
 * inscrição. Os arquivos saem só pela rota de arquivo, nunca pelo caminho no disco.
 *
 * @phpstan-import-type Marcacao from ConferenciaDaInscricao
 */
class HomologacaoController extends Controller
{
    public const string AVISO_SEM_PERIODO = 'A chamada desta inscrição não tem período de homologação. Cadastre o período na aba Períodos do edital para conferir e decidir.';

    public const string AVISO_FORA_DO_PERIODO = 'Fora do período de homologação da chamada (:inicio a :fim): a inscrição fica só para consulta.';

    public const string ERRO_FORA_DO_PERIODO = 'Fora do período de homologação: a inscrição fica só para consulta.';

    public const string ERRO_JA_DECIDIDA = 'Esta inscrição já tem decisão registrada e fica só para consulta.';

    public const string ERRO_NAO_FINALIZADA = 'Só inscrições finalizadas podem ser conferidas.';

    public const string ERRO_ITEM = 'Este item não faz parte da conferência desta inscrição.';

    public const string ERRO_HOMOLOGAR_COM_PENDENCIA = 'Para homologar, marque todos os itens como ok.';

    public const string ERRO_NAO_HOMOLOGAR_SEM_MOTIVO = 'Marque ao menos um item com problema ou escreva o motivo.';

    /** Valor do filtro das inscrições ainda sem decisão. */
    private const string SEM_DECISAO = 'sem_decisao';

    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('busca', ''));
        $edital = ctype_digit((string) $request->query('edital', '')) ? (string) $request->query('edital') : '';
        $situacao = (string) $request->query('situacao', '');
        $situacaoDecidida = SituacaoHomologacao::tryFrom($situacao);
        if ($situacaoDecidida === null && $situacao !== self::SEM_DECISAO) {
            $situacao = '';
        }

        $inscricoes = $this->finalizadasDosEditaisPublicados()
            ->select([
                'inscricoes.id', 'inscricoes.edital_id', 'inscricoes.user_id', 'inscricoes.organizacao_id',
                'inscricoes.edital_categoria_id', 'inscricoes.documento_cadastro', 'inscricoes.numero',
                'inscricoes.finalizada_em', 'inscricoes.homologacao',
            ])
            ->with(['edital:id,titulo', 'user:id,name', 'organizacao:id,razao_social,nome_fantasia', 'categoria:id,nome'])
            ->when($edital !== '', fn (Builder $q) => $q->where('inscricoes.edital_id', (int) $edital))
            ->when($situacaoDecidida !== null, fn (Builder $q) => $q->where('inscricoes.homologacao', $situacaoDecidida->value))
            ->when($situacao === self::SEM_DECISAO, fn (Builder $q) => $q->whereNull('inscricoes.homologacao'))
            ->when($busca !== '', fn (Builder $q) => $this->buscar($q, $busca))
            ->orderByRaw("CASE WHEN inscricoes.homologacao = 'em_recurso' THEN 0 WHEN inscricoes.homologacao IS NULL THEN 1 ELSE 2 END")
            ->orderBy('inscricoes.finalizada_em')
            ->orderBy('inscricoes.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Inscricao $inscricao): array => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                'documento' => $this->documento($inscricao->documento_cadastro),
                'edital' => $inscricao->edital->titulo,
                'categoria' => $inscricao->categoria?->nome,
                'finalizadaEm' => $inscricao->finalizada_em->format('d/m/Y H:i'),
                'homologacao' => $inscricao->homologacao?->value,
                'situacaoRotulo' => $inscricao->homologacao?->rotulo() ?? 'Sem decisão',
            ]);

        $editais = Edital::query()
            ->publicados()
            ->whereHas('inscricoes', fn (Builder $q) => $q->where('situacao', SituacaoInscricao::Finalizada))
            ->orderBy('titulo')
            ->orderBy('id')
            ->get(['id', 'titulo'])
            ->map(fn (Edital $e): array => ['valor' => (string) $e->id, 'rotulo' => $e->titulo])
            ->values();

        return Inertia::render('Retaguarda/Homologacao/Index', [
            'inscricoes' => $inscricoes,
            'filtros' => ['edital' => $edital, 'situacao' => $situacao, 'busca' => $busca],
            'editais' => $editais,
            'situacoes' => [
                ['valor' => self::SEM_DECISAO, 'rotulo' => 'Sem decisão'],
                ...array_map(fn (SituacaoHomologacao $s): array => ['valor' => $s->value, 'rotulo' => $s->rotulo()], SituacaoHomologacao::cases()),
            ],
        ]);
    }

    /**
     * A inscrição finalizada para conferir: os documentos (com o parecer da IA), as respostas, as marcações, a decisão e
     * o recurso. Marcar e decidir só sem decisão e dentro do período de homologação da chamada; fora dele (ou sem ele),
     * só consulta, com o aviso.
     */
    public function show(Inscricao $inscricao): Response
    {
        abort_unless($inscricao->situacao === SituacaoInscricao::Finalizada && $inscricao->edital !== null, 404);

        $inscricao->load(['edital:id,titulo', 'chamada', 'user:id,name', 'organizacao:id,razao_social,nome_fantasia', 'categoria:id,nome', 'homologador:id,name']);
        $conferencia = new ConferenciaDaInscricao($inscricao);
        $questionario = new QuestionarioDaInscricao($inscricao);
        $marcacoes = $conferencia->marcacoes();
        $enviados = $inscricao->documentos()->get()
            ->keyBy(fn (InscricaoDocumento $enviado): int => (int) $enviado->edital_documento_id);
        $podeConferir = $inscricao->homologacao === null && $inscricao->naHomologacao();
        $chamada = $inscricao->chamada;

        return Inertia::render('Retaguarda/Homologacao/Show', [
            'inscricao' => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'edital' => $inscricao->edital->titulo,
                'chamada' => $chamada?->nome,
                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                'documento' => $this->documento($inscricao->documento_cadastro),
                'cadastro' => $inscricao->organizacao_id === null ? 'Pessoa física' : 'Pessoa jurídica',
                'categoria' => $inscricao->categoria?->nome,
                'cotas' => $inscricao->cotas?->map(fn (ModalidadeCota $cota): string => $cota->rotulo())->values()->all() ?? [],
                'finalizadaEm' => $inscricao->finalizada_em->format('d/m/Y H:i'),
                'homologacao' => $inscricao->homologacao?->value,
                'situacaoRotulo' => $inscricao->homologacao?->rotulo() ?? 'Sem decisão',
                'motivo' => $inscricao->homologacao_motivo,
                'decididaPor' => $inscricao->homologador?->name,
                'decididaEm' => $inscricao->homologada_em?->format('d/m/Y H:i'),
                'prazoDoRecurso' => in_array($inscricao->homologacao, [SituacaoHomologacao::NaoHomologada, SituacaoHomologacao::EmRecurso], true)
                    ? $inscricao->prazoDoRecurso()?->format('d/m/Y')
                    : null,
            ],
            'periodo' => [
                'inicio' => $chamada?->homologacao_inicio?->format('d/m/Y'),
                'fim' => $chamada?->homologacao_fim?->format('d/m/Y'),
            ],
            'podeConferir' => $podeConferir,
            'aviso' => $this->aviso($inscricao),
            'perfil' => RetratoDoPerfil::linhas($inscricao->perfil),
            'documentos' => $conferencia->documentos()
                ->map(fn (EditalDocumento $documento): array => [
                    'id' => (int) $documento->id,
                    'nome' => $documento->nome,
                    'etapaRotulo' => $documento->etapa->rotulo(),
                    'obrigatorio' => $documento->obrigatorio,
                    'enviado' => $this->arquivoEnviado($inscricao, $documento, $enviados->get((int) $documento->id)),
                    'ia' => $this->parecerDaIa($enviados->get((int) $documento->id)),
                    'marcacao' => $this->marcacao($marcacoes['documentos'][(int) $documento->id] ?? null),
                ])
                ->all(),
            'secoes' => PropsDaInscricao::secoes($questionario),
            // Objetos mesmo vazios: a tela lê as respostas e as marcações pelo id da pergunta.
            'respostas' => (object) $questionario->respostasSalvas(),
            'perguntasConferidas' => $conferencia->perguntas()->map(fn (EditalPergunta $pergunta): int => (int) $pergunta->id)->values()->all(),
            'marcacoesDasPerguntas' => (object) array_map(fn (array $marcacao): array => $this->marcacao($marcacao), $marcacoes['perguntas']),
            'motivoSugerido' => $conferencia->motivoSugerido(),
            'podeHomologar' => $podeConferir && $conferencia->tudoOk(),
            'recurso' => $this->recurso($inscricao, $enviados),
            'podeJulgar' => $inscricao->homologacao === SituacaoHomologacao::EmRecurso,
        ]);
    }

    /**
     * Marca o documento ou a pergunta como ok ou com problema (uma linha por item: marcar de novo atualiza). A situação,
     * a decisão e o período valem na linha travada da inscrição, dentro da transação. A tela atualiza a marcação, sem
     * mensagem de sucesso.
     */
    public function marcar(MarcarItemRequest $request, Inscricao $inscricao): RedirectResponse
    {
        $erro = DB::transaction(function () use ($request, $inscricao): ?string {
            Travas::edital($inscricao->edital);
            $travada = Travas::inscricao($inscricao);
            $recusa = $this->recusaDaConferencia($travada);

            if ($recusa !== null) {
                return $recusa;
            }

            $item = (int) $request->integer('item');
            $documento = $request->input('tipo') === 'documento';
            $conferencia = new ConferenciaDaInscricao($travada);

            if (! ($documento ? $conferencia->temDocumento($item) : $conferencia->temPergunta($item))) {
                throw ValidationException::withMessages(['item' => self::ERRO_ITEM]);
            }

            $travada->conferencias()->updateOrCreate(
                [
                    'edital_documento_id' => $documento ? $item : null,
                    'edital_pergunta_id' => $documento ? null : $item,
                ],
                [
                    'situacao' => $request->enum('situacao', MarcacaoDoItem::class),
                    'observacao' => $request->input('observacao'),
                    'user_id' => $request->user()->id,
                ],
            );

            return null;
        });

        return $erro === null ? back() : back()->with('erro', $erro);
    }

    /**
     * Registra a decisão da homologação, com quem e quando, na linha travada da inscrição (recusada como na marcação).
     * Homologar exige todos os itens ok. Não homologar exige item com problema ou motivo escrito; sem texto, o motivo é
     * o montado dos itens com problema. As marcações automáticas (obrigatório não enviado) são gravadas antes da
     * decisão, enquanto ainda contam, para valerem depois dela.
     */
    public function decidir(DecidirHomologacaoRequest $request, Inscricao $inscricao): RedirectResponse
    {
        $decisao = $request->enum('decisao', SituacaoHomologacao::class);
        $motivo = $request->filled('motivo') ? $request->string('motivo')->toString() : null;

        $erro = DB::transaction(function () use ($request, $inscricao, $decisao, $motivo): ?string {
            Travas::edital($inscricao->edital);
            $travada = Travas::inscricao($inscricao);
            $recusa = $this->recusaDaConferencia($travada);

            if ($recusa !== null) {
                return $recusa;
            }

            $conferencia = new ConferenciaDaInscricao($travada);

            if ($decisao === SituacaoHomologacao::Homologada) {
                if (! $conferencia->tudoOk()) {
                    return self::ERRO_HOMOLOGAR_COM_PENDENCIA;
                }

                $motivo = null;
            } else {
                if ($conferencia->itensComProblema() === [] && $motivo === null) {
                    return self::ERRO_NAO_HOMOLOGAR_SEM_MOTIVO;
                }

                $conferencia->gravarProblemasAutomaticos($request->user()->id);
                $motivo ??= $conferencia->motivoSugerido();
            }

            $travada->update([
                'homologacao' => $decisao,
                'homologacao_motivo' => $motivo,
                'homologada_por' => $request->user()->id,
                'homologada_em' => now(),
            ]);

            return null;
        });

        if ($erro !== null) {
            return back()->with('erro', $erro);
        }

        return redirect()->route('retaguarda.homologacao.show', $inscricao)->with(
            'sucesso',
            $decisao === SituacaoHomologacao::Homologada ? 'Inscrição homologada.' : 'Inscrição não homologada.',
        );
    }

    /** Abre na aba o arquivo que a inscrição enviou para o documento; 404 sem envio ou sem o arquivo no disco. */
    public function arquivo(Inscricao $inscricao, EditalDocumento $documento): StreamedResponse
    {
        abort_if($inscricao->edital === null, 404);

        $enviado = $inscricao->documentos()->where('edital_documento_id', $documento->id)->first();

        abort_unless($enviado !== null && Storage::disk(Inscricao::DISCO)->exists($enviado->arquivo), 404);

        return Storage::disk(Inscricao::DISCO)->response($enviado->arquivo, $enviado->arquivo_nome);
    }

    /** @return Builder<Inscricao> */
    private function finalizadasDosEditaisPublicados(): Builder
    {
        return Inscricao::query()
            ->where('inscricoes.situacao', SituacaoInscricao::Finalizada)
            ->whereHas('edital', fn (Builder $q) => $q->publicados());
    }

    /** O número da inscrição ou parte do nome do proponente (o usuário ou a organização). */
    private function buscar(Builder $query, string $busca): void
    {
        // No Oracle a comparação de texto diferencia maiúsculas; % e _ digitados valem literalmente.
        $termo = '%'.addcslashes(mb_strtolower($busca), '\%_').'%';
        $termoDoNumero = '%'.addcslashes($busca, '\%_').'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw("inscricoes.numero LIKE ? ESCAPE '\'", [$termoDoNumero])
            ->orWhereHas('user', fn (Builder $u) => $u->whereRaw("LOWER(users.name) LIKE ? ESCAPE '\'", [$termo]))
            ->orWhereHas('organizacao', fn (Builder $o) => $o
                ->whereRaw("LOWER(organizacoes.nome_fantasia) LIKE ? ESCAPE '\'", [$termo])
                ->orWhereRaw("LOWER(organizacoes.razao_social) LIKE ? ESCAPE '\'", [$termo])));
    }

    /** Por que a inscrição travada não pode ser marcada nem decidida: não finalizada, já decidida ou fora do período. */
    private function recusaDaConferencia(Inscricao $travada): ?string
    {
        if ($travada->situacao !== SituacaoInscricao::Finalizada) {
            return self::ERRO_NAO_FINALIZADA;
        }

        if ($travada->homologacao !== null) {
            return self::ERRO_JA_DECIDIDA;
        }

        if (! $travada->naHomologacao()) {
            return self::ERRO_FORA_DO_PERIODO;
        }

        return null;
    }

    /** Nulo dentro do período ou com decisão; o aviso de sem período (sem chamada ou sem as datas) ou de fora dele. */
    private function aviso(Inscricao $inscricao): ?string
    {
        $chamada = $inscricao->chamada;

        if ($inscricao->homologacao !== null || $inscricao->naHomologacao()) {
            return null;
        }

        if ($chamada === null || ! $chamada->temPeriodoDeHomologacao()) {
            return self::AVISO_SEM_PERIODO;
        }

        return strtr(self::AVISO_FORA_DO_PERIODO, [
            ':inicio' => $chamada->homologacao_inicio->format('d/m/Y'),
            ':fim' => $chamada->homologacao_fim->format('d/m/Y'),
        ]);
    }

    /**
     * Nome e URL (rota da retaguarda) do arquivo enviado; nulo sem envio.
     *
     * @return array{nome: string, url: string}|null
     */
    private function arquivoEnviado(Inscricao $inscricao, EditalDocumento $documento, ?InscricaoDocumento $enviado): ?array
    {
        return $enviado === null ? null : [
            'nome' => $enviado->arquivo_nome,
            'url' => route('retaguarda.homologacao.documentos.arquivo', [$inscricao, $documento]),
        ];
    }

    /**
     * O parecer da conferência por IA como o proponente vê (conferindo parado vale como não conferido); nulo sem envio
     * ou com o arquivo só enviado, sem passar pela IA.
     *
     * @return array{situacao: string, rotulo: string, motivo: ?string}|null
     */
    private function parecerDaIa(?InscricaoDocumento $enviado): ?array
    {
        $situacao = $enviado?->situacaoExibida();

        if ($situacao === null || $situacao === SituacaoConferencia::Enviado) {
            return null;
        }

        return [
            'situacao' => $situacao->value,
            'rotulo' => $situacao->rotulo(),
            'motivo' => $enviado->motivo ?? ($situacao === SituacaoConferencia::NaoConferido ? InscricaoDocumentoController::NAO_CONFERIDO : null),
        ];
    }

    /**
     * A marcação para a tela, com a situação como texto.
     *
     * @param  Marcacao|null  $marcacao
     * @return array{situacao: string, observacao: ?string, automatica: bool, por: ?string, em: ?string}|null
     */
    private function marcacao(?array $marcacao): ?array
    {
        return $marcacao === null ? null : [...$marcacao, 'situacao' => $marcacao['situacao']->value];
    }

    /**
     * O recurso da inscrição, com cada arquivo novo ao lado do original que ele substituiria, na ordem do edital; nulo
     * sem recurso. Com o recurso aceito o original já foi substituído (o envio da inscrição aponta para o arquivo novo),
     * então não há original para mostrar.
     *
     * @param  Collection<int, InscricaoDocumento>  $enviados  pelo id do documento exigido
     * @return array<string, mixed>|null
     */
    private function recurso(Inscricao $inscricao, Collection $enviados): ?array
    {
        $recurso = $inscricao->recurso()->with(['julgador:id,name', 'documentos.documento:id,nome,ordem'])->first();

        if ($recurso === null) {
            return null;
        }

        return [
            'justificativa' => $recurso->justificativa,
            'enviadoEm' => $recurso->enviado_em?->format('d/m/Y H:i'),
            'decisao' => $recurso->decisao?->value,
            'decisaoRotulo' => $recurso->decisao?->rotulo(),
            'parecer' => $recurso->parecer,
            'julgadoPor' => $recurso->julgador?->name,
            'julgadoEm' => $recurso->julgado_em?->format('d/m/Y H:i'),
            'documentos' => $recurso->documentos
                ->sortBy([
                    fn (InscricaoRecursoDocumento $a, InscricaoRecursoDocumento $b): int => (int) $a->documento->ordem <=> (int) $b->documento->ordem,
                    fn (InscricaoRecursoDocumento $a, InscricaoRecursoDocumento $b): int => (int) $a->edital_documento_id <=> (int) $b->edital_documento_id,
                ])
                ->map(fn (InscricaoRecursoDocumento $novo): array => [
                    'id' => (int) $novo->edital_documento_id,
                    'nome' => $novo->documento->nome,
                    'original' => $recurso->decisao === DecisaoRecurso::Aceito
                        ? null
                        : $this->arquivoEnviado($inscricao, $novo->documento, $enviados->get((int) $novo->edital_documento_id)),
                    'novo' => [
                        'nome' => $novo->arquivo_nome,
                        'url' => route('retaguarda.homologacao.recurso.arquivo', [$inscricao, $novo->documento]),
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /** CPF mascarado (11 dígitos) ou CNPJ formatado. */
    private function documento(string $documento): string
    {
        return strlen($documento) === 11 ? Cpf::mascarar($documento) : Cnpj::formatar($documento);
    }
}
