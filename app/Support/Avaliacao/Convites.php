<?php

namespace App\Support\Avaliacao;

use App\Enums\SituacaoConvite;
use App\Models\Avaliador;
use App\Models\EditalAvaliador;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Dispara o convite por e-mail do avaliador para os editais em que ele foi escolhido. */
final class Convites
{
    /**
     * Marca os vínculos como na fila, com uma execução nova, e lança o envio de um e-mail só. Cria o link de acesso se
     * o avaliador ainda não tem. Se o envio nem começar, os vínculos já ficam como falhos (com "Reenviar").
     *
     * @param  Collection<int, EditalAvaliador>  $vinculos  Vínculos do avaliador a convidar.
     */
    public static function enviar(Avaliador $avaliador, Collection $vinculos): void
    {
        if ($vinculos->isEmpty()) {
            return;
        }

        if ($avaliador->linkDeAcesso() === null) {
            $avaliador->novoAcesso();
        }

        $execucao = (string) Str::uuid();
        $ids = $vinculos->map(fn (EditalAvaliador $vinculo) => $vinculo->getKey())->all();

        EditalAvaliador::query()->whereKey($ids)->update([
            'convite_situacao' => SituacaoConvite::NaFila->value,
            'convite_erro' => null,
            'convite_enviado_em' => null,
            'convite_iniciado_em' => now(),
            'convite_execucao' => $execucao,
        ]);

        try {
            app(LancadorDeConvite::class)->lancar($avaliador, $execucao);
        } catch (Throwable $erro) {
            Log::error('Convite do avaliador: não foi possível iniciar o envio.', [
                'avaliador' => (int) $avaliador->getKey(),
                'excecao' => $erro::class,
            ]);

            EditalAvaliador::query()->whereKey($ids)->where('convite_execucao', $execucao)->update([
                'convite_situacao' => SituacaoConvite::Falhou->value,
                'convite_erro' => EditalAvaliador::ERRO_AO_INICIAR,
            ]);
        }
    }
}
