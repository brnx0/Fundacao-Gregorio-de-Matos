<?php

namespace Database\Factories;

use App\Enums\MarcacaoDoItem;
use App\Models\EditalDocumento;
use App\Models\EditalPergunta;
use App\Models\Inscricao;
use App\Models\InscricaoConferencia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscricaoConferencia>
 */
class InscricaoConferenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inscricao_id' => Inscricao::factory()->finalizada(),
            'edital_documento_id' => EditalDocumento::factory(),
            'situacao' => MarcacaoDoItem::Ok,
            'user_id' => User::factory()->gestor(),
        ];
    }

    public function problema(string $observacao = 'Ilegível.'): static
    {
        return $this->state([
            'situacao' => MarcacaoDoItem::Problema,
            'observacao' => $observacao,
        ]);
    }

    public function daPergunta(EditalPergunta $pergunta): static
    {
        return $this->state([
            'edital_documento_id' => null,
            'edital_pergunta_id' => $pergunta->id,
        ]);
    }
}
