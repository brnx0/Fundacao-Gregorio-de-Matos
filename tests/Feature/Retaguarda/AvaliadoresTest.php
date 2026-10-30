<?php

namespace Tests\Feature\Retaguarda;

use App\Models\Avaliador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AvaliadoresTest extends TestCase
{
    use RefreshDatabase;

    private const string CPF = '52998224725';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());
    }

    /** @return array<string, string> */
    private function dados(array $extra = []): array
    {
        return [
            'nome' => 'Maria da Silva',
            'data_nascimento' => '1980-05-17',
            'cpf' => '529.982.247-25',
            'email' => ' Maria@Exemplo.com.br ',
            ...$extra,
        ];
    }

    public function test_cria_avaliador_ativo_com_cpf_so_digitos_e_email_em_minusculas(): void
    {
        $this->post('/retaguarda/avaliadores', $this->dados())
            ->assertRedirect('/retaguarda/avaliadores')
            ->assertSessionHas('sucesso', 'Avaliador salvo.');

        $avaliador = Avaliador::sole();
        $this->assertSame('Maria da Silva', $avaliador->nome);
        $this->assertSame('1980-05-17', $avaliador->data_nascimento->toDateString());
        $this->assertSame(self::CPF, $avaliador->cpf);
        $this->assertSame('maria@exemplo.com.br', $avaliador->email);
        $this->assertTrue($avaliador->ativo);
    }

    public function test_lista_por_nome_com_cpf_mascarado(): void
    {
        Avaliador::factory()->create(['nome' => 'Bruno', 'cpf' => self::CPF]);
        Avaliador::factory()->create(['nome' => 'Ana']);

        $this->get('/retaguarda/avaliadores')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Avaliadores/Index')
            ->where('avaliadores.total', 2)
            ->where('avaliadores.data.0.nome', 'Ana')
            ->where('avaliadores.data.1.cpf', '***.982.247-**'));
    }

    public function test_busca_por_nome_email_ou_cpf_completo(): void
    {
        Avaliador::factory()->create(['nome' => 'Bruno Lima', 'email' => 'bruno@exemplo.com', 'cpf' => self::CPF]);
        Avaliador::factory()->create(['nome' => 'Ana Souza', 'email' => 'ana@exemplo.com']);

        $this->get('/retaguarda/avaliadores?busca=LIMA')->assertInertia(fn (Assert $p) => $p->where('avaliadores.total', 1));
        $this->get('/retaguarda/avaliadores?busca=ana@')->assertInertia(fn (Assert $p) => $p->where('avaliadores.total', 1));
        $this->get('/retaguarda/avaliadores?busca=529.982.247-25')->assertInertia(fn (Assert $p) => $p
            ->where('avaliadores.total', 1)
            ->where('avaliadores.data.0.nome', 'Bruno Lima'));
    }

    public function test_edicao_traz_o_avaliador_e_atualiza(): void
    {
        $avaliador = Avaliador::factory()->create(['cpf' => self::CPF, 'data_nascimento' => '1975-01-02']);

        $this->get("/retaguarda/avaliadores/{$avaliador->id}/edit")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Avaliadores/Form')
            ->where('avaliador.cpf', '529.982.247-25')
            ->where('avaliador.data_nascimento', '1975-01-02'));

        $this->put("/retaguarda/avaliadores/{$avaliador->id}", $this->dados(['nome' => 'Maria Souza']))->assertSessionHasNoErrors();

        $this->assertSame('Maria Souza', $avaliador->fresh()->nome);
    }

    public function test_cpf_e_email_repetidos_sao_recusados_mas_o_proprio_passa(): void
    {
        $existente = Avaliador::factory()->create(['cpf' => self::CPF, 'email' => 'maria@exemplo.com.br']);

        $this->post('/retaguarda/avaliadores', $this->dados())->assertSessionHasErrors([
            'cpf' => 'Este CPF já está cadastrado como avaliador.',
            'email' => 'Este e-mail já está cadastrado como avaliador.',
        ]);
        $this->assertSame(1, Avaliador::count());

        $this->put("/retaguarda/avaliadores/{$existente->id}", $this->dados())->assertSessionHasNoErrors();
    }

    public function test_campos_obrigatorios_e_validos(): void
    {
        $this->post('/retaguarda/avaliadores', ['nome' => '', 'data_nascimento' => '', 'cpf' => '', 'email' => ''])
            ->assertSessionHasErrors(['nome', 'data_nascimento', 'cpf', 'email']);

        $this->post('/retaguarda/avaliadores', $this->dados([
            'nome' => str_repeat('ç', 130),
            'data_nascimento' => now()->toDateString(),
            'cpf' => '111.111.111-11',
            'email' => 'sem-arroba',
        ]))->assertSessionHasErrors([
            'nome',
            'data_nascimento' => 'A data de nascimento precisa ser anterior a hoje.',
            'cpf' => 'Digite um CPF válido.',
            'email' => 'Digite um e-mail válido.',
        ]);

        $this->assertSame(0, Avaliador::count());
    }

    public function test_desativa_e_reativa(): void
    {
        $avaliador = Avaliador::factory()->create();

        $this->patch("/retaguarda/avaliadores/{$avaliador->id}/ativo")->assertSessionHas('sucesso', 'Avaliador desativado.');
        $this->assertFalse($avaliador->fresh()->ativo);

        $this->patch("/retaguarda/avaliadores/{$avaliador->id}/ativo")->assertSessionHas('sucesso', 'Avaliador ativado.');
        $this->assertTrue($avaliador->fresh()->ativo);
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $this->actingAs(User::factory()->create());
        $avaliador = Avaliador::factory()->create();

        $this->get('/retaguarda/avaliadores')->assertRedirect('/retaguarda/login');
        $this->post('/retaguarda/avaliadores', $this->dados())->assertRedirect('/retaguarda/login');
        $this->patch("/retaguarda/avaliadores/{$avaliador->id}/ativo")->assertRedirect('/retaguarda/login');

        $this->assertTrue($avaliador->fresh()->ativo);
        $this->assertSame(1, Avaliador::count());
    }

    public function test_menu_de_cadastro_tem_avaliadores(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.avaliadores.index', absolute: false), $menu);
        $this->assertStringContainsString("'Avaliadores'", $menu);
    }
}
