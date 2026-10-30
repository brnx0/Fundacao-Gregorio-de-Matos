<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\AvatarVLibras;
use App\Enums\LadoVLibras;
use App\Models\ConfiguracaoVLibras;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VLibrasTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/vlibras';

    public function test_tela_mostra_o_padrao_antes_da_primeira_gravacao(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $resposta = $this->get(self::URL);

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/VLibras')
            ->where('configuracao', ['ativo' => true, 'lado' => 'R', 'avatar' => 'icaro'])
            ->where('lados', [
                ['valor' => 'R', 'rotulo' => 'Direita'],
                ['valor' => 'L', 'rotulo' => 'Esquerda'],
            ])
            ->where('avatares', [
                ['valor' => 'icaro', 'rotulo' => 'Ícaro'],
                ['valor' => 'hosana', 'rotulo' => 'Hosana'],
                ['valor' => 'guga', 'rotulo' => 'Guga'],
                ['valor' => 'random', 'rotulo' => 'Aleatório'],
            ]));
    }

    public function test_tela_mostra_a_configuracao_salva(): void
    {
        ConfiguracaoVLibras::factory()->desligado()->create(['lado' => LadoVLibras::Esquerda, 'avatar' => AvatarVLibras::Guga]);
        $this->actingAs(User::factory()->gestor()->create());

        $resposta = $this->get(self::URL);

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('configuracao', ['ativo' => false, 'lado' => 'L', 'avatar' => 'guga']));
    }

    public function test_salva_a_configuracao_numa_linha_so(): void
    {
        $this->actingAs(User::factory()->gestor()->create());
        $this->put(self::URL, ['ativo' => false, 'lado' => 'L', 'avatar' => 'random']);

        $resposta = $this->put(self::URL, ['ativo' => true, 'lado' => 'L', 'avatar' => 'hosana']);

        $resposta->assertSessionHasNoErrors()
            ->assertRedirect(self::URL)
            ->assertSessionHas('sucesso', 'Configuração do VLibras salva.');
        $configuracao = ConfiguracaoVLibras::sole();
        $this->assertTrue($configuracao->ativo);
        $this->assertSame(LadoVLibras::Esquerda, $configuracao->lado);
        $this->assertSame(AvatarVLibras::Hosana, $configuracao->avatar);
    }

    public function test_recusa_valores_fora_da_lista(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $resposta = $this->put(self::URL, ['ativo' => 'talvez', 'lado' => 'X', 'avatar' => 'zeca']);

        $resposta->assertSessionHasErrors([
            'ativo' => 'Valor inválido.',
            'lado' => 'Selecione uma opção da lista.',
            'avatar' => 'Selecione uma opção da lista.',
        ]);
        $this->assertSame(0, ConfiguracaoVLibras::count());
    }

    public function test_recusa_envio_sem_os_campos(): void
    {
        $this->actingAs(User::factory()->gestor()->create());

        $resposta = $this->put(self::URL, []);

        $resposta->assertSessionHasErrors([
            'ativo' => 'Este campo é obrigatório.',
            'lado' => 'Este campo é obrigatório.',
            'avatar' => 'Este campo é obrigatório.',
        ]);
        $this->assertSame(0, ConfiguracaoVLibras::count());
    }

    public function test_proponente_nao_acessa(): void
    {
        $this->actingAs(User::factory()->proponente()->create());

        $this->get(self::URL)->assertRedirect('/retaguarda/login');
        $this->put(self::URL, ['ativo' => false, 'lado' => 'R', 'avatar' => 'icaro'])->assertRedirect('/retaguarda/login');

        $this->assertSame(0, ConfiguracaoVLibras::count());
    }

    public function test_visitante_vai_para_login(): void
    {
        $this->get(self::URL)->assertRedirect('/retaguarda/login');
    }

    public function test_menu_aponta_para_vlibras(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.vlibras.edit', absolute: false), $menu);
        $this->assertStringContainsString("'VLibras'", $menu);
    }
}
