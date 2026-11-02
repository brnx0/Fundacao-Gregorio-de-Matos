<?php

namespace Tests\Feature;

use App\Auth\GovBr\GovBrClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EncerrarSessaoDoProponenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_proponente_que_volta_para_a_home_sai_da_sessao_e_ve_o_portal_deslogado(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get('/');

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Home')
            ->where('auth.user', null));
        $this->assertTrue($resposta->viewData('page')['clearHistory']);
        $this->assertGuest();
    }

    public function test_proponente_que_entrou_pelo_govbr_sai_tambem_do_govbr_ao_voltar_para_a_home(): void
    {
        config(['govbr.driver' => 'govbr']);
        $this->actingAs(User::factory()->create())
            ->withSession(['portal.via_govbr' => true]);

        $resposta = $this->get('/');

        $resposta->assertRedirect(app(GovBrClient::class)->logoutUrl());
        $this->assertGuest();
    }

    public function test_gestor_continua_logado_na_retaguarda_e_a_home_o_mostra_como_visitante(): void
    {
        $gestor = User::factory()->gestor()->create();
        $this->actingAs($gestor, 'retaguarda');

        $resposta = $this->get('/');

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user', null));
        $this->assertAuthenticatedAs($gestor, 'retaguarda');
    }

    public function test_telas_do_cadastro_ficam_cifradas_no_historico_do_navegador(): void
    {
        $this->actingAs(User::factory()->create());

        $resposta = $this->get('/cadastro');

        $resposta->assertOk();
        $this->assertTrue($resposta->viewData('page')['encryptHistory']);
    }
}
