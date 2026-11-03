<?php

namespace Database\Factories;

use App\Enums\AnexoCadastro;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoConferencia;
use App\Models\PessoaFisica;
use App\Models\PessoaFisicaAnexo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PessoaFisicaAnexo>
 */
class PessoaFisicaAnexoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pessoa_fisica_id' => PessoaFisica::factory(),
            'campo' => AnexoCadastro::DocumentoCpf,
            'arquivo' => 'cadastros/'.fake()->uuid().'.pdf',
            'arquivo_nome' => 'documento.pdf',
            'situacao' => SituacaoConferencia::Enviado,
        ];
    }

    public function campo(AnexoCadastro $campo): static
    {
        return $this->state(['campo' => $campo]);
    }

    public function conferindo(): static
    {
        return $this->state([
            'situacao' => SituacaoConferencia::Conferindo,
            'provedor' => ProvedorIa::Gemini,
            'modelo' => 'gemini-2.5-pro',
            'iniciada_em' => now(),
            'execucao' => fake()->uuid(),
        ]);
    }

    public function aprovado(): static
    {
        return $this->state([
            'situacao' => SituacaoConferencia::Aprovado,
            'provedor' => ProvedorIa::OpenAi,
            'modelo' => 'gpt-5.4',
            'iniciada_em' => now(),
            'conferido_em' => now(),
        ]);
    }

    public function reprovado(string $motivo = 'O arquivo não é o documento pedido.'): static
    {
        return $this->state([
            'situacao' => SituacaoConferencia::Reprovado,
            'motivo' => $motivo,
            'provedor' => ProvedorIa::OpenAi,
            'modelo' => 'gpt-5.4',
            'iniciada_em' => now(),
            'conferido_em' => now(),
        ]);
    }

    public function naoConferido(): static
    {
        return $this->state([
            'situacao' => SituacaoConferencia::NaoConferido,
            'motivo' => 'Não foi possível conferir o documento agora.',
            'erro' => 'Serviço de IA indisponível.',
            'iniciada_em' => now(),
            'conferido_em' => now(),
        ]);
    }
}
