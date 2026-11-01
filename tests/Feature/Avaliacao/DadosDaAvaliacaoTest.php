<?php

namespace Tests\Feature\Avaliacao;

use App\Enums\SituacaoAvaliacao;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class DadosDaAvaliacaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    public function test_periodo_de_avaliacao_em_dias_inteiros_de_salvador(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao([
            'validacao_inicio' => '2026-12-15',
            'validacao_fim' => '2026-12-20',
        ]));

        $esperados = [
            '2026-12-14 23:59' => false,
            '2026-12-15 00:00' => true,
            '2026-12-20 23:59' => true,
            '2026-12-21 00:00' => false,
        ];

        foreach ($esperados as $momento => $aberta) {
            $this->travelTo(Carbon::parse($momento, 'America/Bahia'));

            $this->assertSame($aberta, $inscricao->chamada->naAvaliacao(), $momento);
            $this->assertSame($aberta, $inscricao->naAvaliacao(), $momento);
        }

        $semPeriodo = $this->inscricaoFinalizada($this->editalEmHomologacao(['validacao_inicio' => null, 'validacao_fim' => null]));

        $this->assertFalse($semPeriodo->chamada->temPeriodoDeAvaliacao());
        $this->assertFalse($semPeriodo->chamada->naAvaliacao());
        $this->assertFalse($semPeriodo->naAvaliacao());
    }

    public function test_inscricao_tem_uma_avaliacao_so(): void
    {
        $inscricao = Inscricao::factory()->finalizada()->create();
        InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id]);

        $this->assertInstanceOf(InscricaoAvaliacao::class, $inscricao->avaliacao);

        $this->expectException(QueryException::class);

        InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id]);
    }

    public function test_nota_por_criterio_e_unica(): void
    {
        $avaliacao = InscricaoAvaliacao::factory()->create();
        $criterio = EditalCriterio::factory()->create();
        $nota = ['edital_criterio_id' => $criterio->id, 'nota' => 7, 'peso' => 1, 'pontos_maximos' => 10];

        $avaliacao->notas()->create($nota);

        $this->assertSame('7.00', $avaliacao->notas()->first()->nota);
        $this->assertSame($criterio->id, $avaliacao->notas()->first()->criterio->id);

        $this->expectException(QueryException::class);

        $avaliacao->notas()->create($nota);
    }

    public function test_avaliador_entra_uma_vez_no_edital(): void
    {
        $edital = Edital::factory()->create();
        $avaliador = Avaliador::factory()->create();
        EditalAvaliador::factory()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]);

        $this->assertSame(1, $edital->avaliadores()->count());
        $this->assertSame(1, $avaliador->vinculos()->count());

        $this->expectException(QueryException::class);

        EditalAvaliador::factory()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]);
    }

    public function test_situacao_da_avaliacao(): void
    {
        $avaliacao = InscricaoAvaliacao::factory()->create();

        $this->assertSame(SituacaoAvaliacao::NaoIniciada, $avaliacao->situacao());

        $avaliacao->notas()->create([
            'edital_criterio_id' => EditalCriterio::factory()->create()->id,
            'nota' => 5, 'peso' => 1, 'pontos_maximos' => 10,
        ]);

        $this->assertSame(SituacaoAvaliacao::EmAndamento, $avaliacao->fresh()->situacao());

        $soObservacao = InscricaoAvaliacao::factory()->create(['observacao' => 'Pendente de análise.']);

        $this->assertSame(SituacaoAvaliacao::EmAndamento, $soObservacao->situacao());

        $concluida = InscricaoAvaliacao::factory()->concluida()->create();

        $this->assertSame(SituacaoAvaliacao::Concluida, $concluida->situacao());
        $this->assertInstanceOf(InscricaoAvaliacaoNota::class, $avaliacao->notas()->first());
    }

    public function test_acesso_fica_fora_da_serializacao(): void
    {
        $avaliador = Avaliador::factory()->create();
        $avaliador->forceFill(['acesso_hash' => str_repeat('a', 64), 'acesso_cifrado' => 'segredo'])->save();

        $this->assertArrayNotHasKey('acesso_hash', $avaliador->fresh()->toArray());
        $this->assertArrayNotHasKey('acesso_cifrado', $avaliador->fresh()->toArray());
        $this->assertSame(str_repeat('a', 64), $avaliador->fresh()->acesso_hash);
    }
}
