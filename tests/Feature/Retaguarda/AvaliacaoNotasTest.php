<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Models\InscricaoAvaliacaoNota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CenarioDaHomologacao;
use Tests\TestCase;

/** Retaguarda: a aba Notas da avaliação (só avaliação concluída mostra nota). */
class AvaliacaoNotasTest extends TestCase
{
    use CenarioDaHomologacao;
    use RefreshDatabase;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gestor = User::factory()->gestor()->create();
        $this->actingAs($this->gestor);
    }

    private function editalComCriterios(): Edital
    {
        return $this->editalEmHomologacao(['validacao_inicio' => '2026-12-15', 'validacao_fim' => '2026-12-20']);
    }

    private function inscricaoHomologada(Edital $edital): Inscricao
    {
        $inscricao = $this->inscricaoFinalizada($edital);
        $inscricao->update(['homologacao' => 'homologada']);

        return $inscricao;
    }

    private function atribuir(Inscricao $inscricao, string $nome = 'Ana'): InscricaoAvaliacao
    {
        $avaliador = Avaliador::factory()->create(['nome' => $nome]);

        return InscricaoAvaliacao::factory()->create(['inscricao_id' => $inscricao->id, 'avaliador_id' => $avaliador->id]);
    }

    private function nota(InscricaoAvaliacao $avaliacao, EditalCriterio $criterio, string $nota, string $peso = '1.00', string $pontos = '10.00'): void
    {
        InscricaoAvaliacaoNota::query()->create([
            'inscricao_avaliacao_id' => $avaliacao->id,
            'edital_criterio_id' => $criterio->id,
            'nota' => $nota,
            'peso' => $peso,
            'pontos_maximos' => $pontos,
        ]);
    }

    private function aba(Edital $edital, string $consulta = ''): string
    {
        return "/retaguarda/avaliacao/editais/{$edital->slug}/notas{$consulta}";
    }

    public function test_lista_com_nota_final_so_das_concluidas(): void
    {
        $edital = $this->editalComCriterios();
        $concluida = $this->atribuir($this->inscricaoHomologada($edital), 'Ana');
        $concluida->update(['concluida_em' => now(), 'nota_final' => '8.50']);
        $this->atribuir($this->inscricaoHomologada($edital), 'Bruno');
        $this->inscricaoHomologada($edital);

        $this->get($this->aba($edital))
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Retaguarda/Avaliacao/Notas')
                ->has('inscricoes.data', 3)
                ->where('inscricoes.data.0.avaliador', 'Ana')
                ->where('inscricoes.data.0.situacaoRotulo', 'Concluída')
                ->where('inscricoes.data.0.notaFinal', '8,50')
                ->where('inscricoes.data.1.situacaoRotulo', 'Não iniciada')
                ->where('inscricoes.data.1.notaFinal', null)
                ->where('inscricoes.data.2.avaliador', null)
                ->where('inscricoes.data.2.situacaoRotulo', 'Sem avaliador')
                ->where('inscricoes.data.2.notaFinal', null));
    }

    public function test_rascunho_nunca_aparece(): void
    {
        $edital = $this->editalComCriterios();
        $criterio = EditalCriterio::factory()->for($edital)->create(['nome' => 'Critério secreto']);
        $inscricao = $this->inscricaoHomologada($edital);
        $avaliacao = $this->atribuir($inscricao);
        $avaliacao->update(['observacao' => 'Rascunho reservado', 'nota_final' => '7.77']);
        $this->nota($avaliacao, $criterio, '6.66');

        $resposta = $this->get($this->aba($edital))->assertOk();

        $resposta->assertInertia(fn (Assert $pagina) => $pagina
            ->where('inscricoes.data.0.situacaoRotulo', 'Em andamento')
            ->where('inscricoes.data.0.notaFinal', null));

        $json = json_encode($resposta->inertiaProps(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Rascunho reservado', $json);
        $this->assertStringNotContainsString('6,66', $json);
        $this->assertStringNotContainsString('6.66', $json);
        $this->assertStringNotContainsString('7,77', $json);
        $this->assertStringNotContainsString('7.77', $json);
        $this->assertStringNotContainsString('Critério secreto', $json);
    }

    public function test_mostra_a_nota_por_criterio_com_a_copia(): void
    {
        $edital = $this->editalComCriterios();
        $segundo = EditalCriterio::factory()->for($edital)->create(['nome' => 'Impacto', 'ordem' => 2]);
        $primeiro = EditalCriterio::factory()->for($edital)->create(['nome' => 'Mérito', 'ordem' => 1]);
        $inscricao = $this->inscricaoHomologada($edital);
        $avaliacao = $this->atribuir($inscricao, 'Ana');
        $avaliacao->update(['concluida_em' => '2026-12-18 14:30:00', 'nota_final' => '12.50', 'observacao' => 'Bom projeto']);
        $this->nota($avaliacao, $segundo, '5.00', '1.00', '10.00');
        $this->nota($avaliacao, $primeiro, '7.50', '2.00', '20.00');
        // O edital muda depois da nota: a tela mostra a cópia gravada.
        $primeiro->update(['peso' => '9.00', 'pontos_maximos' => '99.00']);

        $this->get($this->aba($edital, "/{$inscricao->id}"))
            ->assertOk()
            ->assertInertia(fn (Assert $pagina) => $pagina
                ->component('Retaguarda/Avaliacao/NotasDaInscricao')
                ->where('avaliacao.avaliador', 'Ana')
                ->where('avaliacao.notaFinal', '12,50')
                ->where('avaliacao.observacao', 'Bom projeto')
                ->where('avaliacao.concluidaEm', '18/12/2026 14:30')
                ->has('avaliacao.criterios', 2)
                ->where('avaliacao.criterios.0.nome', 'Mérito')
                ->where('avaliacao.criterios.0.nota', '7,50')
                ->where('avaliacao.criterios.0.peso', '2,00')
                ->where('avaliacao.criterios.0.pontosMaximos', '20,00')
                ->where('avaliacao.criterios.1.nome', 'Impacto')
                ->where('avaliacao.criterios.1.nota', '5,00'));
    }

    public function test_avaliacao_nao_concluida_da_404(): void
    {
        $edital = $this->editalComCriterios();
        $comRascunho = $this->inscricaoHomologada($edital);
        $this->atribuir($comRascunho)->update(['observacao' => 'Rascunho']);
        $semAvaliador = $this->inscricaoHomologada($edital);

        $this->get($this->aba($edital, "/{$comRascunho->id}"))->assertNotFound();
        $this->get($this->aba($edital, "/{$semAvaliador->id}"))->assertNotFound();
    }

    public function test_inscricao_de_outro_edital_da_404(): void
    {
        $edital = $this->editalComCriterios();
        $outro = $this->editalComCriterios();
        $inscricao = $this->inscricaoHomologada($outro);
        $this->atribuir($inscricao)->update(['concluida_em' => now(), 'nota_final' => '8.00']);

        $this->get($this->aba($edital, "/{$inscricao->id}"))->assertNotFound();
    }

    public function test_filtra_por_situacao(): void
    {
        $edital = $this->editalComCriterios();
        $criterio = EditalCriterio::factory()->for($edital)->create();
        $semAvaliador = $this->inscricaoHomologada($edital);
        $naoIniciada = $this->inscricaoHomologada($edital);
        $this->atribuir($naoIniciada);
        $emAndamento = $this->inscricaoHomologada($edital);
        $this->nota($this->atribuir($emAndamento), $criterio, '5.00');
        $concluida = $this->inscricaoHomologada($edital);
        $this->atribuir($concluida)->update(['concluida_em' => now(), 'nota_final' => '8.00']);

        foreach ([
            'sem_avaliador' => $semAvaliador,
            'nao_iniciada' => $naoIniciada,
            'em_andamento' => $emAndamento,
            'concluida' => $concluida,
        ] as $valor => $esperada) {
            $this->get($this->aba($edital, "?situacao={$valor}"))
                ->assertOk()
                ->assertInertia(fn (Assert $pagina) => $pagina
                    ->has('inscricoes.data', 1)
                    ->where('inscricoes.data.0.id', (int) $esperada->id)
                    ->where('filtros.situacao', $valor));
        }

        $this->get($this->aba($edital, '?situacao=inventada'))
            ->assertInertia(fn (Assert $pagina) => $pagina->has('inscricoes.data', 4)->where('filtros.situacao', ''));
    }

    public function test_quem_nao_e_gestor_vai_para_o_login(): void
    {
        $edital = $this->editalComCriterios();
        $inscricao = $this->inscricaoHomologada($edital);
        $this->atribuir($inscricao)->update(['concluida_em' => now(), 'nota_final' => '8.00']);

        Auth::logout();
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');
        $this->get($this->aba($edital, "/{$inscricao->id}"))->assertRedirect('/retaguarda/login');

        $this->actingAs(User::factory()->proponente()->create());
        $this->get($this->aba($edital))->assertRedirect('/retaguarda/login');
        $this->get($this->aba($edital, "/{$inscricao->id}"))->assertRedirect('/retaguarda/login');
    }
}
