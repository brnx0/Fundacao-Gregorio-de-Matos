<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\DecisaoRecurso;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoHomologacao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\JulgarRecursoRequest;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoDocumento;
use App\Models\InscricaoRecurso;
use App\Support\ArquivoArmazenado;
use App\Support\Inscricao\Travas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Recurso contra a não homologação, na retaguarda: os arquivos novos que o proponente enviou e o julgamento. */
class RecursoDaHomologacaoController extends Controller
{
    public const string ERRO_SEM_RECURSO = 'Esta inscrição não tem recurso aguardando julgamento.';

    /**
     * Julga o recurso, com o parecer, quem e quando, na linha travada da inscrição; vale depois do período de
     * homologação. Aceito: os arquivos do recurso passam a ser os da inscrição (só enviados, sem conferência por IA) e
     * ela fica homologada; os originais trocados são apagados depois do commit. Negado: os documentos ficam como
     * estavam. A decisão original (quem, quando e o motivo) fica registrada.
     */
    public function julgar(JulgarRecursoRequest $request, Inscricao $inscricao): RedirectResponse
    {
        $decisao = $request->enum('decisao', DecisaoRecurso::class);
        /** @var list<array{string, ?string}> $trocados */
        $trocados = [];

        $erro = DB::transaction(function () use ($request, $inscricao, $decisao, &$trocados): ?string {
            Travas::edital($inscricao->edital);
            $travada = Travas::inscricao($inscricao);
            $recurso = InscricaoRecurso::query()->where('inscricao_id', $travada->id)->lockForUpdate()->first();

            if ($travada->homologacao !== SituacaoHomologacao::EmRecurso || $recurso === null || $recurso->decisao !== null) {
                return self::ERRO_SEM_RECURSO;
            }

            if ($decisao === DecisaoRecurso::Aceito) {
                $trocados = $this->trocarOsArquivos($travada, $recurso);
            }

            $recurso->update([
                'decisao' => $decisao,
                'parecer' => $request->string('parecer')->toString(),
                'julgado_por' => $request->user()->id,
                'julgado_em' => now(),
            ]);
            $travada->update([
                'homologacao' => $decisao === DecisaoRecurso::Aceito ? SituacaoHomologacao::Homologada : SituacaoHomologacao::RecursoNegado,
            ]);

            return null;
        });

        if ($erro !== null) {
            return back()->with('erro', $erro);
        }

        ArquivoArmazenado::apagarTodos($trocados);

        return redirect()->route('retaguarda.homologacao.show', $inscricao)->with(
            'sucesso',
            $decisao === DecisaoRecurso::Aceito ? 'Recurso aceito: inscrição homologada.' : 'Recurso negado.',
        );
    }

    /** Abre na aba o arquivo novo enviado no recurso para o documento; 404 sem ele ou sem o arquivo no disco. */
    public function arquivo(Inscricao $inscricao, EditalDocumento $documento): StreamedResponse
    {
        $novo = $inscricao->recurso?->documentos()->where('edital_documento_id', $documento->id)->first();

        abort_unless($novo !== null && Storage::disk(Inscricao::DISCO)->exists($novo->arquivo), 404);

        return Storage::disk(Inscricao::DISCO)->response($novo->arquivo, $novo->arquivo_nome);
    }

    /**
     * Cada arquivo do recurso vira o envio da inscrição para o documento (criado quando não tinha sido enviado), só
     * enviado e sem o resultado de conferência anterior. Devolve os originais trocados, para apagar depois do commit.
     *
     * @return list<array{string, ?string}> Pares [disco, caminho].
     */
    private function trocarOsArquivos(Inscricao $travada, InscricaoRecurso $recurso): array
    {
        $trocados = [];

        foreach ($recurso->documentos()->get() as $novo) {
            $enviado = InscricaoDocumento::query()->firstOrNew([
                'inscricao_id' => $travada->id,
                'edital_documento_id' => $novo->edital_documento_id,
            ]);

            if ($enviado->exists && $enviado->arquivo !== $novo->arquivo) {
                $trocados[] = [Inscricao::DISCO, $enviado->arquivo];
            }

            $enviado->fill([
                'arquivo' => $novo->arquivo,
                'arquivo_nome' => $novo->arquivo_nome,
                'situacao' => SituacaoConferencia::Enviado,
                'motivo' => null,
                'erro' => null,
                'provedor' => null,
                'modelo' => null,
                'execucao' => null,
                'iniciada_em' => null,
                'conferido_em' => null,
            ])->save();
        }

        return $trocados;
    }
}
