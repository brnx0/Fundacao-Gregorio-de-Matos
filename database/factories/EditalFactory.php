<?php

namespace Database\Factories;

use App\Models\Edital;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Edital>
 */
class EditalFactory extends Factory
{
    public function definition(): array
    {
        $titulo = fake()->unique()->sentence(3);

        return [
            'titulo' => $titulo,
            'slug' => Str::slug($titulo).'-'.fake()->unique()->numberBetween(1, 999999),
            'resumo' => fake()->sentence(12),
            'categoria' => fake()->randomElement(['Fomento', 'Audiovisual', 'Rede municipal']),
            'inscricoes_inicio' => now()->subDays(5)->toDateString(),
            'inscricoes_fim' => now()->addDays(20)->toDateString(),
            // Publicado precisa de imagem da vitrine (Edital::PENDENCIA_IMAGEM).
            'imagem' => 'editais/teste.jpg',
            'pdf' => 'editais/teste.pdf',
            'publicado' => true,
        ];
    }

    public function semPdf(): static
    {
        return $this->state(['pdf' => null]);
    }

    /** Rascunho incompleto (ex.: importação recém-criada): sem título, categoria, resumo, datas, imagem e PDF, não publicado. */
    public function rascunho(): static
    {
        return $this->state([
            'titulo' => null,
            'slug' => 'rascunho-'.fake()->unique()->numberBetween(1, 999999),
            'resumo' => null,
            'categoria' => null,
            'inscricoes_inicio' => null,
            'inscricoes_fim' => null,
            'imagem' => null,
            'pdf' => null,
            'publicado' => false,
        ]);
    }
}
