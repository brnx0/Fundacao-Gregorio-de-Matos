<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\Escolaridade;
use App\Enums\PorteOrganizacao;
use App\Enums\RacaCor;
use App\Enums\TipoDeficiencia;
use App\Models\Organizacao;
use App\Models\PessoaFisica;
use App\Models\PrefeituraBairro;
use App\Models\RepresentanteLegal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProponentesTest extends TestCase
{
    use RefreshDatabase;

    private const string CPF_DA_MARIA = '52998224725';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->actingAs(User::factory()->gestor()->create());
    }

    /** Usuário do portal que enviou a etapa "Seus dados". */
    private function comDados(User $user, array $dados = []): User
    {
        PessoaFisica::factory()->for($user)->create($dados);

        return $user;
    }

    private function maria(array $dados = []): User
    {
        return $this->comDados(User::factory()->proponente()->create(['cpf' => self::CPF_DA_MARIA, 'name' => 'Maria Proponente']), $dados);
    }

    public function test_lista_quem_enviou_os_dados_com_cpf_mascarado_sem_gestor_nem_conta_so_com_login(): void
    {
        $this->maria();
        $this->comDados(User::factory()->gestor()->create());
        User::factory()->create();

        $this->get('/retaguarda/proponentes')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Proponentes/Index')
            ->has('proponentes.data', 1)
            ->where('proponentes.data.0.nome', 'Maria Proponente')
            ->where('proponentes.data.0.cpf', '***.982.247-**')
            ->where('proponentes.data.0.tipos', ['Proponente']));
    }

    public function test_filtra_por_situacao_e_por_tipo(): void
    {
        $this->comDados(User::factory()->proponente()->create(['name' => 'Ana Proponente']));
        $this->comDados(User::factory()->patrocinador()->create(['name' => 'Bia Patrocinadora']));
        $this->comDados(User::factory()->cadastroCompleto()->create(['name' => 'Caio Em Andamento']));

        $this->get('/retaguarda/proponentes?situacao=andamento')->assertInertia(fn (Assert $p) => $p
            ->has('proponentes.data', 1)
            ->where('proponentes.data.0.nome', 'Caio Em Andamento')
            ->where('proponentes.data.0.finalizado', false));

        $this->get('/retaguarda/proponentes?situacao=finalizado')->assertInertia(fn (Assert $p) => $p
            ->has('proponentes.data', 2)
            ->where('proponentes.data.0.nome', 'Ana Proponente')
            ->where('proponentes.data.1.nome', 'Bia Patrocinadora'));

        $this->get('/retaguarda/proponentes?tipo=patrocinador')->assertInertia(fn (Assert $p) => $p
            ->has('proponentes.data', 1)
            ->where('proponentes.data.0.nome', 'Bia Patrocinadora')
            ->where('filtros.tipo', 'patrocinador'));
    }

    public function test_busca_pelo_nome_e_pelo_cpf_completo_sem_mostrar_o_cpf(): void
    {
        $this->maria();
        $this->comDados(User::factory()->proponente()->create(['cpf' => '11144477735', 'name' => 'João Preposto']));

        $this->get('/retaguarda/proponentes?busca=MARI')->assertInertia(fn (Assert $p) => $p
            ->has('proponentes.data', 1)
            ->where('proponentes.data.0.nome', 'Maria Proponente'));

        $this->get('/retaguarda/proponentes?busca=111.444.777-35')->assertInertia(fn (Assert $p) => $p
            ->has('proponentes.data', 1)
            ->where('proponentes.data.0.nome', 'João Preposto')
            ->where('proponentes.data.0.cpf', '***.444.777-**'));
    }

    public function test_detalhe_mostra_os_dados_os_anexos_sem_caminho_e_as_pessoas_juridicas(): void
    {
        $maria = $this->maria([
            'raca_cor' => RacaCor::Parda,
            'pcd' => true,
            'deficiencia' => TipoDeficiencia::Outra,
            'deficiencia_outra' => 'Baixa audição unilateral',
            'escolaridade' => Escolaridade::MedioCompleto,
            'anos_atuacao' => 7,
            'telefone' => '71999998888',
            'foto_perfil' => 'cadastros/1/foto.png',
            'documento_cpf' => 'cadastros/1/cpf.pdf',
        ]);
        $organizacao = Organizacao::factory()->comResponsavel($maria)->create([
            'cnpj' => '11222333000181',
            'razao_social' => 'Coletivo Cultural Ltda',
            'porte' => PorteOrganizacao::Micro,
        ]);
        RepresentanteLegal::factory()->for($organizacao)->create(['cpf' => '11144477735', 'nome' => 'João Preposto']);

        $resposta = $this->get("/retaguarda/proponentes/{$maria->id}");

        $resposta->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Proponentes/Show')
            ->where('proponente.nome', 'Maria Proponente')
            ->where('secoes.1.campos.0', ['rotulo' => 'CPF', 'valor' => '***.982.247-**'])
            ->where('secoes.3.campos.0', ['rotulo' => 'Telefone', 'valor' => '(71) 99999-8888'])
            ->where('secoes.4.campos.1', ['rotulo' => 'Raça/cor', 'valor' => 'Parda'])
            ->where('secoes.4.campos.3', ['rotulo' => 'Tipo de deficiência', 'valor' => 'Outra: Baixa audição unilateral'])
            ->where('secoes.4.campos.4', ['rotulo' => 'Escolaridade', 'valor' => 'Ensino médio completo ou equivalente'])
            ->where('secoes.4.campos.7', ['rotulo' => 'Anos de atuação na área cultural', 'valor' => '7'])
            ->where('anexos.0.campo', 'foto_perfil')
            ->where('anexos.0.imagem', true)
            ->where('anexos.1.url', route('retaguarda.proponentes.anexo', [$maria, 'documento_cpf']))
            ->where('anexos.1.nome', 'copia-do-cpf-maria-proponente.pdf')
            ->where('anexos.3.enviado', false)
            ->where('anexos.3.url', null)
            ->where('organizacoes.0.razaoSocial', 'Coletivo Cultural Ltda')
            ->where('organizacoes.0.cnpj', '11.222.333/0001-81')
            ->where('organizacoes.0.porte', 'Micro')
            ->where('organizacoes.0.responsavel', true)
            ->where('organizacoes.0.representantes.0.cpf', '***.444.777-**'));

        $props = json_encode($resposta->viewData('page')['props']);
        $this->assertStringNotContainsString('cadastros/', $props);
        $this->assertStringNotContainsString(self::CPF_DA_MARIA, $props);
        $this->assertTrue($resposta->viewData('page')['encryptHistory']);
    }

    public function test_detalhe_mostra_o_nome_atual_da_prefeitura_bairro_escolhida(): void
    {
        $maria = $this->maria(['territorio' => 'itapua_ipitanga']);
        PrefeituraBairro::where('codigo', 'itapua_ipitanga')->update(['nome' => 'Itapuã']);
        $joao = $this->comDados(User::factory()->proponente()->create(), ['territorio' => PrefeituraBairro::FORA_DE_SALVADOR]);

        $this->get("/retaguarda/proponentes/{$maria->id}")
            ->assertInertia(fn (Assert $p) => $p->where('secoes.5.campos.7', ['rotulo' => 'Território', 'valor' => 'Itapuã']));
        $this->get("/retaguarda/proponentes/{$joao->id}")
            ->assertInertia(fn (Assert $p) => $p->where('secoes.5.campos.7', ['rotulo' => 'Território', 'valor' => 'Fora de Salvador']));
    }

    public function test_detalhe_mostra_a_data_de_nascimento_e_as_areas_na_ordem_das_listas(): void
    {
        $maria = User::factory()->proponente()->create();
        PessoaFisica::factory()->for($maria)->comAreas('Teatro', 'Outros', 'Música')->create(['data_nascimento' => '1985-04-12']);

        $this->get("/retaguarda/proponentes/{$maria->id}")->assertInertia(fn (Assert $p) => $p
            ->where('secoes.0.campos.3', ['rotulo' => 'Data de nascimento', 'valor' => '12/04/1985'])
            ->where('secoes.2.campos.1', ['rotulo' => 'Áreas de atuação', 'valor' => 'Música, Teatro, Outros']));
    }

    public function test_detalhe_de_cadastro_antigo_mostra_data_de_nascimento_e_areas_nao_informadas(): void
    {
        $joao = User::factory()->proponente()->create();
        PessoaFisica::factory()->for($joao)->semDataDeNascimento()->create();

        $this->get("/retaguarda/proponentes/{$joao->id}")->assertInertia(fn (Assert $p) => $p
            ->where('secoes.0.campos.3', ['rotulo' => 'Data de nascimento', 'valor' => null])
            ->where('secoes.2.campos.1', ['rotulo' => 'Áreas de atuação', 'valor' => null]));
    }

    public function test_detalhe_da_404_para_usuario_da_retaguarda_e_para_quem_nao_enviou_os_dados(): void
    {
        $gestor = $this->comDados(User::factory()->gestor()->create());
        $soLogin = User::factory()->create();

        $this->get("/retaguarda/proponentes/{$gestor->id}")->assertNotFound();
        $this->get("/retaguarda/proponentes/{$soLogin->id}")->assertNotFound();
        $this->get("/retaguarda/proponentes/{$gestor->id}/anexos/documento_cpf")->assertNotFound();
    }

    public function test_anexo_abre_o_arquivo_enviado(): void
    {
        $maria = $this->maria(['documento_cpf' => 'cadastros/1/cpf.pdf']);
        Storage::disk('local')->put('cadastros/1/cpf.pdf', 'conteudo-do-pdf');

        $resposta = $this->get("/retaguarda/proponentes/{$maria->id}/anexos/documento_cpf");

        $resposta->assertOk()->assertStreamedContent('conteudo-do-pdf');
        $this->assertSame('inline; filename=copia-do-cpf-maria-proponente.pdf', $resposta->headers->get('Content-Disposition'));
    }

    public function test_anexo_da_404_para_campo_vazio_arquivo_ausente_ou_coluna_que_nao_e_arquivo(): void
    {
        $maria = $this->maria(['documento_rg' => 'cadastros/1/rg.pdf', 'documento_passaporte' => null]);

        $this->get("/retaguarda/proponentes/{$maria->id}/anexos/documento_passaporte")->assertNotFound();
        $this->get("/retaguarda/proponentes/{$maria->id}/anexos/documento_rg")->assertNotFound();
        $this->get("/retaguarda/proponentes/{$maria->id}/anexos/rg")->assertNotFound();
    }

    public function test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda(): void
    {
        $maria = $this->maria(['documento_cpf' => 'cadastros/1/cpf.pdf']);
        Storage::disk('local')->put('cadastros/1/cpf.pdf', 'conteudo-do-pdf');
        $enderecos = ['/retaguarda/proponentes', "/retaguarda/proponentes/{$maria->id}", "/retaguarda/proponentes/{$maria->id}/anexos/documento_cpf"];

        Auth::logout();
        foreach ($enderecos as $endereco) {
            $this->get($endereco)->assertRedirect('/retaguarda/login');
        }

        $this->actingAs($maria);
        foreach ($enderecos as $endereco) {
            $this->get($endereco)->assertRedirect('/retaguarda/login');
        }
    }

    public function test_menu_tem_o_grupo_cadastro_com_proponentes(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.proponentes.index', absolute: false), $menu);
        $this->assertStringContainsString("'Cadastro'", $menu);
        $this->assertStringContainsString("'Proponentes'", $menu);
    }
}
