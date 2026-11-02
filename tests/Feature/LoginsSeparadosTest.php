<?php

namespace Tests\Feature;

use App\Models\Avaliador;
use App\Models\User;
use App\Support\Avaliacao\AvaliadorDaSessao;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Portal e retaguarda são aplicações separadas: cada uma tem o seu login (guards 'web' e 'retaguarda') na mesma
 * sessão do navegador, e entrar ou sair de uma não mexe na outra.
 */
class LoginsSeparadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrar_na_retaguarda_mantem_o_login_do_portal(): void
    {
        $proponente = User::factory()->create();
        $gestor = User::factory()->gestor()->create();
        Auth::guard('web')->login($proponente);

        $this->post('/retaguarda/login', ['email' => $gestor->email, 'senha' => '123456'])->assertRedirect('/retaguarda');

        $this->novaRequisicao();
        $this->assertAuthenticatedAs($proponente, 'web');
        $this->assertAuthenticatedAs($gestor, 'retaguarda');
    }

    public function test_entrar_no_portal_pelo_govbr_mantem_o_login_da_retaguarda(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app['env'] = 'local';
        config(['govbr.driver' => 'fake']);
        $gestor = User::factory()->gestor()->create();
        Auth::guard('retaguarda')->login($gestor);

        $this->post('/auth/govbr/fake', ['cpf' => '529.982.247-25'])->assertRedirect('/cadastro');

        $this->novaRequisicao();
        $this->assertAuthenticatedAs(User::where('cpf', '52998224725')->firstOrFail(), 'web');
        $this->assertAuthenticatedAs($gestor, 'retaguarda');
    }

    public function test_sair_da_retaguarda_mantem_o_login_do_portal(): void
    {
        $proponente = User::factory()->create();
        Auth::guard('web')->login($proponente);
        Auth::guard('retaguarda')->login(User::factory()->gestor()->create());

        $this->post('/retaguarda/sair')->assertRedirect('/retaguarda/login');

        $this->novaRequisicao();
        $this->assertGuest('retaguarda');
        $this->assertAuthenticatedAs($proponente, 'web');
    }

    public function test_sair_do_portal_mantem_o_login_da_retaguarda(): void
    {
        $gestor = User::factory()->gestor()->create();
        Auth::guard('web')->login(User::factory()->create());
        Auth::guard('retaguarda')->login($gestor);

        $this->post('/logout')->assertRedirect('/');

        $this->novaRequisicao();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($gestor, 'retaguarda');
    }

    public function test_home_encerra_so_o_login_do_portal(): void
    {
        $gestor = User::factory()->gestor()->create();
        Auth::guard('web')->login(User::factory()->create());
        Auth::guard('retaguarda')->login($gestor);

        $this->get('/')->assertOk();

        $this->novaRequisicao();
        $this->assertGuest('web');
        $this->assertAuthenticatedAs($gestor, 'retaguarda');
    }

    public function test_sair_do_portal_nao_sai_do_govbr_de_quem_entrou_pela_retaguarda(): void
    {
        config(['govbr.driver' => 'govbr']);
        Auth::guard('web')->login(User::factory()->create());
        Auth::guard('retaguarda')->login(User::factory()->gestor()->create());
        $this->withSession(['retaguarda.via_govbr' => true]);

        $resposta = $this->post('/logout');

        $resposta->assertRedirect('/')->assertSessionHas('retaguarda.via_govbr', true);
    }

    public function test_sair_do_portal_mantem_o_acesso_do_avaliador(): void
    {
        $avaliador = Avaliador::factory()->create();
        Auth::guard('web')->login(User::factory()->create());
        $this->withSession([AvaliadorDaSessao::SESSAO_AVALIADOR => $avaliador->id]);

        $resposta = $this->post('/logout');

        $resposta->assertRedirect('/')->assertSessionHas(AvaliadorDaSessao::SESSAO_AVALIADOR, $avaliador->id);
    }

    public function test_sair_sem_outra_area_aberta_zera_a_sessao(): void
    {
        Auth::guard('web')->login(User::factory()->create());
        $this->withSession(['url.intended' => '/cadastro']);

        $resposta = $this->post('/logout');

        $resposta->assertRedirect('/')->assertSessionMissing('url.intended');
    }

    public function test_portal_mostra_o_proponente_mesmo_com_a_retaguarda_logada(): void
    {
        Auth::guard('web')->login(User::factory()->create(['name' => 'Maria do Portal']));
        Auth::guard('retaguarda')->login(User::factory()->gestor()->create(['name' => 'Ana da Retaguarda']));

        $resposta = $this->get('/privacidade');

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.nome', 'Maria do Portal'));
    }

    public function test_retaguarda_mostra_o_gestor_mesmo_com_o_portal_logado(): void
    {
        Auth::guard('web')->login(User::factory()->create(['name' => 'Maria do Portal']));
        Auth::guard('retaguarda')->login(User::factory()->gestor()->create(['name' => 'Ana da Retaguarda']));

        $resposta = $this->get('/retaguarda/editais');

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.nome', 'Ana da Retaguarda'));
    }

    /**
     * Como numa requisição nova: os logins são relidos da sessão e o login padrão volta a ser o do portal. No teste
     * o app é o mesmo entre requisições, e o auth:retaguarda de uma deixaria o padrão trocado para a seguinte.
     */
    private function novaRequisicao(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
    }
}
