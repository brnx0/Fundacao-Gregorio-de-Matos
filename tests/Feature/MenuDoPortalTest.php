<?php

namespace Tests\Feature;

use App\Models\Edital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MenuDoPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_proponente_ve_so_os_editais_publicados_com_pdf(): void
    {
        Edital::factory()->create(['titulo' => 'Edital Publicado', 'slug' => 'publicado']);
        Edital::factory()->create(['titulo' => 'Rascunho da Retaguarda', 'publicado' => false]);
        Edital::factory()->semPdf()->create(['titulo' => 'Sem PDF']);

        $this->actingAs(User::factory()->proponente()->create())->get('/portal/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Editais')
                ->where('editais.total', 1)
                ->where('editais.data.0.titulo', 'Edital Publicado')
                ->where('editais.data.0.pdf', route('editais.pdf', 'publicado')));
    }

    public function test_editais_mostram_so_os_com_inscricoes_abertas(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Edital::factory()->create(['titulo' => 'Abre hoje', 'inscricoes_inicio' => '2026-11-10', 'inscricoes_fim' => '2026-11-30']);
        Edital::factory()->create(['titulo' => 'Fecha hoje', 'inscricoes_inicio' => '2026-11-01', 'inscricoes_fim' => '2026-11-10']);
        Edital::factory()->create(['titulo' => 'Abre amanhã', 'inscricoes_inicio' => '2026-11-11', 'inscricoes_fim' => '2026-11-30']);
        Edital::factory()->create(['titulo' => 'Fechou ontem', 'inscricoes_inicio' => '2026-10-01', 'inscricoes_fim' => '2026-11-09']);

        $this->actingAs(User::factory()->proponente()->create())->get('/portal/editais')
            ->assertInertia(fn (Assert $page) => $page
                ->where('editais.total', 2)
                ->where('editais.data.0.titulo', 'Abre hoje')
                ->where('editais.data.1.titulo', 'Fecha hoje'));
    }

    public function test_editais_trazem_a_foto_enviada_na_retaguarda(): void
    {
        Edital::factory()->create(['imagem' => 'editais/capa.jpg']);

        $this->actingAs(User::factory()->proponente()->create())->get('/portal/editais')
            ->assertInertia(fn (Assert $page) => $page
                ->where('editais.data.0.imagem', Storage::disk('public')->url('editais/capa.jpg')));
    }

    public function test_patrocinador_ve_a_tela_de_projetos(): void
    {
        $this->actingAs(User::factory()->patrocinador()->create())->get('/portal/projetos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Projetos'));
    }

    public function test_com_os_dois_tipos_ve_editais_e_projetos(): void
    {
        $user = User::factory()->proponenteEPatrocinador()->create();

        $this->actingAs($user)->get('/portal/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Editais'));
        $this->actingAs($user)->get('/portal/projetos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Projetos'));
    }

    public function test_cada_tipo_e_levado_a_propria_tela(): void
    {
        $this->actingAs(User::factory()->patrocinador()->create())->get('/portal/editais')
            ->assertRedirect(route('portal.projetos'));

        $this->actingAs(User::factory()->proponente()->create())->get('/portal/projetos')
            ->assertRedirect(route('portal.editais'));
    }

    public function test_sem_tipo_de_cadastro_volta_para_a_ultima_etapa(): void
    {
        $this->actingAs(User::factory()->cadastroCompleto()->create())->get('/portal/editais')
            ->assertRedirect(route('cadastro.tipo'))
            ->assertSessionHas('aviso', 'Finalize seu cadastro escolhendo o tipo de cadastro.');
    }

    public function test_telas_do_portal_exigem_login(): void
    {
        $this->get('/portal/editais')->assertRedirect('/');
        $this->get('/portal/projetos')->assertRedirect('/');
    }

    public function test_gestor_volta_a_home_e_nao_e_levado_a_retaguarda(): void
    {
        $this->actingAs(User::factory()->gestor()->create())->get('/portal/editais')->assertRedirect('/');
    }

    public function test_menu_tem_minhas_inscricoes(): void
    {
        $menu = file_get_contents(resource_path('js/Components/MenuPortal.vue'));

        $this->assertStringContainsString("'".route('portal.inscricoes.index', absolute: false)."'", $menu);
        $this->assertStringContainsString("'Minhas inscrições'", $menu);
    }
}
