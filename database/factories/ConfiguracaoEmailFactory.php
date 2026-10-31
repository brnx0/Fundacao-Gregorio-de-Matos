<?php

namespace Database\Factories;

use App\Enums\CriptografiaEmail;
use App\Models\ConfiguracaoEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracaoEmail>
 */
class ConfiguracaoEmailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ativo' => true,
            'host' => 'smtp.gmail.com',
            'porta' => 587,
            'criptografia' => CriptografiaEmail::Tls,
            'usuario' => 'envios@example.com',
            'senha' => 'senha-de-app-de-teste',
            'remetente_email' => 'envios@example.com',
            'remetente_nome' => 'Fundação Gregório de Mattos',
        ];
    }

    /** Configurada, mas o sistema segue usando o .env. */
    public function desligada(): static
    {
        return $this->state(['ativo' => false]);
    }
}
