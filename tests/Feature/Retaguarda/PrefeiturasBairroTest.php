<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Bairro;
use App\Models\PrefeituraBairro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrefeiturasBairroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(array $alteracoes = []): array
    {
        return [
            'numero' => 11,
            'nome' => 'Ilhas',
            'bairros' => [['id' => null, 'nome' => 'Ilha Nova'], ['id' => null, 'nome' => 'Ilha do Medo']],
            ...$alteracoes,
        ];
    }

    private function prefeitura(string $codigo): PrefeituraBairro
    {
        return PrefeituraBairro::where('codigo', $codigo)->sole();
    }

    public function test_migration_traz_a_delimitacao_oficial_das_dez_prefeituras_bairro(): void
    {
        $this->assertSame(10, PrefeituraBairro::count());
        $this->assertSame(171, Bairro::count());
        $this->assertSame(23, $this->prefeitura('barra_pituba')->bairros()->count());
        $this->assertSame(4, $this->prefeitura('valeria')->bairros()->count());
        $this->assertSame('Barra/Pituba', Bairro::where('nome', 'Aquarius')->sole()->prefeituraBairro->nome);
        $this->assertSame('Pau da Lima', Bairro::where('nome', 'Vila Canária')->sole()->prefeituraBairro->nome);
    }

    public function test_lista_por_numero_com_o_total_de_bairros(): void
    {
        $this->get('/retaguarda/prefeituras-bairro')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/PrefeiturasBairro/Index')
            ->has('prefeituras.data', 10)
            ->where('prefeituras.data.0.numero', 'I')
            ->where('prefeituras.data.0.nome', 'Centro/Brotas')
            ->where('prefeituras.data.0.totalBairros', 23)
            ->where('prefeituras.data.3.numero', 'IV')
            ->where('prefeituras.data.9.numero', 'X')
            ->where('prefeituras.data.9.totalBairros', 4));
    }

    public function test_busca_pelo_nome_da_prefeitura_bairro_ou_de_um_bairro_sem_diferenca_de_maiusculas(): void
    {
        $this->get('/retaguarda/prefeituras-bairro?busca=AQUARIUS')->assertInertia(fn (Assert $p) => $p
            ->has('prefeituras.data', 1)
            ->where('prefeituras.data.0.nome', 'Barra/Pituba'));

        $this->get('/retaguarda/prefeituras-bairro?busca=cabula')->assertInertia(fn (Assert $p) => $p
            ->has('prefeituras.data', 1)
            ->where('prefeituras.data.0.nome', 'Cabula/Tancredo Neves'));

        $this->get('/retaguarda/prefeituras-bairro?busca=%25')->assertInertia(fn (Assert $p) => $p->has('prefeituras.data', 0));
    }

    public function test_edicao_traz_os_bairros_em_ordem_alfabetica(): void
    {
        $cajazeiras = $this->prefeitura('cajazeiras');

        $this->get("/retaguarda/prefeituras-bairro/{$cajazeiras->id}/edit")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/PrefeiturasBairro/Form')
            ->where('prefeitura.numero', 3)
            ->has('prefeitura.bairros', 17)
            ->where('prefeitura.bairros.0.nome', 'Águas Claras')
            ->where('prefeitura.bairros.16.nome', 'Jaguaripe I'));
    }

    public function test_cria_prefeitura_bairro_com_os_bairros_e_codigo_tirado_do_nome(): void
    {
        $this->post('/retaguarda/prefeituras-bairro', $this->dados(['nome' => 'Ilhas da Baía']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/prefeituras-bairro')
            ->assertSessionHas('sucesso', 'Prefeitura-Bairro salva.');

        $nova = PrefeituraBairro::where('nome', 'Ilhas da Baía')->sole();
        $this->assertSame(11, $nova->numero);
        $this->assertSame('ilhas_da_baia', $nova->codigo);
        $this->assertTrue($nova->ativo);
        $this->assertEqualsCanonicalizing(['Ilha Nova', 'Ilha do Medo'], $nova->bairros()->pluck('nome')->all());
    }

    public function test_atualiza_renomeia_acrescenta_e_remove_bairros_numa_gravacao_so_sem_mudar_o_codigo(): void
    {
        $valeria = $this->prefeitura('valeria');
        $piraja = Bairro::where('nome', 'Pirajá')->sole();
        $palestina = Bairro::where('nome', 'Palestina')->sole();

        $this->put("/retaguarda/prefeituras-bairro/{$valeria->id}", [
            'numero' => 10,
            'nome' => 'Valéria/Pirajá',
            'bairros' => [
                ['id' => $piraja->id, 'nome' => 'Pirajá Velho'],
                ['id' => $palestina->id, 'nome' => 'Palestina'],
                ['id' => null, 'nome' => 'Boa Vista do Lobato'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect('/retaguarda/prefeituras-bairro');

        $valeria->refresh();
        $this->assertSame('Valéria/Pirajá', $valeria->nome);
        $this->assertSame('valeria', $valeria->codigo);
        $this->assertEqualsCanonicalizing(['Pirajá Velho', 'Palestina', 'Boa Vista do Lobato'], $valeria->bairros()->pluck('nome')->all());
        $this->assertSame((int) $piraja->id, (int) Bairro::where('nome', 'Pirajá Velho')->value('id'));
        $this->assertDatabaseMissing('bairros', ['nome' => 'Valéria']);
    }

    public function test_nome_liberado_na_mesma_gravacao_pode_ser_reaproveitado(): void
    {
        $valeria = $this->prefeitura('valeria');
        $piraja = Bairro::where('nome', 'Pirajá')->sole();

        $this->put("/retaguarda/prefeituras-bairro/{$valeria->id}", [
            'numero' => 10,
            'nome' => 'Valéria',
            'bairros' => [['id' => $piraja->id, 'nome' => 'Valéria'], ['id' => null, 'nome' => 'Pirajá']],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['Valéria', 'Pirajá'], $valeria->bairros()->pluck('nome')->all());
    }

    public function test_id_de_bairro_de_outra_prefeitura_bairro_nao_e_mexido(): void
    {
        $nova = PrefeituraBairro::factory()->create();
        $aquarius = Bairro::where('nome', 'Aquarius')->sole();

        $this->put("/retaguarda/prefeituras-bairro/{$nova->id}", $this->dados([
            'nome' => $nova->nome,
            'numero' => $nova->numero,
            'bairros' => [['id' => $aquarius->id, 'nome' => 'Bairro Novo']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Aquarius', $aquarius->fresh()->nome);
        $this->assertSame('Barra/Pituba', $aquarius->fresh()->prefeituraBairro->nome);
        $this->assertSame(['Bairro Novo'], $nova->bairros()->pluck('nome')->all());
    }

    public function test_bairro_de_outra_prefeitura_bairro_e_recusado_com_o_nome_dela(): void
    {
        $this->post('/retaguarda/prefeituras-bairro', $this->dados([
            'bairros' => [['id' => null, 'nome' => 'Ilha Nova'], ['id' => null, 'nome' => 'vila canária']],
        ]))->assertSessionHasErrors(['bairros.1.nome' => 'Este bairro já pertence à Prefeitura-Bairro Pau da Lima.']);

        $this->assertDatabaseMissing('prefeituras_bairro', ['nome' => 'Ilhas']);
    }

    public function test_bairro_repetido_na_lista_e_recusado(): void
    {
        $this->post('/retaguarda/prefeituras-bairro', $this->dados([
            'bairros' => [['id' => null, 'nome' => 'Ilha Nova'], ['id' => null, 'nome' => 'ILHA NOVA']],
        ]))->assertSessionHasErrors(['bairros.1.nome' => 'Este bairro está repetido na lista.']);
    }

    public function test_nome_e_numero_de_outra_prefeitura_bairro_sao_recusados(): void
    {
        $this->post('/retaguarda/prefeituras-bairro', $this->dados(['nome' => 'centro/brotas', 'numero' => 1]))
            ->assertSessionHasErrors([
                'nome' => 'Já existe uma Prefeitura-Bairro com este nome.',
                'numero' => 'Já existe uma Prefeitura-Bairro com este número.',
            ]);
    }

    public function test_campos_obrigatorios(): void
    {
        $this->post('/retaguarda/prefeituras-bairro', ['numero' => '', 'nome' => '', 'bairros' => [['id' => null, 'nome' => '']]])
            ->assertSessionHasErrors(['numero', 'nome', 'bairros.0.nome' => 'Informe o nome do bairro ou remova a linha.']);
    }

    public function test_salvar_a_propria_prefeitura_bairro_sem_mudancas_passa(): void
    {
        $valeria = $this->prefeitura('valeria');

        $this->put("/retaguarda/prefeituras-bairro/{$valeria->id}", [
            'numero' => 10,
            'nome' => 'VALÉRIA',
            'bairros' => $valeria->bairros()->get(['id', 'nome'])->map(fn (Bairro $b): array => ['id' => $b->id, 'nome' => $b->nome])->all(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(4, $valeria->bairros()->count());
    }

    public function test_desativa_e_reativa(): void
    {
        $valeria = $this->prefeitura('valeria');

        $this->patch("/retaguarda/prefeituras-bairro/{$valeria->id}/ativo")->assertSessionHas('sucesso', 'Prefeitura-Bairro desativada.');
        $this->assertFalse($valeria->fresh()->ativo);

        $this->patch("/retaguarda/prefeituras-bairro/{$valeria->id}/ativo")->assertSessionHas('sucesso', 'Prefeitura-Bairro ativada.');
        $this->assertTrue($valeria->fresh()->ativo);
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $this->actingAs(User::factory()->create());
        $valeria = $this->prefeitura('valeria');

        $this->get('/retaguarda/prefeituras-bairro')->assertRedirect('/retaguarda/login');
        $this->post('/retaguarda/prefeituras-bairro', $this->dados())->assertRedirect('/retaguarda/login');
        $this->patch("/retaguarda/prefeituras-bairro/{$valeria->id}/ativo")->assertRedirect('/retaguarda/login');

        $this->assertTrue($valeria->fresh()->ativo);
        $this->assertSame(10, PrefeituraBairro::count());
    }

    public function test_menu_tem_o_grupo_parametrizacao_com_prefeituras_bairro(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString("'Parametrização'", $menu);
        $this->assertStringContainsString(route('retaguarda.prefeituras-bairro.index', absolute: false), $menu);
        $this->assertStringContainsString("'Prefeituras-Bairro'", $menu);
    }
}
