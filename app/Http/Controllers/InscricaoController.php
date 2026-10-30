<?php

namespace App\Http\Controllers;

use App\Enums\ModalidadeCota;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Requests\SalvarInscricaoRequest;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecurso;
use App\Support\ArquivoArmazenado;
use App\Support\Inscricao\ConferenciaDaInscricao;
use App\Support\Inscricao\PassosDaInscricao;
use App\Support\Inscricao\PropsDaInscricao;
use App\Support\Inscricao\QuestionarioDaInscricao;
use App\Support\Inscricao\RegrasDaInscricao;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscrição do proponente num edital: listar as suas, criar o rascunho, abrir (com o resultado da homologação), trocar
 * as escolhas (cadastro, categoria e cotas), finalizar e reabrir.
 */
class InscricaoController extends Controller
{
    public const string ERRO_LIMITE_ATINGIDO = 'Este cadastro já atingiu o limite de inscrições deste edital.';

    public const string ERRO_REABRIR = 'Reabra a inscrição para alterar as escolhas.';

    public const string ERRO_REABRIR_COM_DECISAO = 'A inscrição já tem resultado da homologação e não pode mais ser reaberta.';

    public const string ERRO_PENDENCIAS = 'Resolva as pendências para finalizar a inscrição.';

    public const string AVISO_PERFIL_INCOMPLETO = 'Complete o seu perfil no cadastro de Pessoa Física para se inscrever.';

    public const string ERRO_PERFIL_INCOMPLETO_AO_FINALIZAR = 'Complete o seu perfil no cadastro de Pessoa Física para finalizar a inscrição.';

    /**
     * As inscrições do usuário em qualquer fase (rascunho, em homologação ou com o resultado), das mais novas para as
     * mais antigas. Sem as colunas de texto longo (CLOB no Oracle); a de edital apagado fica de fora, como no 404.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $inscricoes = Inscricao::query()
            ->where('user_id', $user->id)
            ->whereHas('edital')
            ->with(['edital:id,titulo', 'organizacao', 'categoria'])
            ->orderByDesc('id')
            ->get(['id', 'edital_id', 'organizacao_id', 'edital_categoria_id', 'numero', 'situacao', 'homologacao', 'finalizada_em'])
            ->map(function (Inscricao $inscricao) use ($user): array {
                $situacao = $inscricao->situacaoNoPortal();

                return [
                    'id' => (int) $inscricao->id,
                    'numero' => $inscricao->numero,
                    'edital' => $inscricao->edital->titulo,
                    'cadastro' => PropsDaInscricao::nomeDoCadastro($inscricao, $user),
                    'categoria' => $inscricao->categoria?->nome,
                    'situacao' => $situacao['chave'],
                    'situacaoRotulo' => $situacao['rotulo'],
                    'finalizadaEm' => $inscricao->finalizada_em?->format('d/m/Y H:i'),
                ];
            })
            ->all();

        return Inertia::render('Portal/MinhasInscricoes', ['inscricoes' => $inscricoes]);
    }

    public function store(SalvarInscricaoRequest $request, Edital $edital): RedirectResponse
    {
        if (! $request->user()->perfilCompleto()) {
            return redirect()->route('cadastro')->with('aviso', self::AVISO_PERFIL_INCOMPLETO);
        }

        $inscricao = new Inscricao([
            'edital_id' => $edital->id,
            'user_id' => $request->user()->id,
            'situacao' => SituacaoInscricao::Rascunho,
        ]);
        $inscricao->setRelation('edital', $edital);
        $this->preencher($inscricao, $request);

        if (! $inscricao->noPeriodo()) {
            return $this->inscricoesFechadas();
        }

        DB::transaction(function () use ($inscricao, $edital): void {
            $this->travarEdital($edital);
            $this->recusarSeLimiteAtingido($inscricao);
            $inscricao->save();
        });

        return redirect()->route('portal.inscricoes.show', $inscricao);
    }

    /**
     * A inscrição em passos: escolhas, seções do questionário, documentos e revisão. O passo pedido em ?passo= só abre
     * com os anteriores completos; finalizada ou fora do período abre na revisão. As seções vão com todas as perguntas
     * que valem (visíveis ou não), para a tela recalcular a visibilidade enquanto o proponente responde.
     */
    public function show(Request $request, Inscricao $inscricao): Response
    {
        Gate::authorize('gerenciar', $inscricao);

        $user = $request->user();
        $edital = $inscricao->edital;
        $inscricao->load(['organizacao', 'categoria', 'chamada']);
        $enviados = $inscricao->documentos()->get()
            ->keyBy(fn (InscricaoDocumento $enviado): int => (int) $enviado->edital_documento_id);
        $validacaoLigada = ConfiguracaoIa::validacaoLigada();
        $questionario = new QuestionarioDaInscricao($inscricao);
        $passos = PassosDaInscricao::para($inscricao, $questionario);
        $podeEditar = $inscricao->situacao === SituacaoInscricao::Rascunho && $inscricao->noPeriodo();
        $passoPedido = $request->query('passo');

        return Inertia::render('Portal/InscricaoPassos', [
            'edital' => PropsDaInscricao::edital($edital),
            'inscricao' => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'situacao' => $inscricao->situacao->value,
                'situacaoRotulo' => $inscricao->situacao->rotulo(),
                'cadastro' => PropsDaInscricao::nomeDoCadastro($inscricao, $user),
                'cadastroChave' => PropsDaInscricao::chaveDoCadastro($inscricao->organizacao_id === null ? null : (int) $inscricao->organizacao_id),
                'categoriaId' => $inscricao->edital_categoria_id === null ? null : (int) $inscricao->edital_categoria_id,
                'categoria' => $inscricao->categoria?->nome,
                'cotas' => $inscricao->cotas?->map(fn (ModalidadeCota $cota): string => $cota->value)->values()->all() ?? [],
                'fimDoPeriodo' => $inscricao->chamada?->fim?->format('d/m/Y'),
                'finalizadaEm' => $inscricao->finalizada_em?->format('d/m/Y H:i'),
            ],
            'podeEditar' => $podeEditar,
            'podeReabrir' => $inscricao->situacao === SituacaoInscricao::Finalizada && $inscricao->homologacao === null && $inscricao->noPeriodo(),
            'resultado' => $this->resultadoDaHomologacao($inscricao),
            'pendencias' => $inscricao->pendenciasParaFinalizar(questionario: $questionario),
            'documentos' => $inscricao->documentosExigidos()
                ->map(fn (EditalDocumento $documento): array => [
                    'id' => (int) $documento->id,
                    'nome' => $documento->nome,
                    'orientacao' => $documento->orientacao,
                    'etapa' => $documento->etapa->value,
                    'etapaRotulo' => $documento->etapa->rotulo(),
                    'obrigatorio' => $documento->obrigatorio,
                    'modeloUrl' => filled($documento->modelo) ? route('editais.documentos.modelo', [$edital, $documento]) : null,
                    'modeloNome' => $documento->modelo_nome,
                    'checagem' => $documento->checagemNaInscricao($validacaoLigada)->value,
                    'enviado' => $this->arquivoEnviado($inscricao, $documento, $enviados->get((int) $documento->id)),
                ])
                ->all(),
            'passos' => $passos,
            'passoAtual' => $podeEditar
                ? PassosDaInscricao::atual($passos, is_string($passoPedido) ? $passoPedido : null)
                : PassosDaInscricao::REVISAO,
            'secoes' => PropsDaInscricao::secoes($questionario),
            // Objeto mesmo vazio: a tela lê as respostas pelo id da pergunta.
            'respostas' => (object) $questionario->respostasSalvas(),
            'perfilIncompleto' => ! $user->perfilCompleto(),
            ...PropsDaInscricao::opcoes($user, $edital),
        ]);
    }

    /**
     * Situação da conferência de cada documento enviado e o que ainda impede de finalizar, para a tela consultar
     * enquanto algum está conferindo. Sem o detalhe técnico da falha, que fica só no banco; conferindo parado aparece
     * como não conferido.
     */
    public function situacao(Inscricao $inscricao): JsonResponse
    {
        Gate::authorize('gerenciar', $inscricao);

        $pendencias = $inscricao->pendenciasParaFinalizar();

        return response()->json([
            'documentos' => $inscricao->documentos()->orderBy('id')->get()
                ->map(fn (InscricaoDocumento $documento): array => [
                    'id' => (int) $documento->edital_documento_id,
                    ...$this->situacaoParaATela($documento),
                ])
                ->all(),
            'podeFinalizar' => $pendencias === [],
            'pendencias' => $pendencias,
        ]);
    }

    public function update(SalvarInscricaoRequest $request, Inscricao $inscricao): RedirectResponse
    {
        if (! $inscricao->noPeriodo()) {
            return $this->inscricoesFechadas();
        }

        // Saída antes de travar; a situação que vale é a relida com a linha travada, dentro da transação.
        if ($inscricao->situacao !== SituacaoInscricao::Rascunho) {
            return $this->pedirParaReabrir($inscricao);
        }

        [$arquivosDescartados, $travada, $questionario] = DB::transaction(function () use ($inscricao, $request): array {
            $this->travarEdital($inscricao->edital);
            $travada = $this->travarInscricao($inscricao);

            if ($travada->situacao !== SituacaoInscricao::Rascunho) {
                throw new HttpResponseException($this->pedirParaReabrir($inscricao));
            }

            $this->preencher($travada, $request);

            // A nova categoria pode levar a inscrição para uma chamada que já fechou.
            if (! $travada->noPeriodo()) {
                throw new HttpResponseException($this->inscricoesFechadas());
            }

            if ($travada->isDirty(['documento_cadastro', 'edital_chamada_id'])) {
                $this->recusarSeLimiteAtingido($travada);
            }
            $travada->save();
            $questionario = new QuestionarioDaInscricao($travada);
            $questionario->descartarRespostasQueNaoValem();

            return [$this->descartarDocumentosForaDaLista($travada), $travada, $questionario];
        });

        ArquivoArmazenado::apagarTodos($arquivosDescartados);
        $passos = PassosDaInscricao::para($travada, $questionario);

        return redirect()
            ->route('portal.inscricoes.show', [$inscricao, 'passo' => PassosDaInscricao::seguinte($passos, PassosDaInscricao::ESCOLHAS)])
            ->with('sucesso', 'Escolhas salvas.');
    }

    /**
     * Finaliza o rascunho no período, com o perfil do dono completo e sem pendências. O número (ano e id com 6 dígitos)
     * nasce na primeira vez e é mantido quando a inscrição é reaberta e finalizada de novo; o retrato do perfil do dono
     * é refeito a cada finalização. Finalizar o que já está finalizado não muda nada.
     */
    public function finalizar(Inscricao $inscricao): RedirectResponse
    {
        Gate::authorize('gerenciar', $inscricao);

        return DB::transaction(function () use ($inscricao): RedirectResponse {
            // O edital antes da inscrição: espera quem troca ou importa o questionário (que trava o edital) terminar.
            $this->travarEdital($inscricao->edital);
            $travada = $this->travarInscricao($inscricao);

            if (! $travada->noPeriodo()) {
                return $this->inscricoesFechadas();
            }

            if ($travada->situacao === SituacaoInscricao::Finalizada) {
                return redirect()->route('portal.inscricoes.show', $inscricao);
            }

            $dono = $travada->user;

            if (! $dono->perfilCompleto()) {
                return redirect()->route('portal.inscricoes.show', $inscricao)->with('erro', self::ERRO_PERFIL_INCOMPLETO_AO_FINALIZAR);
            }

            if ($travada->pendenciasParaFinalizar() !== []) {
                return redirect()->route('portal.inscricoes.show', $inscricao)->with('erro', self::ERRO_PENDENCIAS);
            }

            $agora = now();
            $travada->update([
                'perfil' => $dono->pessoaFisica->retratoDoPerfil(),
                'situacao' => SituacaoInscricao::Finalizada,
                'finalizada_em' => $agora,
                'numero' => $travada->numero ?? sprintf('%d%06d', $agora->year, (int) $travada->id),
            ]);

            return redirect()->route('portal.inscricoes.show', $inscricao)->with('sucesso', 'Inscrição finalizada.');
        });
    }

    /**
     * Volta a inscrição finalizada a rascunho, no período e sem resultado da homologação; o número e os documentos
     * ficam e as marcações da conferência são apagadas (a inscrição vai mudar e será conferida de novo).
     */
    public function reabrir(Inscricao $inscricao): RedirectResponse
    {
        Gate::authorize('gerenciar', $inscricao);

        return DB::transaction(function () use ($inscricao): RedirectResponse {
            // O edital antes da inscrição, como quem marca ou decide a homologação.
            $this->travarEdital($inscricao->edital);
            $travada = $this->travarInscricao($inscricao);

            if ($travada->homologacao !== null) {
                return redirect()->route('portal.inscricoes.show', $inscricao)->with('erro', self::ERRO_REABRIR_COM_DECISAO);
            }

            if (! $travada->noPeriodo()) {
                return $this->inscricoesFechadas();
            }

            if ($travada->situacao === SituacaoInscricao::Finalizada) {
                $travada->update(['situacao' => SituacaoInscricao::Rascunho, 'finalizada_em' => null]);
                $travada->conferencias()->delete();

                return redirect()->route('portal.inscricoes.show', $inscricao)->with('sucesso', 'Inscrição reaberta.');
            }

            return redirect()->route('portal.inscricoes.show', $inscricao);
        });
    }

    /**
     * O resultado da homologação que o proponente vê; nulo no rascunho. O motivo e os itens com problema só na não
     * homologada (com ou sem recurso), o prazo do recurso só enquanto ela aguarda recurso e o parecer depois do
     * julgamento do recurso.
     *
     * @return array{situacao: string, rotulo: string, motivo: ?string, itens: list<array{nome: string, observacao: ?string}>, prazoDoRecurso: ?string, podeRecorrer: bool, parecer: ?string}|null
     */
    private function resultadoDaHomologacao(Inscricao $inscricao): ?array
    {
        if ($inscricao->situacao === SituacaoInscricao::Rascunho) {
            return null;
        }

        $situacao = $inscricao->situacaoNoPortal();
        $naoHomologada = in_array($inscricao->homologacao, [SituacaoHomologacao::NaoHomologada, SituacaoHomologacao::EmRecurso, SituacaoHomologacao::RecursoNegado], true);
        /** @var InscricaoRecurso|null $recurso */
        $recurso = $inscricao->recurso()->first();

        return [
            'situacao' => $situacao['chave'],
            'rotulo' => $situacao['rotulo'],
            'motivo' => $naoHomologada ? $inscricao->homologacao_motivo : null,
            'itens' => $naoHomologada
                ? array_map(
                    fn (array $item): array => ['nome' => $item['nome'], 'observacao' => $item['observacao']],
                    (new ConferenciaDaInscricao($inscricao))->itensComProblema(),
                )
                : [],
            'prazoDoRecurso' => $inscricao->homologacao === SituacaoHomologacao::NaoHomologada
                ? $inscricao->prazoDoRecurso()?->format('d/m/Y')
                : null,
            'podeRecorrer' => $inscricao->podeRecorrer(),
            'parecer' => $recurso?->julgado_em !== null ? $recurso->parecer : null,
        ];
    }

    /**
     * O arquivo enviado para o documento exigido, com a situação da conferência; nulo se ainda não foi enviado.
     *
     * @return array{nome: string, url: string, situacao: string, motivo: ?string}|null
     */
    private function arquivoEnviado(Inscricao $inscricao, EditalDocumento $exigido, ?InscricaoDocumento $enviado): ?array
    {
        if ($enviado === null) {
            return null;
        }

        return [
            'nome' => $enviado->arquivo_nome,
            'url' => route('portal.inscricoes.documentos.arquivo', [$inscricao, $exigido]),
            ...$this->situacaoParaATela($enviado),
        ];
    }

    /**
     * A situação que o proponente vê (conferindo parado vale como não conferido) e o motivo: o da conferência ou, sem
     * ele, a mensagem padrão do não conferido.
     *
     * @return array{situacao: string, motivo: ?string}
     */
    private function situacaoParaATela(InscricaoDocumento $documento): array
    {
        $situacao = $documento->situacaoExibida();

        return [
            'situacao' => $situacao->value,
            'motivo' => $documento->motivo ?? ($situacao === SituacaoConferencia::NaoConferido ? InscricaoDocumentoController::NAO_CONFERIDO : null),
        ];
    }

    /** Põe na inscrição o que foi escolhido: cadastro, categoria, cotas e a chamada que a categoria define. */
    private function preencher(Inscricao $inscricao, SalvarInscricaoRequest $request): void
    {
        $cadastro = $request->cadastroEscolhido();
        $categoria = $request->categoriaEscolhida();
        $chamada = RegrasDaInscricao::chamada($inscricao->edital, $categoria);

        $inscricao->fill([
            'edital_chamada_id' => $chamada?->id,
            'organizacao_id' => $cadastro['id'],
            'documento_cadastro' => $cadastro['documento'],
            'natureza' => $cadastro['natureza'],
            'edital_categoria_id' => $categoria?->id,
            'cotas' => $request->cotasEscolhidas(),
        ]);
        $inscricao->setRelation('chamada', $chamada);
    }

    private function inscricoesFechadas(): RedirectResponse
    {
        return redirect()->route('portal.editais')->with('aviso', PortalController::AVISO_INSCRICOES_FECHADAS);
    }

    private function pedirParaReabrir(Inscricao $inscricao): RedirectResponse
    {
        return redirect()->route('portal.inscricoes.show', $inscricao)->with('erro', self::ERRO_REABRIR);
    }

    /**
     * Duas gravações ao mesmo tempo no edital esperam uma pela outra, para a segunda ver a primeira no limite.
     * Ordem das travas, sempre: o edital e depois a inscrição (quem finaliza ou anexa documento segue a mesma).
     */
    private function travarEdital(Edital $edital): void
    {
        Edital::query()->whereKey($edital->id)->lockForUpdate()->value('id');
    }

    /**
     * Relê a inscrição travando a linha: quem finaliza, reabre ou anexa documento ao mesmo tempo espera aqui, e
     * a situação e as escolhas que valem são as da linha travada, não as da cópia lida antes da transação.
     */
    private function travarInscricao(Inscricao $inscricao): Inscricao
    {
        $travada = Inscricao::query()->whereKey($inscricao->id)->lockForUpdate()->firstOrFail();
        $travada->setRelation('edital', $inscricao->edital);

        return $travada;
    }

    private function recusarSeLimiteAtingido(Inscricao $inscricao): void
    {
        $ignorar = $inscricao->exists ? (int) $inscricao->id : null;

        if (RegrasDaInscricao::limiteAtingido($inscricao->edital, $inscricao->chamada, $inscricao->documento_cadastro, $ignorar)) {
            throw ValidationException::withMessages(['cadastro' => self::ERRO_LIMITE_ATINGIDO]);
        }
    }

    /**
     * Apaga os documentos enviados que a inscrição deixou de exigir. Devolve os arquivos deles para apagar do
     * disco depois do commit; um processo de conferência em andamento não os regrava (a linha não existe mais).
     *
     * @return list<array{string, ?string}>
     */
    private function descartarDocumentosForaDaLista(Inscricao $inscricao): array
    {
        $exigidos = $inscricao->documentosExigidos()->map(fn (EditalDocumento $documento): int => (int) $documento->id)->all();

        $descartados = $inscricao->documentos()->get()
            ->reject(fn (InscricaoDocumento $documento): bool => in_array((int) $documento->edital_documento_id, $exigidos, true));

        if ($descartados->isEmpty()) {
            return [];
        }

        $inscricao->documentos()->whereKey($descartados->modelKeys())->delete();

        return $descartados->map(fn (InscricaoDocumento $documento): array => [Inscricao::DISCO, $documento->arquivo])->values()->all();
    }
}
