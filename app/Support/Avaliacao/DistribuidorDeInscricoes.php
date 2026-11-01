<?php

namespace App\Support\Avaliacao;

use App\Enums\ModoDistribuicao;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCategoria;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use Illuminate\Database\Eloquent\Builder;
use Random\Randomizer;

/**
 * Monta o plano da distribuição igual ou por categoria. Entram as inscrições homologadas do edital ainda sem avaliador,
 * embaralhadas; cada uma vai para o destino (avaliador ativo do edital, ou da categoria dela) com menos inscrições no
 * edital naquele momento (as que ele já tem mais as que o plano já deu), com o empate sorteado. Só lê: quem grava é o
 * controller, com o edital travado.
 */
class DistribuidorDeInscricoes
{
    public const string SEM_CATEGORIA = 'Sem categoria';

    private readonly Randomizer $sorteio;

    public function __construct(?Randomizer $sorteio = null)
    {
        $this->sorteio = $sorteio ?? new Randomizer;
    }

    /**
     * Os modos que o edital aceita: por categoria só quando ele tem categorias.
     *
     * @return list<ModoDistribuicao>
     */
    public static function modos(Edital $edital): array
    {
        return $edital->categorias()->exists() ? ModoDistribuicao::cases() : [ModoDistribuicao::Igual];
    }

    public function plano(Edital $edital, ModoDistribuicao $modo): PlanoDeDistribuicao
    {
        $destinos = $this->destinos($edital);
        $carga = array_fill_keys(array_keys($destinos), 0);
        foreach ($this->cargasAtuais($edital) as $avaliador => $quantidade) {
            if (isset($carga[$avaliador])) {
                $carga[$avaliador] = $quantidade;
            }
        }

        $categorias = $edital->categorias()->pluck('nome', 'id')->mapWithKeys(fn (string $nome, $id): array => [(int) $id => $nome])->all();
        $atribuicoes = [];
        $porAvaliador = [];
        $sobras = [];

        foreach ($this->candidatas($edital) as $inscricao) {
            $categoria = $inscricao->edital_categoria_id === null ? null : (int) $inscricao->edital_categoria_id;
            $possiveis = $modo === ModoDistribuicao::Igual
                ? array_keys($destinos)
                : array_keys(array_filter($destinos, fn (array $suas): bool => $categoria !== null && in_array($categoria, $suas, true)));

            if ($possiveis === []) {
                $rotulo = $categoria === null ? self::SEM_CATEGORIA : ($categorias[$categoria] ?? self::SEM_CATEGORIA);
                $sobras[$rotulo] = ($sobras[$rotulo] ?? 0) + 1;

                continue;
            }

            $escolhido = $this->menosCarregado($possiveis, $carga);
            $atribuicoes[(int) $inscricao->id] = $escolhido;
            $porAvaliador[$escolhido] = ($porAvaliador[$escolhido] ?? 0) + 1;
            $carga[$escolhido]++;
        }

        return new PlanoDeDistribuicao($atribuicoes, $porAvaliador, $this->naOrdemDasCategorias($sobras, $categorias));
    }

    /**
     * As homologadas do edital sem avaliador, embaralhadas (a partir da ordem do id, para o sorteio ser reproduzível).
     *
     * @return list<Inscricao>
     */
    private function candidatas(Edital $edital): array
    {
        $inscricoes = Inscricao::query()
            ->where('edital_id', $edital->id)
            ->where('situacao', SituacaoInscricao::Finalizada)
            ->where('homologacao', SituacaoHomologacao::Homologada)
            ->whereDoesntHave('avaliacao')
            ->orderBy('id')
            ->get(['id', 'edital_categoria_id'])
            ->all();

        return $this->sorteio->shuffleArray($inscricoes);
    }

    /**
     * Os avaliadores ativos do edital, com as categorias que cada um avalia.
     *
     * @return array<int, list<int>> id do avaliador → ids das categorias
     */
    private function destinos(Edital $edital): array
    {
        return $edital->avaliadores()
            ->whereHas('avaliador', fn (Builder $q) => $q->where('ativo', true))
            ->with('categorias:id')
            ->orderBy('avaliador_id')
            ->get()
            ->mapWithKeys(fn (EditalAvaliador $vinculo): array => [
                (int) $vinculo->avaliador_id => $vinculo->categorias->map(fn (EditalCategoria $categoria): int => (int) $categoria->id)->values()->all(),
            ])
            ->all();
    }

    /**
     * Quantas inscrições do edital cada avaliador já tem (concluídas ou não).
     *
     * @return array<int, int> id do avaliador → quantidade
     */
    private function cargasAtuais(Edital $edital): array
    {
        return InscricaoAvaliacao::query()
            ->join('inscricoes', 'inscricoes.id', '=', 'inscricao_avaliacoes.inscricao_id')
            ->where('inscricoes.edital_id', $edital->id)
            ->groupBy('inscricao_avaliacoes.avaliador_id')
            ->selectRaw('inscricao_avaliacoes.avaliador_id as avaliador_id')
            ->selectRaw('count(*) as total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $linha): array => [(int) $linha->avaliador_id => (int) $linha->total])
            ->all();
    }

    /**
     * O destino com menos inscrições; no empate, um deles sorteado.
     *
     * @param  list<int>  $possiveis
     * @param  array<int, int>  $carga
     */
    private function menosCarregado(array $possiveis, array $carga): int
    {
        $menor = min(array_map(fn (int $avaliador): int => $carga[$avaliador], $possiveis));
        $empatados = array_values(array_filter($possiveis, fn (int $avaliador): bool => $carga[$avaliador] === $menor));

        return count($empatados) === 1 ? $empatados[0] : $empatados[$this->sorteio->getInt(0, count($empatados) - 1)];
    }

    /**
     * As sobras na ordem das categorias do edital, com "Sem categoria" no fim.
     *
     * @param  array<string, int>  $sobras
     * @param  array<int, string>  $categorias
     * @return array<string, int>
     */
    private function naOrdemDasCategorias(array $sobras, array $categorias): array
    {
        $ordem = array_flip(array_values([...$categorias, self::SEM_CATEGORIA]));
        uksort($sobras, fn (string $a, string $b): int => ($ordem[$a] ?? PHP_INT_MAX) <=> ($ordem[$b] ?? PHP_INT_MAX));

        return $sobras;
    }
}
