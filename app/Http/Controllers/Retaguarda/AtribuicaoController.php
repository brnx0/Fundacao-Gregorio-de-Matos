<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\AtribuirRequest;
use App\Http\Requests\Retaguarda\TransferirRequest;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use App\Support\Inscricao\Travas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atribuição manual de uma inscrição homologada a um avaliador e transferência das não concluídas de um avaliador para
 * outro. Tudo com o edital travado (a distribuição trava o mesmo edital) e depois a inscrição; o estado é relido nas
 * linhas travadas. Transferir apaga as notas e a observação já lançadas; avaliação concluída não muda de dono.
 */
class AtribuicaoController extends Controller
{
    public const string ERRO_DESTINO = 'Escolha um avaliador ativo deste edital.';

    public const string ERRO_ORIGEM = 'Escolha um avaliador deste edital.';

    public const string ERRO_JA_CONCLUIDA = 'Esta avaliação já foi concluída e não pode ser transferida.';

    /**
     * Dá a inscrição ao avaliador escolhido: cria a avaliação ou a transfere (se não estiver concluída). Com pendência
     * da distribuição, nada muda.
     */
    public function atribuir(AtribuirRequest $request, Edital $edital, Inscricao $inscricao): RedirectResponse
    {
        abort_unless($edital->publicado && (int) $inscricao->edital_id === (int) $edital->id && $this->homologada($inscricao), 404);

        $destino = $request->avaliadorId();
        $gestor = (int) $request->user()->id;

        [$tipo, $mensagem] = DB::transaction(function () use ($edital, $inscricao, $destino, $gestor): array {
            Travas::edital($edital);
            $travada = Travas::inscricao($inscricao);
            abort_unless($this->publicado($edital) && $this->homologada($travada), 404);

            // Como na distribuição: com pendência (critério sem pontuação máxima, por exemplo) ninguém recebe inscrição.
            $pendencias = $edital->pendenciasDaDistribuicao();
            if ($pendencias !== []) {
                return ['erro', $pendencias[0]];
            }

            $nome = $this->nomeDoDestino($edital, $destino, 'avaliador_id');
            $avaliacao = InscricaoAvaliacao::query()->where('inscricao_id', $travada->id)->with('avaliador:id,nome')->first();

            if ($avaliacao === null) {
                InscricaoAvaliacao::query()->create([
                    'inscricao_id' => $travada->id,
                    'avaliador_id' => $destino,
                    'atribuida_por' => $gestor,
                    'atribuida_em' => now(),
                ]);

                return ['sucesso', "Inscrição {$travada->numero} atribuída a {$nome}."];
            }

            if ($avaliacao->concluida_em !== null) {
                return ['erro', self::ERRO_JA_CONCLUIDA];
            }

            if ((int) $avaliacao->avaliador_id === $destino) {
                return ['aviso', "A inscrição {$travada->numero} já está com {$nome}."];
            }

            $origem = $avaliacao->avaliador->nome;
            $avaliacao->notas()->delete();
            $avaliacao->update([
                'avaliador_id' => $destino,
                'atribuida_por' => $gestor,
                'atribuida_em' => now(),
                'observacao' => null,
            ]);

            return ['sucesso', "Inscrição {$travada->numero} transferida de {$origem} para {$nome}."];
        });

        return back()->with($tipo, $mensagem);
    }

    /**
     * Passa as inscrições não concluídas do edital de um avaliador (ativo ou não: o caso comum é o desativado) para um
     * avaliador ativo do edital. As concluídas ficam com quem as concluiu.
     */
    public function transferirTodas(TransferirRequest $request, Edital $edital): RedirectResponse
    {
        abort_unless($edital->publicado, 404);

        $de = $request->de();
        $para = $request->para();
        $gestor = (int) $request->user()->id;

        [$tipo, $mensagem] = DB::transaction(function () use ($edital, $de, $para, $gestor): array {
            Travas::edital($edital);
            abort_unless($this->publicado($edital), 404);

            $vinculoDeOrigem = $edital->avaliadores()->where('avaliador_id', $de)->with('avaliador:id,nome')->first();
            if ($vinculoDeOrigem === null) {
                throw ValidationException::withMessages(['de' => self::ERRO_ORIGEM]);
            }
            $origem = $vinculoDeOrigem->avaliador->nome;
            $nome = $this->nomeDoDestino($edital, $para, 'para');

            // As inscrições também travadas (na ordem edital → inscrição): quem salva uma nota espera a transferência.
            $abertas = fn (): Builder => InscricaoAvaliacao::query()
                ->where('avaliador_id', $de)
                ->whereNull('concluida_em')
                ->whereHas('inscricao', fn (Builder $q) => $q->where('edital_id', $edital->id));

            Inscricao::query()->whereIn('id', $abertas()->select('inscricao_id'))->orderBy('id')->lockForUpdate()->pluck('id');
            $total = $abertas()->count();

            if ($total === 0) {
                return ['aviso', "Não há inscrições não concluídas com {$origem}."];
            }

            InscricaoAvaliacaoNota::query()->whereIn('inscricao_avaliacao_id', $abertas()->select('id'))->delete();
            $abertas()->update([
                'avaliador_id' => $para,
                'atribuida_por' => $gestor,
                'atribuida_em' => now(),
                'observacao' => null,
            ]);

            $quantas = $total === 1 ? '1 inscrição transferida' : "{$total} inscrições transferidas";

            return ['sucesso', "{$quantas} de {$origem} para {$nome}."];
        });

        return back()->with($tipo, $mensagem);
    }

    /** Finalizada e homologada: só essas são avaliadas. */
    private function homologada(Inscricao $inscricao): bool
    {
        return $inscricao->situacao === SituacaoInscricao::Finalizada && $inscricao->homologacao === SituacaoHomologacao::Homologada;
    }

    /** Relido com o edital já travado: pode ter sido despublicado depois de a requisição chegar. */
    private function publicado(Edital $edital): bool
    {
        return (bool) Edital::query()->whereKey($edital->id)->value('publicado');
    }

    /**
     * O nome do avaliador de destino, que precisa estar no edital e ativo; senão, erro de validação no campo dado.
     *
     * @throws ValidationException
     */
    private function nomeDoDestino(Edital $edital, int $avaliador, string $campo): string
    {
        $vinculo = $edital->avaliadores()
            ->where('avaliador_id', $avaliador)
            ->whereHas('avaliador', fn (Builder $q) => $q->where('ativo', true))
            ->with('avaliador:id,nome')
            ->first();

        if (! $vinculo instanceof EditalAvaliador) {
            throw ValidationException::withMessages([$campo => self::ERRO_DESTINO]);
        }

        return $vinculo->avaliador->nome;
    }
}
