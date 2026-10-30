<?php

namespace Database\Factories;

use App\Enums\Escolaridade;
use App\Enums\FaixaDeRenda;
use App\Enums\IdentidadeGenero;
use App\Enums\PovoTradicional;
use App\Enums\RacaCor;
use App\Models\AreaAtuacao;
use App\Models\PessoaFisica;
use App\Models\PrefeituraBairro;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PessoaFisica>
 */
class PessoaFisicaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->cadastroCompleto(),
            'nome_social' => null,
            'nome_artistico' => null,
            'data_nascimento' => fake()->dateTimeBetween('-80 years', '-'.PessoaFisica::IDADE_MINIMA.' years')->format('Y-m-d'),
            'foto_perfil' => null,
            'pais_origem' => 'Brasil',
            'rg' => fake()->numerify('########'),
            'documento_cpf' => 'cadastros/cpf.pdf',
            'documento_rg' => 'cadastros/rg.pdf',
            'documento_passaporte' => null,
            'documento_crnm' => null,
            'documento_outros' => null,
            'drt' => null,
            'cultura_principal_renda' => false,
            'ja_foi_avaliador' => false,
            'interesse_avaliador' => false,
            'representa_coletivo' => false,
            'telefone' => fake()->numerify('719########'),
            'telefone_adicional' => null,
            'email' => fake()->safeEmail(),
            'identidade_genero' => fake()->randomElement(IdentidadeGenero::cases()),
            'raca_cor' => fake()->randomElement(RacaCor::cases()),
            'pcd' => false,
            'deficiencia' => null,
            'deficiencia_outra' => null,
            'escolaridade' => fake()->randomElement(Escolaridade::cases()),
            'renda' => fake()->randomElement(FaixaDeRenda::cases()),
            'povo_tradicional' => PovoTradicional::NaoPertence,
            'anos_atuacao' => fake()->numberBetween(0, 30),
            'cep' => fake()->numerify('40######'),
            'logradouro' => fake()->streetName(),
            'numero' => fake()->buildingNumber(),
            'bairro' => 'Centro',
            'complemento' => null,
            'cidade' => 'Salvador',
            'estado' => 'BA',
            // Códigos das Prefeituras-Bairro que a migration cadastra.
            'territorio' => fake()->randomElement(['centro_brotas', 'barra_pituba', 'cajazeiras', PrefeituraBairro::FORA_DE_SALVADOR]),
            'comprovante_residencia' => null,
        ];
    }

    /** Cadastro feito antes do perfil existir. */
    public function perfilIncompleto(): static
    {
        return $this->state(fn () => ['escolaridade' => null, 'renda' => null, 'povo_tradicional' => null, 'anos_atuacao' => null]);
    }

    /** Cadastro feito antes de o formulário pedir a data de nascimento. */
    public function semDataDeNascimento(): static
    {
        return $this->state(fn () => ['data_nascimento' => null]);
    }

    /** Com estas áreas de atuação, pelos nomes (as 31 da migration ou as criadas na factory). */
    public function comAreas(string ...$nomes): static
    {
        return $this->afterCreating(fn (PessoaFisica $pessoa) => $pessoa->areasAtuacao()->attach(
            AreaAtuacao::query()->whereIn('nome', $nomes)->pluck('id'),
        ));
    }
}
