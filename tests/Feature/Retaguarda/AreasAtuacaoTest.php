<?php

namespace Tests\Feature\Retaguarda;

use App\Models\AreaAtuacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AreasAtuacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());
    }

    private function area(string $nome): AreaAtuacao
    {
        return AreaAtuacao::where('nome', $nome)->sole();
    }

    public function test_migration_traz_as_areas_do_levantamento_ativas(): void
    {
        $this->assertSame(31, AreaAtuacao::where('ativo', true)->count());
        $this->assertTrue(AreaAtuacao::where('nome', 'Serviços Culturais / Criativos')->exists());
        $this->assertTrue(AreaAtuacao::where('nome', 'Outros')->exists());
    }

    public function test_lista_por_nome_paginada(): void
    {
        $this->get('/retaguarda/areas-atuacao')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/AreasAtuacao/Index')
            ->has('areas.data', 15)
            ->where('areas.total', 31)
            ->where('areas.data.0.nome', 'Arquitetura')
            ->where('areas.data.0.ativo', true));
    }

    public function test_busca_pelo_nome_sem_diferenca_de_maiusculas(): void
    {
        $this->get('/retaguarda/areas-atuacao?busca=ATIVIDADES')->assertInertia(fn (Assert $p) => $p->where('areas.total', 6));

        $this->get('/retaguarda/areas-atuacao?busca=%25')->assertInertia(fn (Assert $p) => $p->where('areas.total', 0));
    }

    public function test_cria_area_ativa(): void
    {
        $this->post('/retaguarda/areas-atuacao', ['nome' => 'Circo'])
            ->assertRedirect('/retaguarda/areas-atuacao')
            ->assertSessionHas('sucesso', 'Área de atuação salva.');

        $this->assertTrue($this->area('Circo')->ativo);
    }

    public function test_edicao_traz_a_area_e_atualiza_o_nome(): void
    {
        $teatro = $this->area('Teatro');

        $this->get("/retaguarda/areas-atuacao/{$teatro->id}/edit")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/AreasAtuacao/Form')
            ->where('area', ['id' => $teatro->id, 'nome' => 'Teatro', 'ativo' => true]));

        $this->put("/retaguarda/areas-atuacao/{$teatro->id}", ['nome' => 'Teatro e Circo'])->assertSessionHasNoErrors();

        $this->assertSame('Teatro e Circo', $teatro->fresh()->nome);
    }

    public function test_nome_repetido_e_recusado_sem_diferenca_de_maiusculas(): void
    {
        $this->post('/retaguarda/areas-atuacao', ['nome' => 'TEATRO'])
            ->assertSessionHasErrors(['nome' => 'Já existe uma área de atuação com este nome.']);

        $this->assertSame(31, AreaAtuacao::count());
    }

    public function test_salvar_a_propria_area_sem_mudancas_passa(): void
    {
        $teatro = $this->area('Teatro');

        $this->put("/retaguarda/areas-atuacao/{$teatro->id}", ['nome' => 'teatro'])->assertSessionHasNoErrors();
    }

    public function test_nome_obrigatorio_e_dentro_do_limite_em_bytes(): void
    {
        $this->post('/retaguarda/areas-atuacao', ['nome' => ''])->assertSessionHasErrors('nome');
        $this->post('/retaguarda/areas-atuacao', ['nome' => str_repeat('ç', 60)])->assertSessionHasErrors('nome');
    }

    public function test_desativa_e_reativa(): void
    {
        $teatro = $this->area('Teatro');

        $this->patch("/retaguarda/areas-atuacao/{$teatro->id}/ativo")->assertSessionHas('sucesso', 'Área de atuação desativada.');
        $this->assertFalse($teatro->fresh()->ativo);

        $this->patch("/retaguarda/areas-atuacao/{$teatro->id}/ativo")->assertSessionHas('sucesso', 'Área de atuação ativada.');
        $this->assertTrue($teatro->fresh()->ativo);
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $this->actingAs(User::factory()->create());
        $teatro = $this->area('Teatro');

        $this->get('/retaguarda/areas-atuacao')->assertRedirect('/retaguarda/login');
        $this->post('/retaguarda/areas-atuacao', ['nome' => 'Circo'])->assertRedirect('/retaguarda/login');
        $this->patch("/retaguarda/areas-atuacao/{$teatro->id}/ativo")->assertRedirect('/retaguarda/login');

        $this->assertTrue($teatro->fresh()->ativo);
        $this->assertSame(31, AreaAtuacao::count());
    }

    public function test_menu_de_parametrizacao_tem_areas_de_atuacao(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.areas-atuacao.index', absolute: false), $menu);
        $this->assertStringContainsString("'Áreas de atuação'", $menu);
    }
}
