<?php

namespace Tests\Unit;

use App\Enums\ModoDistribuicao;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Support\Avaliacao\DistribuidorDeInscricoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/** O plano da distribuição: o destino com menos inscrições no edital recebe, e o empate é sorteado. */
class DistribuidorDeInscricoesTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private function inscricaoHomologada(Edital $edital): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['homologacao' => 'homologada']);

        return $inscricao;
    }

    private function avaliadorNoEdital(Edital $edital): Avaliador
    {
        $avaliador = Avaliador::factory()->create();
        EditalAvaliador::factory()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]);

        return $avaliador;
    }

    private function sorteio(int $semente): Randomizer
    {
        return new Randomizer(new Mt19937($semente));
    }

    public function test_empate_e_sorteado_e_reproduzivel(): void
    {
        $edital = $this->editalEmHomologacao();
        $ana = $this->avaliadorNoEdital($edital);
        $bruno = $this->avaliadorNoEdital($edital);
        $inscricao = $this->inscricaoHomologada($edital);

        $primeiro = (new DistribuidorDeInscricoes($this->sorteio(1)))->plano($edital, ModoDistribuicao::Igual);
        $deNovo = (new DistribuidorDeInscricoes($this->sorteio(1)))->plano($edital, ModoDistribuicao::Igual);

        $this->assertSame($primeiro->atribuicoes, $deNovo->atribuicoes);
        $this->assertCount(1, $primeiro->atribuicoes);
        $this->assertContains($primeiro->atribuicoes[(int) $inscricao->id], [(int) $ana->id, (int) $bruno->id]);

        // O empate não vai sempre para o mesmo (o de menor id, por exemplo): sementes diferentes dão destinos diferentes.
        $sorteados = collect(range(1, 20))
            ->map(fn (int $semente): int => (new DistribuidorDeInscricoes($this->sorteio($semente)))
                ->plano($edital, ModoDistribuicao::Igual)
                ->atribuicoes[(int) $inscricao->id])
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->assertSame([(int) $ana->id, (int) $bruno->id], $sorteados);
    }

    public function test_carga_atual_conta(): void
    {
        $edital = $this->editalEmHomologacao();
        $ana = $this->avaliadorNoEdital($edital);
        $bruno = $this->avaliadorNoEdital($edital);
        InscricaoAvaliacao::factory()->create(['inscricao_id' => $this->inscricaoHomologada($edital)->id, 'avaliador_id' => $ana->id]);
        InscricaoAvaliacao::factory()->concluida()->create(['inscricao_id' => $this->inscricaoHomologada($edital)->id, 'avaliador_id' => $ana->id]);
        // As inscrições de outro edital não pesam.
        $outro = $this->editalEmHomologacao();
        foreach (range(1, 3) as $vez) {
            InscricaoAvaliacao::factory()->create(['inscricao_id' => $this->inscricaoHomologada($outro)->id, 'avaliador_id' => $bruno->id]);
        }
        $novas = [$this->inscricaoHomologada($edital), $this->inscricaoHomologada($edital)];

        $plano = (new DistribuidorDeInscricoes($this->sorteio(1)))->plano($edital, ModoDistribuicao::Igual);

        $this->assertSame([(int) $novas[0]->id, (int) $novas[1]->id], collect($plano->atribuicoes)->keys()->sort()->values()->all());
        $this->assertSame([(int) $bruno->id], array_values(array_unique($plano->atribuicoes)));
        $this->assertSame([(int) $bruno->id => 2], $plano->porAvaliador);
        $this->assertSame([], $plano->semAvaliador);
    }
}
