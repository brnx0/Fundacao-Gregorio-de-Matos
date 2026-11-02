<?php

namespace Tests\Feature;

use App\Actions\LoginGovBrUser;
use App\Auth\GovBr\GovBrClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesGovBr;
use Tests\TestCase;

class GovBrLoginTest extends TestCase
{
    use FakesGovBr;
    use RefreshDatabase;

    private const ERRO = 'Não foi possível entrar com o gov.br. Tente novamente.';

    public function test_fluxo_completo(): void
    {
        $r = $this->get('/auth/govbr/redirect');
        $r->assertRedirectContains('https://sso.staging.acesso.gov.br/authorize');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))->assertRedirect('/cadastro');

        $this->assertAuthenticated();
        $this->assertSame('52998224725', auth()->user()->cpf);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/token') && $req['code_verifier'] !== null);
    }

    public function test_redirect_guarda_state_nonce_e_verifier_na_sessao(): void
    {
        $r = $this->get('/auth/govbr/redirect');
        parse_str(parse_url($r->headers->get('Location'), PHP_URL_QUERY), $q);

        $this->assertSame(40, strlen(session('govbr.state')));
        $this->assertSame(40, strlen(session('govbr.nonce')));
        $this->assertSame(64, strlen(session('govbr.verifier')));
        $this->assertSame(session('govbr.state'), $q['state']);
        $this->assertSame(session('govbr.nonce'), $q['nonce']);
    }

    public function test_usuario_cancelou_no_govbr(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->get('/auth/govbr/callback?error=access_denied&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_state_invalido_ou_reusado(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->get('/auth/govbr/callback?code=abc&state=errado')
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();

        // Fluxo válido consome o state; repetir o mesmo callback falha.
        $this->get('/auth/govbr/redirect');
        $state = session('govbr.state');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->get('/auth/govbr/callback?code=abc&state='.$state)->assertRedirect('/cadastro');
        auth()->logout();

        $this->get('/auth/govbr/callback?code=abc&state='.$state)
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_retorno_repetido_com_usuario_ja_logado_nao_mostra_erro(): void
    {
        $this->get('/auth/govbr/redirect');
        $state = session('govbr.state');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->get('/auth/govbr/callback?code=abc&state='.$state)->assertRedirect('/cadastro');

        $this->get('/auth/govbr/callback?code=abc&state='.$state)
            ->assertRedirect('/cadastro')->assertSessionMissing('erro');
        $this->assertAuthenticated();
    }

    public function test_cpf_de_gestor_e_recusado_no_portal_sem_entrar(): void
    {
        $gestor = User::factory()->gestor()->create(['cpf' => '52998224725']);
        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', LoginGovBrUser::GESTOR_RECUSADO);

        $this->assertGuest();
        $this->assertNull($gestor->fresh()->last_login_at);
    }

    public function test_falha_com_gestor_logado_volta_a_home_e_nao_leva_a_retaguarda(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $this->get('/auth/govbr/callback?code=abc&state=')
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
    }

    public function test_callback_sem_fluxo_iniciado_falha(): void
    {
        $this->get('/auth/govbr/callback?code=abc&state=')
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_sub_invalido_nao_loga(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint(['sub' => '11111111111'] + $this->claimsValidos(session('govbr.nonce')));

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_nonce_errado_nao_loga(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos('outro-nonce'));

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_token_endpoint_com_erro_nao_loga(): void
    {
        $this->get('/auth/govbr/redirect');
        Http::fake([self::GOVBR_BASE.'/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_falha_de_conexao_no_token_nao_gera_500(): void
    {
        $this->get('/auth/govbr/redirect');
        Http::fake([self::GOVBR_BASE.'/token' => Http::failedConnection()]);

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_jwks_com_erro_500_nao_gera_500(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->govbrJwksStatus = 500;

        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))
            ->assertRedirect('/')->assertSessionHas('erro', self::ERRO);
        $this->assertGuest();
    }

    public function test_limite_e_por_rota(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/auth/govbr/callback?code=abc&state=errado')->assertRedirect('/');
        }
        $this->get('/auth/govbr/callback?code=abc&state=errado')
            ->assertRedirect('/')
            ->assertSessionHas('erro', 'Muitas tentativas. Aguarde um minuto e tente novamente.');
        $this->getJson('/auth/govbr/callback?code=abc&state=errado')->assertTooManyRequests();

        // O limite esgotado do callback não bloqueia o início do fluxo.
        $this->get('/auth/govbr/redirect')->assertRedirectContains(self::GOVBR_BASE.'/authorize');
    }

    public function test_logout_redireciona_para_govbr(): void
    {
        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'));
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(app(GovBrClient::class)->logoutUrl());
        $this->assertGuest();
    }

    public function test_sair_da_retaguarda_de_quem_entrou_nela_com_govbr_vai_ao_govbr(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')
            ->withSession(['retaguarda.via_govbr' => true]);

        $this->post('/retaguarda/sair')->assertRedirect(app(GovBrClient::class)->logoutUrl());
        $this->assertGuest('retaguarda');
    }

    public function test_logout_de_quem_entrou_com_senha_sai_so_do_portal(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_faz_a_tela_seguinte_limpar_o_historico_do_navegador(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $this->post('/logout');
        $resposta = $this->get('/');

        $this->assertTrue($resposta->viewData('page')['clearHistory']);
    }

    public function test_retorno_do_logout_govbr_vai_para_inicio(): void
    {
        $this->get('/auth/govbr/logout')->assertRedirect('/');
    }

    public function test_nivel_confiabilidade_nao_bloqueia(): void
    {
        config(['govbr.trust_level_url' => 'https://api.staging.acesso.gov.br/niveis/{cpf}']);
        Http::fake(['https://api.staging.acesso.gov.br/*' => Http::response('falha', 500)]);

        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))->assertRedirect('/cadastro');

        $this->assertAuthenticated();
        $this->assertNull(auth()->user()->fresh()->govbr_trust_level);
        Http::assertSent(fn ($req) => $req->url() === 'https://api.staging.acesso.gov.br/niveis/52998224725'
            && $req->hasHeader('Authorization', 'Bearer access-token-teste'));
    }

    public function test_nivel_confiabilidade_gravado_quando_disponivel(): void
    {
        config(['govbr.trust_level_url' => 'https://api.staging.acesso.gov.br/niveis/{cpf}']);
        Http::fake(['https://api.staging.acesso.gov.br/*' => Http::response([['id' => '1'], ['id' => '3'], ['id' => '2']])]);

        $this->get('/auth/govbr/redirect');
        $this->fakeTokenEndpoint($this->claimsValidos(session('govbr.nonce')));
        $this->get('/auth/govbr/callback?code=abc&state='.session('govbr.state'))->assertRedirect('/cadastro');

        $this->assertSame('3', auth()->user()->fresh()->govbr_trust_level);
    }
}
