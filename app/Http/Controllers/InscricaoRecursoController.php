<?php

namespace App\Http\Controllers;

use App\Enums\SituacaoHomologacao;
use App\Http\Requests\EnviarRecursoRequest;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Support\ArquivoArmazenado;
use App\Support\Inscricao\ConferenciaDaInscricao;
use App\Support\Inscricao\Travas;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Recurso do proponente contra a não homologação: um por inscrição, até o fim do prazo (Inscricao::prazoDoRecurso),
 * com a justificativa e/ou arquivos novos para os documentos marcados com problema. Os originais ficam; o gestor
 * troca pelos novos se aceitar o recurso. Só do dono (InscricaoPolicy).
 */
class InscricaoRecursoController extends Controller
{
    public const string ERRO_FORA_DO_PRAZO = 'O prazo para entrar com recurso terminou.';

    public const string ERRO_SEM_RECURSO = 'Esta inscrição não aceita recurso.';

    public function create(Inscricao $inscricao): Response|RedirectResponse
    {
        Gate::authorize('gerenciar', $inscricao);

        if (($recusa = $this->recusar($inscricao)) !== null) {
            return $recusa;
        }

        $edital = $inscricao->edital;
        $conferencia = new ConferenciaDaInscricao($inscricao);
        $itens = $conferencia->itensComProblema();
        $observacoes = collect($itens)->where('tipo', 'documento')->pluck('observacao', 'id');

        return Inertia::render('Portal/RecursoDaInscricao', [
            'edital' => ['titulo' => $edital->titulo, 'slug' => $edital->slug],
            'inscricao' => ['id' => (int) $inscricao->id, 'numero' => $inscricao->numero],
            // Quem pode recorrer tem decisão e, portanto, prazo.
            'prazo' => $inscricao->prazoDoRecurso()?->format('d/m/Y'),
            'motivo' => $inscricao->homologacao_motivo,
            'itens' => array_map(fn (array $item): array => ['nome' => $item['nome'], 'observacao' => $item['observacao']], $itens),
            'documentos' => $conferencia->documentosComProblema()
                ->map(fn (EditalDocumento $documento): array => [
                    'id' => (int) $documento->id,
                    'nome' => $documento->nome,
                    'orientacao' => $documento->orientacao,
                    'observacao' => $observacoes->get((int) $documento->id),
                ])
                ->all(),
        ]);
    }

    /**
     * Guarda os arquivos antes da transação (fora da trava) e grava o recurso com a inscrição travada, conferindo de
     * novo se ela ainda aceita recurso; qualquer falha apaga os arquivos guardados.
     */
    public function store(EnviarRecursoRequest $request, Inscricao $inscricao): RedirectResponse
    {
        if (($recusa = $this->recusar($inscricao)) !== null) {
            return $recusa;
        }

        $guardados = [];

        try {
            foreach ($request->arquivos() as $documentoId => $arquivo) {
                $guardados[(int) $documentoId] = [
                    'arquivo' => ArquivoArmazenado::guardar($arquivo, Inscricao::DISCO, Inscricao::PASTA."/{$inscricao->id}/recurso"),
                    'arquivo_nome' => mb_strcut($arquivo->getClientOriginalName(), 0, 255, 'UTF-8'),
                ];
            }

            DB::transaction(function () use ($inscricao, $request, $guardados): void {
                Travas::edital($inscricao->edital);
                $travada = Travas::inscricao($inscricao);

                if (($recusa = $this->recusar($travada)) !== null) {
                    throw new HttpResponseException($recusa);
                }

                $recurso = $travada->recurso()->create([
                    'justificativa' => $request->validated('justificativa'),
                    'enviado_em' => now(),
                ]);

                foreach ($guardados as $documentoId => $guardado) {
                    $recurso->documentos()->create(['edital_documento_id' => $documentoId, ...$guardado]);
                }

                $travada->update(['homologacao' => SituacaoHomologacao::EmRecurso]);
            });
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagarTodos(array_map(fn (array $guardado): array => [Inscricao::DISCO, $guardado['arquivo']], array_values($guardados)));

            throw $erro;
        }

        return redirect()->route('portal.inscricoes.show', $inscricao)->with('sucesso', 'Recurso enviado.');
    }

    /**
     * Volta para a inscrição quando ela não aceita recurso: não homologada e sem recurso só fica de fora por ter
     * passado o prazo; os demais casos não aceitam recurso.
     */
    private function recusar(Inscricao $inscricao): ?RedirectResponse
    {
        if ($inscricao->podeRecorrer()) {
            return null;
        }

        $foraDoPrazo = $inscricao->homologacao === SituacaoHomologacao::NaoHomologada && ! $inscricao->recurso()->exists();

        return redirect()->route('portal.inscricoes.show', $inscricao)
            ->with('aviso', $foraDoPrazo ? self::ERRO_FORA_DO_PRAZO : self::ERRO_SEM_RECURSO);
    }
}
