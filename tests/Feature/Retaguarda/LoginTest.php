<?php

namespace Tests\Feature\Retaguarda;

use App\Models\User;
use Database\Seeders\PrimeiroGestorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_tela_de_login_abre(): void
    {
        $this->get('/retaguarda/login')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Retaguarda/Login'));
    }

    public function test_entra_com_email_e_senha(): void
    {
        $gestor = User::factory()->gestor()->create();

        $this->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => '123456'])
            ->assertRedirect('/retaguarda');

        $this->assertAuthenticatedAs($gestor, 'retaguarda');
        $this->assertNotNull($gestor->fresh()->last_login_at);
    }

    public function test_email_com_maiusculas_e_espacos_entra(): void
    {
        $this->seed(PrimeiroGestorSeeder::class);

        $this->post('/retaguarda/login', ['email' => '  Admin@Admin ', 'senha' => '123456'])
            ->assertRedirect('/retaguarda');

        $this->assertAuthenticatedAs(User::where('email', 'admin@admin')->firstOrFail(), 'retaguarda');
    }

    public function test_senha_errada_mensagem_generica(): void
    {
        $gestor = User::factory()->gestor()->create();

        $this->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => 'errada'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->assertGuest('retaguarda');
    }

    public function test_usuario_sem_papel_nao_entra(): void
    {
        $proponente = User::factory()->create(['password' => '123456']);

        $this->post('/retaguarda/login', ['email' => $proponente->email, 'senha' => '123456'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->assertGuest('retaguarda');
    }

    public function test_gestor_inativo_nao_entra(): void
    {
        $gestor = User::factory()->gestor()->create(['ativo' => false]);

        $this->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => '123456'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.']);

        $this->assertGuest('retaguarda');
    }

    public function test_limite_de_tentativas(): void
    {
        $gestor = User::factory()->gestor()->create();

        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $this->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => 'errada'])
                ->assertSessionHasErrors('email');
        }

        $this->from('/retaguarda/login')
            ->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => '123456'])
            ->assertRedirect('/retaguarda/login')
            ->assertSessionHasErrors(['email' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);

        $this->assertGuest('retaguarda');
    }

    public function test_limite_de_tentativas_em_json_continua_429(): void
    {
        $gestor = User::factory()->gestor()->create();

        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $this->postJson('/retaguarda/login', ['email' => $gestor->email, 'senha' => 'errada'])
                ->assertUnprocessable();
        }

        $this->postJson('/retaguarda/login', ['email' => $gestor->email, 'senha' => '123456'])
            ->assertTooManyRequests();
    }

    public function test_mensagens_de_validacao_em_portugues(): void
    {
        $this->from('/retaguarda/login')
            ->post('/retaguarda/login', [])
            ->assertRedirect('/retaguarda/login')
            ->assertSessionHasErrors([
                'email' => 'O campo e-mail é obrigatório.',
                'senha' => 'O campo senha é obrigatório.',
            ]);

        $this->post('/retaguarda/login', ['email' => 'nao-e-email', 'senha' => 'x'])
            ->assertSessionHasErrors(['email' => 'O campo e-mail deve ser um endereço de e-mail válido.']);
    }

    public function test_gestor_logado_que_abre_o_login_vai_para_o_inicio(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')
            ->get('/retaguarda/login')
            ->assertRedirect('/retaguarda');
    }

    public function test_gestor_inativo_logado_ve_a_tela_de_login(): void
    {
        $this->actingAs(User::factory()->gestor()->create(['ativo' => false]), 'retaguarda')
            ->get('/retaguarda/login')
            ->assertOk();
    }

    public function test_visitante_vai_para_login(): void
    {
        $this->get('/retaguarda')->assertRedirect('/retaguarda/login');
    }

    public function test_visitante_do_portal_continua_indo_para_a_home(): void
    {
        $this->get('/cadastro')->assertRedirect('/');
    }

    public function test_proponente_logado_no_portal_vai_ao_login_sem_aviso_e_continua_no_portal(): void
    {
        $proponente = User::factory()->create();

        $this->actingAs($proponente)
            ->get('/retaguarda')
            ->assertRedirect('/retaguarda/login')
            ->assertSessionMissing('erro');

        $this->assertGuest('retaguarda');
        $this->assertAuthenticatedAs($proponente);
    }

    public function test_gestor_desativado_com_sessao_aberta_sai_da_retaguarda_sem_aviso(): void
    {
        $gestor = User::factory()->gestor()->create();
        $this->actingAs($gestor, 'retaguarda')->get('/retaguarda/editais')->assertOk();

        $gestor->update(['ativo' => false]);

        $this->get('/retaguarda/editais')
            ->assertRedirect('/retaguarda/login')
            ->assertSessionMissing('erro');

        $this->assertGuest('retaguarda');
    }

    public function test_gestor_ve_a_retaguarda_como_usuario_logado(): void
    {
        $gestor = User::factory()->gestor()->create(['name' => 'Gestora da Retaguarda']);

        $this->actingAs($gestor, 'retaguarda')
            ->get('/retaguarda/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Editais/Index')
                ->where('auth.user.nome', 'Gestora da Retaguarda'));
    }

    public function test_sair_encerra_sessao(): void
    {
        config(['govbr.driver' => 'fake']);
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor, 'retaguarda')
            ->post('/retaguarda/sair')
            ->assertRedirect('/retaguarda/login');

        $this->assertGuest('retaguarda');
    }

    public function test_sair_limpa_o_historico_para_as_telas_de_proponentes_nao_reabrirem(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->followingRedirects()->post('/retaguarda/sair');

        $resposta->assertInertia(fn (Assert $p) => $p->component('Retaguarda/Login'));
        $this->assertTrue($resposta->viewData('page')['clearHistory']);
    }
}
