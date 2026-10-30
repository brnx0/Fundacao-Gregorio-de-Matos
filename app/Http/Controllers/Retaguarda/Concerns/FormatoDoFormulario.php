<?php

namespace App\Http\Controllers\Retaguarda\Concerns;

use App\Enums\NaturezaProponente;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Conversões para o formato das telas do edital (o formulário e o questionário): referências entre listas por
 * posição, como as telas as enviam de volta, naturezas como valores e as opções dos campos de escolha.
 */
trait FormatoDoFormulario
{
    /**
     * @param  Collection<int, Model>  $registros
     * @return array<int, int> id => posição
     */
    private function posicoesPorId(Collection $registros): array
    {
        return $registros->values()->mapWithKeys(fn (Model $registro, int $posicao): array => [(int) $registro->getKey() => $posicao])->all();
    }

    /**
     * @param  Collection<int, Model>  $registros
     * @param  array<int, int>  $posicaoPorId
     * @return list<int>
     */
    private function posicoesDe(Collection $registros, array $posicaoPorId): array
    {
        return $registros
            ->map(fn (Model $registro): ?int => $posicaoPorId[(int) $registro->getKey()] ?? null)
            ->filter(fn (?int $posicao): bool => $posicao !== null)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, NaturezaProponente>|null  $naturezas
     * @return list<string>
     */
    private function valoresDasNaturezas(?Collection $naturezas): array
    {
        return $naturezas?->map(fn (NaturezaProponente $natureza): string => $natureza->value)->values()->all() ?? [];
    }

    /**
     * Opções de um campo de escolha: os casos do enum, com o rótulo.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return list<array{valor: string, rotulo: string}>
     */
    private function opcoesDe(string $enum): array
    {
        return array_map(
            fn (BackedEnum $caso): array => ['valor' => $caso->value, 'rotulo' => $caso->rotulo()],
            $enum::cases(),
        );
    }
}
