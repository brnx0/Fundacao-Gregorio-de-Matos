<?php

namespace Tests\Feature;

use App\Enums\TipoCadastro;
use App\Http\Controllers\CadastroAnexoController;
use App\Models\PessoaFisica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TipoCadastroTest extends TestCase
{
    use RefreshDatabase;

    public function test_etapa_mostra_as_opcoes_e_os_tipos_atuais(): void
    {
        $user = User::factory()->proponenteEPatrocinador()->create();

        $this->actingAs($user)->get('/cadastro/tipo')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cadastro/Tipo')
                ->where('tiposAtuais', ['proponente', 'patrocinador'])
                ->where('opcoes', [
                    ['valor' => 'proponente', 'rotulo' => 'Proponente'],
                    ['valor' => 'patrocinador', 'rotulo' => 'Patrocinador'],
                ])
                ->where('auth.user.tiposCadastro', ['proponente', 'patrocinador']));
    }

    public function test_etapa_sem_tipo_escolhido_vem_vazia(): void
    {
        $this->actingAs(User::factory()->cadastroCompleto()->create())->get('/cadastro/tipo')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tiposAtuais', [])
                ->where('auth.user.tiposCadastro', []));
    }

    public function test_etapa_exige_pessoa_fisica_completa(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->get('/cadastro/tipo')->assertRedirect(route('cadastro'));
        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['proponente']])->assertRedirect(route('cadastro'));

        $this->assertNull($user->fresh()->tipos_cadastro);
    }

    public function test_etapa_exige_login(): void
    {
        $this->get('/cadastro/tipo')->assertRedirect('/');
    }

    public function test_gestor_nao_escolhe_tipo_de_cadastro(): void
    {
        $gestor = User::factory()->gestor()->create();

        $this->actingAs($gestor)->post('/cadastro/tipo', ['tipos_cadastro' => ['proponente']])->assertRedirect('/');

        $this->assertNull($gestor->fresh()->tipos_cadastro);
    }

    public function test_finalizar_como_proponente_abre_os_editais(): void
    {
        $user = User::factory()->cadastroCompleto()->create();

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['proponente']])
            ->assertRedirect(route('portal.editais'))
            ->assertSessionHas('sucesso', 'Cadastro finalizado.');

        $this->assertSame([TipoCadastro::Proponente], $user->fresh()->tipos_cadastro->all());
    }

    public function test_finalizar_como_patrocinador_abre_os_projetos(): void
    {
        $user = User::factory()->cadastroCompleto()->create();

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['patrocinador']])
            ->assertRedirect(route('portal.projetos'))
            ->assertSessionHas('sucesso', 'Cadastro finalizado.');

        $this->assertSame([TipoCadastro::Patrocinador], $user->fresh()->tipos_cadastro->all());
    }

    public function test_finalizar_com_os_dois_tipos_guarda_os_dois_e_abre_os_editais(): void
    {
        $user = User::factory()->cadastroCompleto()->create();

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['patrocinador', 'proponente']])
            ->assertRedirect(route('portal.editais'))
            ->assertSessionHas('sucesso', 'Cadastro finalizado.');

        $this->assertSame([TipoCadastro::Proponente, TipoCadastro::Patrocinador], $user->fresh()->tipos_cadastro->all());
    }

    public function test_finalizar_com_anexo_obrigatorio_faltando_volta_para_anexos(): void
    {
        $semAnexos = PessoaFisica::factory()->create()->user;
        $comAnexos = PessoaFisica::factory()->comAnexosObrigatorios()->create()->user;

        $this->actingAs($semAnexos)->post('/cadastro/tipo', ['tipos_cadastro' => ['proponente']])
            ->assertRedirect(route('cadastro.anexos'))
            ->assertSessionHas('erro', CadastroAnexoController::ERRO_PENDENCIAS);
        $this->actingAs($comAnexos)->post('/cadastro/tipo', ['tipos_cadastro' => ['proponente']])
            ->assertRedirect(route('portal.editais'));

        $this->assertNull($semAnexos->fresh()->tipos_cadastro);
        $this->assertSame([TipoCadastro::Proponente], $comAnexos->fresh()->tipos_cadastro->all());
    }

    public function test_trocar_os_tipos_depois_de_finalizar_nao_confere_os_anexos_de_novo(): void
    {
        $user = PessoaFisica::factory()->for(User::factory()->proponente())->create()->user;

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['patrocinador']])->assertRedirect(route('portal.projetos'));

        $this->assertSame([TipoCadastro::Patrocinador], $user->fresh()->tipos_cadastro->all());
    }

    public function test_tipos_podem_ser_trocados_depois(): void
    {
        $user = User::factory()->proponenteEPatrocinador()->create();

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => ['patrocinador']])->assertRedirect(route('portal.projetos'));

        $this->assertSame([TipoCadastro::Patrocinador], $user->fresh()->tipos_cadastro->all());
    }

    #[DataProvider('tiposInvalidos')]
    public function test_recusa_tipos_vazios_ou_invalidos(mixed $tipos): void
    {
        $user = User::factory()->proponente()->create();

        $this->actingAs($user)->post('/cadastro/tipo', ['tipos_cadastro' => $tipos])
            ->assertSessionHasErrors(['tipos_cadastro' => 'Selecione pelo menos um tipo de cadastro.']);

        $this->assertSame([TipoCadastro::Proponente], $user->fresh()->tipos_cadastro->all());
    }

    /** @return array<string, array{mixed}> */
    public static function tiposInvalidos(): array
    {
        return [
            'nenhum marcado' => [[]],
            'texto em vez de lista' => ['proponente'],
            'tipo que não existe' => [['gestor']],
            'um válido e um que não existe' => [['patrocinador', 'gestor']],
        ];
    }
}
