<?php

namespace Tests\Feature\Avaliacao;

use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCategoria;
use App\Models\EditalCriterio;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use App\Support\Avaliacao\CriteriosDaInscricao;
use App\Support\Avaliacao\NotaFinal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class RegrasDaAvaliacaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    public function test_criterios_de_todas_mais_os_da_categoria(): void
    {
        $edital = $this->editalEmHomologacao();
        $categoriaA = EditalCategoria::factory()->for($edital)->create();
        $categoriaB = EditalCategoria::factory()->for($edital)->create();

        $geral = EditalCriterio::factory()->for($edital)->create(['ordem' => 1]);
        $daA = EditalCriterio::factory()->for($edital)->create(['ordem' => 2, 'todas_categorias' => false]);
        $daB = EditalCriterio::factory()->for($edital)->create(['ordem' => 3, 'todas_categorias' => false]);
        $daA->categorias()->attach($categoriaA->id);
        $daB->categorias()->attach($categoriaB->id);

        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['edital_categoria_id' => $categoriaA->id]);

        $ids = CriteriosDaInscricao::para($inscricao->fresh())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $geral->id, (int) $daA->id], $ids);
    }

    public function test_inscricao_sem_categoria_so_os_de_todas(): void
    {
        $edital = $this->editalEmHomologacao();
        $categoria = EditalCategoria::factory()->for($edital)->create();

        $geral = EditalCriterio::factory()->for($edital)->create(['ordem' => 1]);
        $daCategoria = EditalCriterio::factory()->for($edital)->create(['ordem' => 2, 'todas_categorias' => false]);
        $daCategoria->categorias()->attach($categoria->id);

        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['edital_categoria_id' => null]);

        $ids = CriteriosDaInscricao::para($inscricao->fresh())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([(int) $geral->id], $ids);
    }

    public function test_nota_final_soma_nota_vezes_peso(): void
    {
        $this->assertSame('21.00', NotaFinal::de([
            new InscricaoAvaliacaoNota(['nota' => '8.00', 'peso' => '2.00']),
            new InscricaoAvaliacaoNota(['nota' => '5.00', 'peso' => '1.00']),
        ]));

        $this->assertSame('13.88', NotaFinal::de([
            new InscricaoAvaliacaoNota(['nota' => '7.25', 'peso' => '1.50']),
            new InscricaoAvaliacaoNota(['nota' => '3.00', 'peso' => NotaFinal::PESO_PADRAO]),
        ]));

        $this->assertSame('0.00', NotaFinal::de([]));
    }

    public function test_avaliacao_iniciada(): void
    {
        $edital = $this->editalEmHomologacao();
        $criterio = EditalCriterio::factory()->for($edital)->create();
        $inscricao = $this->inscricaoFinalizada($edital);
        $avaliacao = InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id]);

        $this->assertFalse($edital->avaliacaoIniciada());

        InscricaoAvaliacaoNota::create([
            'inscricao_avaliacao_id' => $avaliacao->id,
            'edital_criterio_id' => $criterio->id,
            'nota' => '5.00',
            'peso' => '1.00',
            'pontos_maximos' => '10.00',
        ]);

        $this->assertTrue($edital->avaliacaoIniciada());
        $this->assertFalse($this->editalEmHomologacao()->avaliacaoIniciada());
    }

    public function test_pendencias_da_distribuicao(): void
    {
        $edital = Edital::factory()->create();
        $this->assertSame([Edital::PENDENCIA_SEM_AVALIADOR, Edital::PENDENCIA_SEM_CRITERIO], $edital->pendenciasDaDistribuicao());

        EditalCriterio::factory()->for($edital)->create(['pontos_maximos' => 10]);
        $this->assertSame([Edital::PENDENCIA_SEM_AVALIADOR], $edital->pendenciasDaDistribuicao());

        $inativo = Avaliador::factory()->inativo()->create();
        EditalAvaliador::create(['edital_id' => $edital->id, 'avaliador_id' => $inativo->id]);
        $this->assertSame([Edital::PENDENCIA_SEM_AVALIADOR], $edital->pendenciasDaDistribuicao());

        $ativo = Avaliador::factory()->create();
        EditalAvaliador::create(['edital_id' => $edital->id, 'avaliador_id' => $ativo->id]);
        $this->assertSame([], $edital->pendenciasDaDistribuicao());

        $semPontos = EditalCriterio::factory()->for($edital)->create(['pontos_maximos' => null, 'ordem' => 2]);
        $this->assertSame([Edital::PENDENCIA_CRITERIO_SEM_PONTOS], $edital->pendenciasDaDistribuicao());

        $semPontos->delete();
        EditalCriterio::query()->where('edital_id', $edital->id)->delete();
        $this->assertSame([Edital::PENDENCIA_SEM_CRITERIO], $edital->pendenciasDaDistribuicao());
    }
}
