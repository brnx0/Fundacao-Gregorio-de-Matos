<?php

namespace Tests\Feature\Retaguarda;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MinhaSenhaTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/minha-senha';

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(array $alteracoes = []): array
    {
        return [
            'senha_atual' => '123456',
            'nova_senha' => 'senha-nova-forte',
            'nova_senha_confirmation' => 'senha-nova-forte',
            ...$alteracoes,
        ];
    }

    public function test_troca_a_senha_com_a_senha_atual_correta(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados())
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Senha alterada.');

        $this->assertTrue(Hash::check('senha-nova-forte', $usuario->fresh()->password));

        Auth::logout();
        $this->post('/retaguarda/login', ['email' => $usuario->email, 'senha' => 'senha-nova-forte'])
            ->assertRedirect('/retaguarda');
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_troca_de_senha_renova_o_id_da_sessao(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
        $this->startSession();
        $antes = session()->getId();

        $this->put(self::URL, $this->dados())->assertSessionHasNoErrors();

        $this->assertNotSame($antes, session()->getId());
    }

    public function test_tela_informa_se_o_usuario_ja_tem_senha(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Retaguarda/MinhaSenha')->where('temSenha', true));

        $this->actingAs(User::factory()->gestor()->create(['password' => null]), 'retaguarda')->get(self::URL)
            ->assertInertia(fn (Assert $p) => $p->where('temSenha', false));
    }

    public function test_senha_atual_errada_e_recusada(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados(['senha_atual' => 'errada']))
            ->assertSessionHasErrors(['senha_atual' => 'A senha atual está incorreta.']);

        $this->assertTrue(Hash::check('123456', $usuario->fresh()->password));
    }

    public function test_confirmacao_diferente_e_recusada(): void
    {
        $usuario = User::factory()->gestor()->create();

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados(['nova_senha_confirmation' => 'outra-coisa']))
            ->assertSessionHasErrors(['nova_senha' => 'A confirmação não confere.']);

        $this->assertTrue(Hash::check('123456', $usuario->fresh()->password));
    }

    public function test_nova_senha_curta_e_recusada(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda')
            ->put(self::URL, $this->dados(['nova_senha' => 'curta', 'nova_senha_confirmation' => 'curta']))
            ->assertSessionHasErrors(['nova_senha' => 'A nova senha precisa ter ao menos 8 caracteres.']);
    }

    public function test_nova_igual_a_atual_e_recusada(): void
    {
        $usuario = User::factory()->gestor()->create(['password' => 'senha-atual-forte']);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados([
            'senha_atual' => 'senha-atual-forte',
            'nova_senha' => 'senha-atual-forte',
            'nova_senha_confirmation' => 'senha-atual-forte',
        ]))->assertSessionHasErrors(['nova_senha' => 'A nova senha precisa ser diferente da atual.']);
    }

    public function test_usuario_sem_senha_define_uma_senha_sem_informar_a_atual(): void
    {
        $usuario = User::factory()->gestor()->create(['password' => null]);

        $this->actingAs($usuario, 'retaguarda')->put(self::URL, $this->dados(['senha_atual' => null]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Senha alterada.');

        $this->assertTrue(Hash::check('senha-nova-forte', $usuario->fresh()->password));
    }

    public function test_proponente_nao_acessa_minha_senha(): void
    {
        $proponente = User::factory()->create();

        $this->actingAs($proponente)->get(self::URL)->assertRedirect('/retaguarda/login');
        $this->actingAs($proponente)->put(self::URL, $this->dados())->assertRedirect('/retaguarda/login');
    }

    public function test_visitante_vai_para_login(): void
    {
        $this->get(self::URL)->assertRedirect('/retaguarda/login');
    }

    public function test_limite_de_tentativas(): void
    {
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        for ($tentativa = 1; $tentativa <= 6; $tentativa++) {
            $this->put(self::URL, $this->dados(['senha_atual' => 'errada']))
                ->assertSessionHasErrors('senha_atual');
        }

        $this->from(self::URL)
            ->put(self::URL, $this->dados())
            ->assertRedirect(self::URL)
            ->assertSessionHasErrors(['senha_atual' => 'Muitas tentativas. Aguarde um minuto e tente novamente.']);
    }
}
