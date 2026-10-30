<?php

namespace Tests\Feature\Inscricao;

use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\InscricaoRecurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

class PeriodoDaHomologacaoTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    public function test_homologacao_aberta_so_entre_inicio_e_fim_em_dias_inteiros(): void
    {
        $inscricao = $this->inscricaoFinalizada($this->editalEmHomologacao());

        $esperados = [
            '2026-11-30 23:59' => false,
            '2026-12-01 00:00' => true,
            '2026-12-10 23:59' => true,
            '2026-12-11 00:00' => false,
        ];

        foreach ($esperados as $momento => $aberta) {
            $this->travelTo(Carbon::parse($momento, 'America/Bahia'));

            $this->assertSame($aberta, $inscricao->chamada->naHomologacao(), $momento);
            $this->assertSame($aberta, $inscricao->naHomologacao(), $momento);
        }
    }

    public function test_sem_periodo_ou_sem_chamada_nao_esta_na_homologacao(): void
    {
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $semPeriodo = $this->inscricaoFinalizada($this->editalEmHomologacao(['homologacao_inicio' => null, 'homologacao_fim' => null]));
        $semChamada = $this->inscricaoFinalizada($this->editalEmHomologacao());
        $semChamada->update(['edital_chamada_id' => null]);

        $this->assertFalse($semPeriodo->chamada->temPeriodoDeHomologacao());
        $this->assertFalse($semPeriodo->naHomologacao());
        $this->assertFalse($semChamada->fresh()->naHomologacao());
    }

    public function test_prazo_do_recurso_e_tres_dias_depois_do_fim_da_homologacao(): void
    {
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $inscricao = $this->naoHomologadaAgora($this->editalEmHomologacao());

        $this->assertSame('2026-12-13', $inscricao->prazoDoRecurso()->toDateString());
        $this->assertNull($this->inscricaoFinalizada($this->editalEmHomologacao())->prazoDoRecurso());
    }

    public function test_prazo_conta_da_decisao_quando_o_periodo_termina_antes_dela(): void
    {
        $this->travelTo(Carbon::parse('2026-12-05 22:30', 'America/Bahia'));
        $periodoEncurtado = $this->naoHomologadaAgora($this->editalEmHomologacao());
        $periodoEncurtado->chamada->update(['homologacao_fim' => '2026-12-03']);
        $semPeriodo = $this->naoHomologadaAgora($this->editalEmHomologacao(['homologacao_inicio' => null, 'homologacao_fim' => null]));

        $this->assertSame('2026-12-08', $periodoEncurtado->fresh()->prazoDoRecurso()->toDateString());
        $this->assertSame('2026-12-08', $semPeriodo->fresh()->prazoDoRecurso()->toDateString());
    }

    public function test_prazo_do_recurso_vai_ate_o_fim_do_ultimo_dia_em_salvador(): void
    {
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $inscricao = $this->naoHomologadaAgora($this->editalEmHomologacao())->fresh();

        $this->travelTo(Carbon::parse('2026-12-13 23:59', 'America/Bahia'));
        $this->assertTrue($inscricao->podeRecorrer());

        $this->travelTo(Carbon::parse('2026-12-14 00:00', 'America/Bahia'));
        $this->assertFalse($inscricao->podeRecorrer());
    }

    public function test_so_nao_homologada_sem_recurso_pode_recorrer(): void
    {
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'America/Bahia'));
        $edital = $this->editalEmHomologacao();
        $chamadaId = $edital->chamadas()->first()->id;
        $comRecurso = $this->naoHomologadaAgora($edital);
        InscricaoRecurso::factory()->create(['inscricao_id' => $comRecurso->id]);

        $this->assertTrue($this->naoHomologadaAgora($edital)->podeRecorrer());
        $this->assertFalse($comRecurso->podeRecorrer());

        foreach (['homologada', 'emRecurso', 'recursoNegado'] as $estado) {
            $inscricao = Inscricao::factory()->for($edital)->{$estado}()->create(['edital_chamada_id' => $chamadaId]);

            $this->assertFalse($inscricao->podeRecorrer(), $estado);
        }
    }

    public function test_situacao_no_portal(): void
    {
        $esperadas = [
            'rascunho' => [Inscricao::factory()->create(), 'Rascunho'],
            'em_homologacao' => [Inscricao::factory()->finalizada()->create(), 'Em homologação'],
            'homologada' => [Inscricao::factory()->homologada()->create(), 'Homologada'],
            'nao_homologada' => [Inscricao::factory()->naoHomologada()->create(), 'Não homologada'],
            'em_recurso' => [Inscricao::factory()->emRecurso()->create(), 'Recurso em análise'],
            'recurso_negado' => [Inscricao::factory()->recursoNegado()->create(), 'Não homologada (recurso negado)'],
        ];

        foreach ($esperadas as $chave => [$inscricao, $rotulo]) {
            $this->assertSame(['chave' => $chave, 'rotulo' => $rotulo], $inscricao->fresh()->situacaoNoPortal());
        }
    }

    /** Inscrição na chamada do edital, não homologada agora. */
    private function naoHomologadaAgora(Edital $edital): Inscricao
    {
        return Inscricao::factory()->for($edital)->naoHomologada()->create([
            'edital_chamada_id' => $edital->chamadas()->first()->id,
        ]);
    }
}
