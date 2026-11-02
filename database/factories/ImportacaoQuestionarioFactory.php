<?php

namespace Database\Factories;

use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Models\Edital;
use App\Models\ImportacaoEdital;
use App\Models\ImportacaoQuestionario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportacaoQuestionario>
 */
class ImportacaoQuestionarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->gestor(),
            'edital_id' => Edital::factory(),
            'situacao' => SituacaoImportacao::Analisando,
            'arquivo' => ImportacaoQuestionario::PASTA.'/'.fake()->uuid().'.pdf',
            'arquivo_nome' => 'espelho.pdf',
            'tipo_arquivo' => 'pdf',
            'provedor' => ProvedorIa::Gemini,
            'modelo' => 'gemini-2.5-pro',
            'iniciada_em' => now(),
            'execucao' => ImportacaoEdital::novaExecucao(),
        ];
    }

    public function comErro(string $mensagem = 'Google Gemini está com instabilidade. Tente de novo em alguns minutos.'): static
    {
        return $this->state(['situacao' => SituacaoImportacao::Erro, 'erro' => $mensagem]);
    }

    public function concluida(): static
    {
        return $this->state([
            'situacao' => SituacaoImportacao::Concluida,
            'concluida_em' => now(),
            'confiabilidade' => ['geral' => 85, 'blocos' => ['questionario' => ['nota' => 85, 'justificativa' => 'Formulário claro.']]],
            'atencao' => [],
        ]);
    }
}
