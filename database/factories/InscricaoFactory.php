<?php

namespace Database\Factories;

use App\Enums\NaturezaProponente;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inscricao>
 */
class InscricaoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'edital_id' => Edital::factory(),
            'user_id' => User::factory(),
            'documento_cadastro' => fake()->numerify('###########'),
            'natureza' => NaturezaProponente::PessoaFisica,
            'situacao' => SituacaoInscricao::Rascunho,
        ];
    }

    public function finalizada(): static
    {
        return $this->state([
            'situacao' => SituacaoInscricao::Finalizada,
            'numero' => fake()->unique()->numerify('INS-########'),
            'finalizada_em' => now(),
        ]);
    }

    public function homologada(): static
    {
        return $this->finalizada()->state([
            'homologacao' => SituacaoHomologacao::Homologada,
            'homologada_em' => now(),
            'homologada_por' => User::factory()->gestor(),
        ]);
    }

    public function naoHomologada(string $motivo = 'Documentos: RG — ilegível.'): static
    {
        return $this->finalizada()->state([
            'homologacao' => SituacaoHomologacao::NaoHomologada,
            'homologacao_motivo' => $motivo,
            'homologada_em' => now(),
            'homologada_por' => User::factory()->gestor(),
        ]);
    }

    public function emRecurso(): static
    {
        return $this->naoHomologada()->state(['homologacao' => SituacaoHomologacao::EmRecurso]);
    }

    public function recursoNegado(): static
    {
        return $this->naoHomologada()->state(['homologacao' => SituacaoHomologacao::RecursoNegado]);
    }
}
