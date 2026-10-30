<?php

namespace Database\Factories;

use App\Enums\TipoPergunta;
use App\Models\Edital;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EditalPergunta>
 */
class EditalPerguntaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'edital_id' => Edital::factory(),
            'edital_questionario_secao_id' => fn (array $atributos) => EditalQuestionarioSecao::factory()
                ->create(['edital_id' => $atributos['edital_id']])
                ->getKey(),
            'enunciado' => fake()->sentence(6).'?',
            'tipo' => TipoPergunta::TextoCurto,
            'obrigatoria' => true,
            'todas_categorias' => true,
            'ordem' => 1,
        ];
    }

    public function semVinculo(): static
    {
        return $this->state(['todas_categorias' => false]);
    }

    /**
     * @param  list<string>  $textos
     */
    public function escolha(TipoPergunta $tipo, array $textos, bool $comOutro = false): static
    {
        $opcoes = [];
        foreach (array_values($textos) as $indice => $texto) {
            $opcoes[] = ['chave' => 'o'.($indice + 1), 'texto' => $texto, 'outro' => false];
        }
        if ($comOutro) {
            $opcoes[] = ['chave' => 'o'.(count($opcoes) + 1), 'texto' => 'Outro', 'outro' => true];
        }

        return $this->state(['tipo' => $tipo, 'opcoes' => $opcoes]);
    }

    public function simNao(): static
    {
        return $this->state(['tipo' => TipoPergunta::SimNao]);
    }

    public function declaracao(): static
    {
        return $this->state(['tipo' => TipoPergunta::Declaracao]);
    }

    /**
     * @param  list<string>  $chaves
     */
    public function condicionadaA(EditalPergunta $origem, array $chaves): static
    {
        return $this->state([
            'condicao_pergunta_id' => $origem->getKey(),
            'condicao_opcoes' => $chaves,
        ]);
    }
}
