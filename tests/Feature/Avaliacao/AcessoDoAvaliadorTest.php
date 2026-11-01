<?php

namespace Tests\Feature\Avaliacao;

use App\Http\Controllers\Avaliacao\AcessoDoAvaliadorController;
use App\Http\Middleware\GarantirAvaliador;
use App\Models\Avaliador;
use App\Models\User;
use App\Support\Cpf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Avaliador sem conta de usuário: entra pelo link pessoal do e-mail e confirma o CPF. */
class AcessoDoAvaliadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_e_cpf_certos_entram(): void
    {
        $avaliador = Avaliador::factory()->create(['nome' => 'Ana Avaliadora']);
        $codigo = $avaliador->novoAcesso();

        $this->get("/avaliacao/acesso/{$codigo}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Avaliacao/Acesso')->where('avaliador', null));
        $sessaoAntes = session()->getId();

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])
            ->assertRedirect('/avaliacao');
        // Sessão nova ao entrar (contra fixação de sessão).
        $this->assertNotSame($sessaoAntes, session()->getId());

        $this->get('/avaliacao')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Avaliacao/Inicio')->where('avaliador.nome', 'Ana Avaliadora'));
    }

    public function test_link_de_acesso_leva_a_pagina_do_codigo(): void
    {
        $avaliador = Avaliador::factory()->create();
        $this->assertNull($avaliador->linkDeAcesso());

        $codigo = $avaliador->novoAcesso();

        $this->assertSame(url("/avaliacao/acesso/{$codigo}"), $avaliador->fresh()->linkDeAcesso());
        $this->assertSame((int) $avaliador->id, (int) Avaliador::porCodigo($codigo)?->id);
    }

    public function test_cpf_com_pontuacao_tambem_entra(): void
    {
        $avaliador = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => Cpf::formatar($avaliador->cpf)])
            ->assertRedirect('/avaliacao');

        $this->assertSame((int) $avaliador->id, (int) session('avaliacao.avaliador'));
    }

    public function test_cpf_errado_nao_entra(): void
    {
        $avaliador = Avaliador::factory()->create();
        $outro = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $outro->cpf])
            ->assertRedirect("/avaliacao/acesso/{$codigo}")
            ->assertSessionHasErrors(['cpf' => AcessoDoAvaliadorController::ERRO_CPF]);

        $this->assertNull(session('avaliacao.avaliador'));
        $this->assertNull(session('avaliacao.acesso'));
    }

    public function test_cpf_invalido_recebe_a_mensagem_de_cpf_invalido(): void
    {
        $codigo = Avaliador::factory()->create()->novoAcesso();

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => '111.111.111-11'])
            ->assertRedirect("/avaliacao/acesso/{$codigo}")
            ->assertSessionHasErrors(['cpf' => 'CPF inválido. Confira os números.']);

        $this->assertNull(session('avaliacao.avaliador'));
    }

    public function test_sem_link_nao_entra(): void
    {
        $this->get('/avaliacao')->assertRedirect('/');
    }

    public function test_codigo_desconhecido_da_404(): void
    {
        $avaliador = Avaliador::factory()->create();
        $avaliador->novoAcesso();
        $desconhecido = str_repeat('a', 48);

        $this->get("/avaliacao/acesso/{$desconhecido}")->assertNotFound();
        $this->post("/avaliacao/acesso/{$desconhecido}", ['cpf' => $avaliador->cpf])->assertNotFound();
        $this->get('/avaliacao/acesso/curto')->assertNotFound();
    }

    public function test_avaliador_inativo_nao_abre_o_link(): void
    {
        $avaliador = Avaliador::factory()->inativo()->create();
        $codigo = $avaliador->novoAcesso();

        $this->get("/avaliacao/acesso/{$codigo}")->assertNotFound();
        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])->assertNotFound();
    }

    public function test_avaliador_desativado_perde_a_sessao(): void
    {
        $avaliador = Avaliador::factory()->create();
        $this->entrar($avaliador);

        $avaliador->update(['ativo' => false]);

        $this->get('/avaliacao')
            ->assertRedirect('/')
            ->assertSessionHas('erro', GarantirAvaliador::ERRO_SEM_ACESSO);

        $this->assertNull(session('avaliacao.avaliador'));
        $this->assertNull(session('avaliacao.acesso'));
    }

    public function test_novo_link_derruba_a_sessao_e_invalida_o_antigo(): void
    {
        $avaliador = Avaliador::factory()->create();
        $codigoAntigo = $this->entrar($avaliador);

        $avaliador->novoAcesso();

        $this->get('/avaliacao')
            ->assertRedirect('/')
            ->assertSessionHas('erro', GarantirAvaliador::ERRO_SEM_ACESSO);

        $this->get("/avaliacao/acesso/{$codigoAntigo}")->assertNotFound();
    }

    public function test_limite_de_tentativas_de_cpf(): void
    {
        $avaliador = Avaliador::factory()->create();
        $outro = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();

        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $outro->cpf])
                ->assertSessionHasErrors(['cpf' => AcessoDoAvaliadorController::ERRO_CPF]);
        }

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])
            ->assertRedirect("/avaliacao/acesso/{$codigo}")
            ->assertSessionHasErrors(['cpf' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);

        $this->assertNull(session('avaliacao.avaliador'));
    }

    public function test_sair_encerra_so_o_avaliador(): void
    {
        $proponente = User::factory()->create();
        $avaliador = Avaliador::factory()->create();
        $this->actingAs($proponente);
        $this->entrar($avaliador);

        $this->post('/avaliacao/sair')->assertRedirect('/');

        $this->assertNull(session('avaliacao.avaliador'));
        $this->assertNull(session('avaliacao.acesso'));
        $this->assertAuthenticatedAs($proponente);
        $this->get('/avaliacao')->assertRedirect('/');
    }

    public function test_codigo_nao_fica_na_sessao(): void
    {
        $avaliador = Avaliador::factory()->create();
        $codigo = $avaliador->novoAcesso();

        // A página do link não fica guardada como "página anterior" (o endereço traz o código).
        $this->get("/avaliacao/acesso/{$codigo}")->assertOk();
        $this->assertStringNotContainsString($codigo, serialize(session()->all()));

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => '529.982.247-25'])->assertSessionHasErrors('cpf');
        $this->assertStringNotContainsString($codigo, serialize(session()->all()));

        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])->assertRedirect('/avaliacao');
        $this->get('/avaliacao')->assertOk();

        $this->assertStringNotContainsString($codigo, serialize(session()->all()));
        $this->assertSame(hash('sha256', $codigo), session('avaliacao.acesso'));
    }

    public function test_portal_nao_mostra_o_avaliador(): void
    {
        $this->entrar(Avaliador::factory()->create());

        $this->get('/privacidade')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('avaliador', null));
    }

    /** Abre o link e confirma o CPF; devolve o código do link. */
    private function entrar(Avaliador $avaliador): string
    {
        $codigo = $avaliador->novoAcesso();

        $this->get("/avaliacao/acesso/{$codigo}")->assertOk();
        $this->post("/avaliacao/acesso/{$codigo}", ['cpf' => $avaliador->cpf])->assertRedirect('/avaliacao');

        return $codigo;
    }
}
