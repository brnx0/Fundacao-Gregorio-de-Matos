<?php

namespace Tests\Feature;

use App\Auth\GovBr\GovBrClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Visita Inertia entre a retaguarda e o resto do site vira carregamento completo (HandleInertiaRequests::version),
 * para o VLibras e a CSP dele, decididos no carregamento, nunca chegarem à retaguarda.
 */
class TrocaDeAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_visita_do_site_para_a_retaguarda_recarrega_a_pagina(): void
    {
        $versaoDoSite = $this->versaoDa('/privacidade');
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->get('/retaguarda/editais', $this->visitaInertia($versaoDoSite));

        $resposta->assertConflict()->assertHeader('X-Inertia-Location', url('/retaguarda/editais'));
    }

    public function test_visita_da_retaguarda_para_o_site_recarrega_a_pagina(): void
    {
        $versaoDaRetaguarda = $this->versaoDa('/retaguarda/login');

        $resposta = $this->get('/privacidade', $this->visitaInertia($versaoDaRetaguarda));

        $resposta->assertConflict()->assertHeader('X-Inertia-Location', url('/privacidade'));
    }

    public function test_visita_dentro_da_mesma_area_segue_pelo_inertia(): void
    {
        $versaoDoSite = $this->versaoDa('/privacidade');
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
        $versaoDaRetaguarda = $this->versaoDa('/retaguarda/editais');

        $this->get('/a-fgm', $this->visitaInertia($versaoDoSite))->assertOk()->assertHeader('X-Inertia', 'true');
        $this->get('/retaguarda/eventos', $this->visitaInertia($versaoDaRetaguarda))->assertOk()->assertHeader('X-Inertia', 'true');
    }

    public function test_gestor_desativado_que_volta_para_a_retaguarda_vai_ao_login_sem_aviso(): void
    {
        $versaoDoSite = $this->versaoDa('/privacidade');
        $gestor = User::factory()->gestor()->create();
        $this->actingAs($gestor, 'retaguarda')->get('/retaguarda/editais')->assertOk();
        $gestor->update(['ativo' => false]);

        $resposta = $this->get('/retaguarda/editais', $this->visitaInertia($versaoDoSite));

        $resposta->assertConflict()->assertHeader('X-Inertia-Location', url('/retaguarda/login'));
        $this->assertGuest('retaguarda');
        $this->get('/retaguarda/login')
            ->assertInertia(fn (Assert $p) => $p->where('flash.erro', null));
    }

    public function test_proponente_do_govbr_que_volta_da_retaguarda_para_a_home_sai_tambem_do_govbr(): void
    {
        config(['govbr.driver' => 'govbr']);
        $versaoDaRetaguarda = $this->versaoDa('/retaguarda/login');
        $this->actingAs(User::factory()->create())->withSession(['portal.via_govbr' => true]);

        $resposta = $this->get('/', $this->visitaInertia($versaoDaRetaguarda));

        $resposta->assertConflict()->assertHeader('X-Inertia-Location', app(GovBrClient::class)->logoutUrl());
        $this->assertGuest();
    }

    public function test_proponente_que_volta_da_retaguarda_para_a_home_tem_o_historico_limpo(): void
    {
        $versaoDaRetaguarda = $this->versaoDa('/retaguarda/login');
        $this->actingAs(User::factory()->create());
        $this->get('/', $this->visitaInertia($versaoDaRetaguarda))->assertConflict();
        $this->assertGuest();

        $resposta = $this->get('/');

        $this->assertTrue($resposta->viewData('page')['clearHistory']);
    }

    /** Versão que a página carregada informa ao Inertia (ele a repete no X-Inertia-Version). */
    private function versaoDa(string $url): string
    {
        return (string) $this->get($url)->viewData('page')['version'];
    }

    /**
     * @return array<string, string>
     */
    private function visitaInertia(string $versao): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => $versao];
    }
}
