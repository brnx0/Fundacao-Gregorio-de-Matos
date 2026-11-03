<?php

namespace Tests\Feature\Retaguarda;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PerfilTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/perfil';

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(User $usuario, array $alteracoes = []): array
    {
        return [
            'name' => 'Maria Atualizada',
            'email' => $usuario->email,
            'telefone' => '(71) 99876-5432',
            'senha_atual' => null,
            ...$alteracoes,
        ];
    }

    public function test_tela_mostra_os_dados_com_cpf_mascarado_e_papeis(): void
    {
        $usuario = User::factory()->gestor()->create([
            'name' => 'Maria Gestora',
            'cpf' => '52998224725',
            'phone' => '71998765432',
        ]);

        $this->actingAs($usuario, 'retaguarda')->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Perfil')
                ->where('perfil.name', 'Maria Gestora')
                ->where('perfil.email', $usuario->email)
                ->where('perfil.telefone', '71998765432')
                ->where('perfil.cpf', '***.982.247-**')
                ->where('perfil.papeis', ['Administrador'])
                ->where('temSenha', true));
    }

    public function test_atualiza_nome_e_telefone_sem_pedir_senha(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario))
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Perfil atualizado.');

        $usuario->refresh();
        $this->assertSame('Maria Atualizada', $usuario->name);
        $this->assertSame('71998765432', $usuario->phone);
    }

    public function test_telefone_vazio_e_gravado_como_nulo(): void
    {
        $usuario = User::factory()->gestor()->create(['phone' => '71998765432']);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['telefone' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($usuario->fresh()->phone);
    }

    public function test_trocar_o_email_exige_a_senha_atual(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['email' => 'novo@gestor.test']))
            ->assertSessionHasErrors(['senha_atual' => 'Informe sua senha atual para trocar o e-mail.']);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['email' => 'novo@gestor.test', 'senha_atual' => 'errada']))
            ->assertSessionHasErrors(['senha_atual' => 'A senha atual está incorreta.']);

        $this->assertNotSame('novo@gestor.test', $usuario->fresh()->email);
    }

    public function test_troca_o_email_com_a_senha_atual_e_entra_com_o_novo(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['email' => 'Novo@Gestor.test', 'senha_atual' => '123456']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso', 'Perfil atualizado.');

        $this->assertSame('novo@gestor.test', $usuario->fresh()->email);
    }

    public function test_usuario_sem_senha_troca_o_email_sem_informar_senha(): void
    {
        $usuario = User::factory()->gestor()->create(['password' => null]);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['email' => 'govbr@gestor.test']))
            ->assertSessionHasNoErrors();

        $this->assertSame('govbr@gestor.test', $usuario->fresh()->email);
    }

    public function test_email_de_outro_usuario_e_recusado(): void
    {
        $outro = User::factory()->gestor()->create();
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['email' => $outro->email, 'senha_atual' => '123456']))
            ->assertSessionHasErrors(['email' => 'Este e-mail já está em uso.']);
    }

    public function test_campos_obrigatorios_e_telefone_invalido(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, ['name' => '', 'email' => '', 'telefone' => '123']))
            ->assertSessionHasErrors([
                'name' => 'Este campo é obrigatório.',
                'email' => 'Este campo é obrigatório.',
                'telefone' => 'Digite o telefone com DDD.',
            ]);
    }

    public function test_nao_altera_cpf_papeis_nem_situacao(): void
    {
        $usuario = User::factory()->gestor()->create(['cpf' => '52998224725']);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados($usuario, [
            'cpf' => '11144477735',
            'ativo' => false,
            'papeis' => [],
        ]))->assertSessionHasNoErrors();

        $usuario->refresh();
        $this->assertSame('52998224725', $usuario->cpf);
        $this->assertTrue($usuario->ativo);
        $this->assertTrue($usuario->ehGestor());
    }

    public function test_proponente_e_visitante_nao_acessam(): void
    {
        $proponente = User::factory()->create();

        $this->actingAs($proponente)->get(self::URL)->assertRedirect('/retaguarda/login');
        $this->actingAs($proponente)->put(self::URL, ['name' => 'X'])->assertRedirect('/retaguarda/login');

        auth()->logout();
        $this->get(self::URL)->assertRedirect('/retaguarda/login');
    }
}
