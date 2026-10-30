<?php

namespace Database\Factories;

use App\Models\Inscricao;
use App\Models\InscricaoRecurso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscricaoRecurso>
 */
class InscricaoRecursoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inscricao_id' => Inscricao::factory()->emRecurso(),
            'justificativa' => fake()->sentence(),
            'enviado_em' => now(),
        ];
    }
}
