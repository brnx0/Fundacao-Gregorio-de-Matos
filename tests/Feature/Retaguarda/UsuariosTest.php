<?php

namespace Tests\Feature\Retaguarda;

use App\Models\PessoaFisica;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsuariosTest extends TestCase
{
    use RefreshDatabase;

    private const string ERRO_ULTIMO_ADMIN = 'É preciso manter ao menos um administrador ativo.';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->gestor()->create(['name' => 'Ana Administradora']);
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(array $alteracoes = []): array
    {
        return [
            'name' => 'Bruno Usuário',
            'email' => '  Bruno@FGM.test ',
            'cpf' => '529.982.247-25',
            'senha' => 'senha-forte',
            'senha_confirmation' => 'senha-forte',
            'papeis' => [Role::ADMINISTRADOR],
            ...$alteracoes,
        ];
    }

    public function test_lista_so_quem_tem_papel(): void
    {
        User::factory()->create(['name' => 'Paula Proponente', 'email' => 'paula@exemplo.test']);
        User::factory()->gestor()->create(['name' => 'Carlos Usuário', 'email' => 'carlos@fgm.test', 'cpf' => '11144477735']);

        $this->get('/retaguarda/usuarios')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Usuarios/Index')
                ->has('usuarios.data', 2)
                ->where('usuarios.data.1.nome', 'Carlos Usuário')
                ->where('usuarios.data.1.cpf', '***.444.777-**')
                ->where('usuarios.data.1.papeis', ['Administrador'])
                ->where('usuarios.data.1.ativo', true));

        $this->get('/retaguarda/usuarios?busca=CARLOS@')
            ->assertInertia(fn (Assert $p) => $p->has('usuarios.data', 1)->where('filtros.busca', 'CARLOS@'));

        $this->get('/retaguarda/usuarios?busca=paula')
            ->assertInertia(fn (Assert $p) => $p->has('usuarios.data', 0));
    }

    public function test_endereco_antigo_de_gestores_nao_existe(): void
    {
        $this->get('/retaguarda/gestores')->assertNotFound();
    }

    public function test_menu_aponta_para_usuarios(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.usuarios.index', absolute: false), $menu);
        $this->assertStringContainsString("'Usuários'", $menu);
        $this->assertStringNotContainsString('gestores', $menu);
    }

    public function test_cria_usuario_com_senha_e_papel(): void
    {
        $this->post('/retaguarda/usuarios', $this->dados())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/usuarios')
            ->assertSessionHas('sucesso', 'Usuário salvo.');

        $usuario = User::where('email', 'bruno@fgm.test')->firstOrFail();
        $this->assertSame('52998224725', $usuario->cpf);
        $this->assertTrue($usuario->temPapel(Role::ADMINISTRADOR));

        Auth::logout();
        $this->post('/retaguarda/login', ['email' => 'bruno@fgm.test', 'senha' => 'senha-forte'])
            ->assertRedirect('/retaguarda');
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_cpf_invalido_e_recusado(): void
    {
        $this->post('/retaguarda/usuarios', $this->dados(['cpf' => '529.982.247-26']))
            ->assertSessionHasErrors('cpf');

        $this->post('/retaguarda/usuarios', $this->dados(['cpf' => $this->admin->cpf, 'email' => 'outro@fgm.test']))
            ->assertSessionHasErrors('cpf');

        $this->assertDatabaseMissing('users', ['email' => 'bruno@fgm.test']);
    }

    public function test_cpf_de_proponente_sem_confirmacao_nao_salva(): void
    {
        $proponente = User::factory()->create([
            'cpf' => '52998224725',
            'name' => 'Nome do gov.br',
            'email' => 'proponente@fgm.test',
            'password' => null,
        ]);
        $total = User::count();

        $this->post('/retaguarda/usuarios', $this->dados())
            ->assertSessionHasErrors([
                'cpf' => 'Este CPF já é do proponente Nome do gov.br. Ao confirmar, ele passa a ser usuário da retaguarda e deixa de acessar o cadastro do portal.',
                'confirmar_proponente',
            ]);

        $this->assertSame($total, User::count());
        $proponente->refresh();
        $this->assertSame('proponente@fgm.test', $proponente->email);
        $this->assertNull($proponente->password);
        $this->assertFalse($proponente->papeis()->exists());
    }

    public function test_cpf_de_proponente_vira_usuario_sem_duplicar(): void
    {
        $pessoa = PessoaFisica::factory()->create(['rg' => '12345678']);
        $proponente = $pessoa->user;
        $proponente->update(['cpf' => '52998224725', 'name' => 'Nome do gov.br']);
        $total = User::count();

        $this->post('/retaguarda/usuarios', $this->dados(['confirmar_proponente' => '1']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/usuarios')
            ->assertSessionHas('sucesso', 'O proponente Nome do gov.br agora é usuário da retaguarda.');

        $this->assertSame($total, User::count());
        $proponente->refresh();
        $this->assertSame('bruno@fgm.test', $proponente->email);
        $this->assertSame('Nome do gov.br', $proponente->name);
        $this->assertTrue(Hash::check('senha-forte', $proponente->password));
        $this->assertTrue($proponente->ehGestor());
        $this->assertSame('12345678', $pessoa->fresh()->rg);
    }

    public function test_edicao_sem_senha_mantem_senha(): void
    {
        $usuario = User::factory()->gestor()->create(['cpf' => '11144477735']);

        $this->get("/retaguarda/usuarios/{$usuario->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Usuarios/Form')
                ->where('usuario.cpf', '111.444.777-35')
                ->where('usuario.papeis', [Role::ADMINISTRADOR]));

        $this->put("/retaguarda/usuarios/{$usuario->id}", $this->dados([
            'cpf' => '111.444.777-35',
            'senha' => '',
            'senha_confirmation' => '',
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/usuarios');

        $usuario->refresh();
        $this->assertSame('Bruno Usuário', $usuario->name);
        $this->assertSame('bruno@fgm.test', $usuario->email);
        $this->assertTrue(Hash::check('123456', $usuario->password));
    }

    public function test_proponente_nao_abre_na_edicao(): void
    {
        $proponente = User::factory()->create();

        $this->get("/retaguarda/usuarios/{$proponente->id}/edit")->assertNotFound();
    }

    public function test_nao_desativa_a_si_mesmo(): void
    {
        User::factory()->gestor()->create(['cpf' => '11144477735']);

        $this->patch("/retaguarda/usuarios/{$this->admin->id}/ativo")
            ->assertSessionHas('erro', 'Você não pode desativar a si mesmo.');
        $this->assertTrue($this->admin->fresh()->ativo);

        $this->put("/retaguarda/usuarios/{$this->admin->id}", $this->dados([
            'email' => $this->admin->email,
            'cpf' => $this->admin->cpf,
            'papeis' => [],
            'senha' => '',
            'senha_confirmation' => '',
        ]))->assertSessionHasErrors('papeis');
        $this->assertTrue($this->admin->temPapel(Role::ADMINISTRADOR));
    }

    public function test_nao_desativa_o_ultimo_administrador(): void
    {
        // Hoje só existe o papel administrador; um papel de teste permite um usuário que não é administrador.
        $papelDeTeste = Role::factory()->create(['nome' => 'Editor', 'slug' => 'editor']);
        $editor = User::factory()->create(['cpf' => '11144477735', 'password' => '123456']);
        $editor->papeis()->attach($papelDeTeste);
        User::factory()->gestor()->create(['cpf' => '39053344705', 'ativo' => false]);

        $this->actingAs($editor)
            ->patch("/retaguarda/usuarios/{$this->admin->id}/ativo")
            ->assertSessionHas('erro', self::ERRO_ULTIMO_ADMIN);
        $this->assertTrue($this->admin->fresh()->ativo);

        $this->put("/retaguarda/usuarios/{$this->admin->id}", $this->dados([
            'email' => $this->admin->email,
            'cpf' => $this->admin->cpf,
            'papeis' => ['editor'],
            'senha' => '',
            'senha_confirmation' => '',
        ]))->assertSessionHasErrors(['papeis' => self::ERRO_ULTIMO_ADMIN]);
        $this->assertTrue($this->admin->temPapel(Role::ADMINISTRADOR));

        $outroAdmin = User::factory()->gestor()->create(['cpf' => '52998224725']);
        $this->patch("/retaguarda/usuarios/{$this->admin->id}/ativo")
            ->assertSessionHas('sucesso', 'Usuário desativado.');
        $this->assertFalse($this->admin->fresh()->ativo);
        $this->assertTrue($outroAdmin->fresh()->ativo);
    }

    public function test_desativado_nao_entra(): void
    {
        $outro = User::factory()->gestor()->create(['cpf' => '11144477735']);

        $this->patch("/retaguarda/usuarios/{$outro->id}/ativo")->assertSessionHas('sucesso', 'Usuário desativado.');

        Auth::logout();
        $this->post('/retaguarda/login', ['email' => $outro->email, 'senha' => '123456'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($this->admin);
        $this->patch("/retaguarda/usuarios/{$outro->id}/ativo")->assertSessionHas('sucesso', 'Usuário ativado.');
        $this->assertTrue($outro->fresh()->ativo);
    }
}
