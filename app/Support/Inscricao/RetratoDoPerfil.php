<?php

namespace App\Support\Inscricao;

use App\Models\PessoaFisica;

/**
 * O retrato do perfil gravado na inscrição (PessoaFisica::retratoDoPerfil) pronto para ler, com os rótulos da seção
 * "Identidade e perfil" do detalhe do proponente na retaguarda.
 */
final class RetratoDoPerfil
{
    /**
     * Uma linha por campo do perfil, na ordem da seção; valor nulo quando não informado. Vazio sem retrato.
     *
     * @param  array<string, mixed>|null  $perfil
     * @return list<array{rotulo: string, valor: ?string}>
     */
    public static function linhas(?array $perfil): array
    {
        if ($perfil === null) {
            return [];
        }

        // Preenchido (não salvo) só para valerem os casts: enums com rótulo, PcD como booleano.
        $pessoa = (new PessoaFisica)->forceFill($perfil);
        $campos = [
            'Identidade de gênero' => $pessoa->identidade_genero?->rotulo(),
            'Raça/cor' => $pessoa->raca_cor?->rotulo(),
            'Pessoa com deficiência (PcD)' => $pessoa->pcd === null ? null : ($pessoa->pcd ? 'Sim' : 'Não'),
            'Tipo de deficiência' => $pessoa->rotuloDoTipoDeDeficiencia(),
            'Escolaridade' => $pessoa->escolaridade?->rotulo(),
            'Renda mensal' => $pessoa->renda?->rotulo(),
            'Povo ou comunidade tradicional' => $pessoa->povo_tradicional?->rotulo(),
            'Anos de atuação na área cultural' => $pessoa->anos_atuacao === null ? null : (string) $pessoa->anos_atuacao,
        ];

        return collect($campos)
            ->map(fn (?string $valor, string $rotulo): array => ['rotulo' => $rotulo, 'valor' => filled($valor) ? $valor : null])
            ->values()
            ->all();
    }
}
