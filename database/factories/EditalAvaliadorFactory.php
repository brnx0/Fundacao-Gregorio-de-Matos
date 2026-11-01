<?php

namespace Database\Factories;

use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EditalAvaliador>
 */
class EditalAvaliadorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'edital_id' => Edital::factory(),
            'avaliador_id' => Avaliador::factory(),
        ];
    }
}
