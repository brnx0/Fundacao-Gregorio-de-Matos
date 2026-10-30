<?php

namespace Database\Factories;

use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\InscricaoRecurso;
use App\Models\InscricaoRecursoDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscricaoRecursoDocumento>
 */
class InscricaoRecursoDocumentoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inscricao_recurso_id' => InscricaoRecurso::factory(),
            'edital_documento_id' => EditalDocumento::factory(),
            'arquivo' => Inscricao::PASTA.'/'.fake()->uuid().'.pdf',
            'arquivo_nome' => 'novo.pdf',
        ];
    }
}
