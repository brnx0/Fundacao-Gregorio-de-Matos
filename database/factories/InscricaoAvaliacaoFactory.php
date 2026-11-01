<?php

namespace Database\Factories;

use App\Models\Avaliador;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscricaoAvaliacao>
 */
class InscricaoAvaliacaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inscricao_id' => Inscricao::factory()->finalizada(),
            'avaliador_id' => Avaliador::factory(),
            'atribuida_por' => User::factory()->gestor(),
            'atribuida_em' => now(),
        ];
    }

    public function concluida(string $notaFinal = '8.00'): static
    {
        return $this->state([
            'concluida_em' => now(),
            'nota_final' => $notaFinal,
        ]);
    }
}
