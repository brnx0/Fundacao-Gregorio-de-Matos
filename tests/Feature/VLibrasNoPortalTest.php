<?php

namespace Tests\Feature;

use App\Enums\AvatarVLibras;
use App\Enums\LadoVLibras;
use App\Models\ConfiguracaoVLibras;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class VLibrasNoPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_carrega_o_vlibras_com_o_lado_e_o_avatar_salvos(): void
    {
        ConfiguracaoVLibras::factory()->create(['lado' => LadoVLibras::Esquerda, 'avatar' => AvatarVLibras::Hosana]);

        $resposta = $this->get('/');

        $resposta->assertOk()
            ->assertSee("avatar: 'hosana'", false)
            ->assertSee("position: 'L'", false);
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $resposta->headers->get('Content-Security-Policy'), $csp);
        preg_match('/<script nonce="([^"]+)">[^<]*vlibras-plugin\.js/', $resposta->getContent(), $script);
        $this->assertNotEmpty($csp[1] ?? null, 'CSP sem nonce');
        $this->assertSame($csp[1], $script[1] ?? null, 'Carregador do VLibras sem o nonce da CSP');
    }

    public function test_sem_configuracao_salva_abre_com_icaro_a_direita(): void
    {
        $resposta = $this->get('/');

        $resposta->assertOk()
            ->assertSee("avatar: 'icaro'", false)
            ->assertSee("position: 'R'", false);
    }

    public function test_csp_libera_so_os_dominios_do_vlibras_e_deixa_a_telemetria_bloqueada(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("#script-src 'self' 'nonce-[A-Za-z0-9+/=]+' https://vlibras\.gov\.br/app/ https://cdn\.jsdelivr\.net/gh/spbgovbr-vlibras/;#", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline';", $csp);
        $this->assertStringContainsString("img-src 'self' data: https://tile.openstreetmap.org https://vlibras.gov.br/app/ https://cdn.jsdelivr.net/gh/spbgovbr-vlibras/;", $csp);
        $this->assertStringContainsString("font-src 'self' data: https://vlibras.gov.br/app/ https://cdn.jsdelivr.net/gh/spbgovbr-vlibras/;", $csp);
        $this->assertStringContainsString("connect-src 'self' https://vlibras.gov.br/app/ https://traducao2.vlibras.gov.br https://dicionario2.vlibras.gov.br https://repositorio.vlibras.gov.br https://pt.wiktionary.org;", $csp);
        $this->assertStringContainsString('frame-src https://www.youtube-nocookie.com https://vlibras.gov.br/app/;', $csp);
        $this->assertStringNotContainsString('posthog', $csp);
    }

    public function test_desligado_fica_fora_da_pagina_e_da_csp(): void
    {
        ConfiguracaoVLibras::factory()->desligado()->create();

        $resposta = $this->get('/');

        $resposta->assertOk();
        $this->assertSemVLibras($resposta);
    }

    public function test_login_da_retaguarda_fica_sem_vlibras(): void
    {
        $resposta = $this->get('/retaguarda/login');

        $resposta->assertOk();
        $this->assertSemVLibras($resposta);
    }

    public function test_telas_da_retaguarda_ficam_sem_vlibras(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->get('/retaguarda/editais');

        $resposta->assertOk();
        $this->assertSemVLibras($resposta);
    }

    private function assertSemVLibras(TestResponse $resposta): void
    {
        $resposta->assertDontSee('vlibras-plugin.js', false);
        $this->assertStringNotContainsString('vlibras', $resposta->headers->get('Content-Security-Policy'));
    }
}
