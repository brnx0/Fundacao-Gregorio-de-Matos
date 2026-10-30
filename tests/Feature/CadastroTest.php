<?php

namespace Tests\Feature;

use App\Enums\Escolaridade;
use App\Enums\FaixaDeRenda;
use App\Enums\IdentidadeGenero;
use App\Enums\PovoTradicional;
use App\Enums\RacaCor;
use App\Enums\TipoCadastro;
use App\Enums\TipoDeficiencia;
use App\Models\AreaAtuacao;
use App\Models\Organizacao;
use App\Models\PessoaFisica;
use App\Models\PrefeituraBairro;
use App\Models\User;
use App\Support\Cnpj;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CadastroTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastro_exige_login(): void
    {
        $this->get('/cadastro')->assertRedirect('/');
    }

    public function test_cadastro_mostra_nome_e_cpf_mascarado(): void
    {
        $user = User::factory()->create(['cpf' => '52998224725', 'name' => 'Maria Souza']);

        $this->actingAs($user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->component('Cadastro/Completar')
            ->where('auth.user.nome', 'Maria Souza')
            ->where('auth.user.cpfMascarado', '***.982.247-**'));
    }

    public function test_formulario_traz_os_dados_do_govbr(): void
    {
        $user = User::factory()->create([
            'cpf' => '52998224725',
            'name' => 'Maria Souza',
            'email' => 'maria@example.com',
            'phone' => '(71) 99876-5432',
        ]);

        $this->actingAs($user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->component('Cadastro/Completar')
            ->where('govbr', [
                'nome' => 'Maria Souza',
                'cpf' => '529.982.247-25',
                'email' => 'maria@example.com',
                'telefone' => '71998765432',
            ])
            ->where('cadastro', null));
    }

    public function test_formulario_traz_o_cadastro_ja_salvo_sem_os_caminhos_dos_arquivos(): void
    {
        $cadastro = PessoaFisica::factory()->create(['rg' => '1234567', 'territorio' => 'cajazeiras']);

        $this->actingAs($cadastro->user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->where('cadastro.rg', '1234567')
            ->where('cadastro.territorio', 'cajazeiras')
            ->where('cadastro.arquivos.documento_cpf', true)
            ->where('cadastro.arquivos.foto_perfil', false)
            ->missing('cadastro.documento_cpf'));
    }

    public function test_territorios_sao_as_prefeituras_bairro_ativas_e_fora_de_salvador(): void
    {
        PrefeituraBairro::where('codigo', 'valeria')->update(['ativo' => false]);

        $this->actingAs(User::factory()->create())->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->has('opcoes.territorios', 10)
            ->where('opcoes.territorios.0', ['valor' => 'centro_brotas', 'rotulo' => 'Centro/Brotas'])
            ->where('opcoes.territorios.8', ['valor' => 'pau_da_lima', 'rotulo' => 'Pau da Lima'])
            ->where('opcoes.territorios.9', ['valor' => 'fora_de_salvador', 'rotulo' => 'Fora de Salvador']));
    }

    public function test_prefeitura_bairro_desativada_continua_valendo_para_quem_ja_a_escolheu(): void
    {
        Storage::fake('local');
        $cadastro = PessoaFisica::factory()->create(['territorio' => 'valeria']);
        PrefeituraBairro::where('codigo', 'valeria')->update(['ativo' => false]);

        $this->actingAs($cadastro->user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->where('opcoes.territorios.9', ['valor' => 'valeria', 'rotulo' => 'Valéria']));

        $this->actingAs($cadastro->user)
            ->post('/cadastro', $this->dadosValidos(['territorio' => 'valeria', 'telefone' => '(71) 99876-5432']))
            ->assertSessionHasNoErrors();
        $this->assertSame('valeria', $cadastro->fresh()->territorio);
    }

    public function test_prefeitura_bairro_desativada_nao_pode_ser_escolhida_por_quem_nao_a_tinha(): void
    {
        PrefeituraBairro::where('codigo', 'valeria')->update(['ativo' => false]);

        $this->actingAs(User::factory()->create())->post('/cadastro', $this->dadosValidos(['territorio' => 'valeria']))
            ->assertSessionHasErrors(['territorio' => 'Selecione uma opção da lista.']);
    }

    public function test_salvar_cadastro_exige_login(): void
    {
        $this->post('/cadastro', $this->dadosValidos())->assertRedirect('/');

        $this->assertDatabaseCount('pessoas_fisicas', 0);
    }

    public function test_salva_o_cadastro_de_pessoa_fisica_e_os_arquivos(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'nome_artistico' => 'Maria do Samba',
            'telefone_adicional' => '(71) 3333-4444',
            'pcd' => true,
            'deficiencia' => 'auditiva',
        ]))->assertRedirect(route('cadastro.organizacao'))->assertSessionHas('sucesso');

        $cadastro = $user->pessoaFisica()->sole();
        $this->assertSame('Maria do Samba', $cadastro->nome_artistico);
        $this->assertSame('7133334444', $cadastro->telefone_adicional);
        $this->assertSame('40020000', $cadastro->cep);
        $this->assertSame('centro_brotas', $cadastro->territorio);
        $this->assertTrue($cadastro->pcd);
        $this->assertFalse($cadastro->representa_coletivo);
        Storage::disk('local')->assertExists([$cadastro->documento_cpf, $cadastro->documento_rg]);
        $this->assertNull($cadastro->foto_perfil);
        $this->assertNotNull($user->fresh()->profile_completed_at);
    }

    public function test_email_e_telefone_do_govbr_nao_podem_ser_trocados_pelo_formulario(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'email' => 'outro@example.com',
            'telefone' => '(11) 91111-2222',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('maria@example.com', $user->pessoaFisica()->sole()->email);
        $this->assertSame('71998765432', $user->pessoaFisica()->sole()->telefone);
    }

    public function test_sem_email_e_telefone_do_govbr_eles_sao_pedidos_no_formulario(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => null, 'phone' => null]);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos())
            ->assertSessionHasErrors(['email' => 'Este campo é obrigatório.', 'telefone' => 'Este campo é obrigatório.']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'email' => 'maria@example.com',
            'telefone' => '(71) 99876-5432',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('maria@example.com', $user->pessoaFisica()->sole()->email);
        $this->assertSame('71998765432', $user->pessoaFisica()->sole()->telefone);
    }

    public function test_campos_obrigatorios_do_cadastro(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/cadastro', [])->assertSessionHasErrors([
            ...array_fill_keys([
                'data_nascimento', 'pais_origem', 'rg', 'documento_cpf', 'documento_rg', 'identidade_genero', 'raca_cor',
                'cep', 'logradouro', 'bairro', 'cidade', 'estado', 'territorio',
            ], 'Este campo é obrigatório.'),
            'areas_atuacao' => 'Marque ao menos uma área de atuação.',
        ]);

        $this->assertDatabaseCount('pessoas_fisicas', 0);
        $this->assertNull($user->fresh()->profile_completed_at);
    }

    /** @param array<string, mixed> $dados */
    #[DataProvider('dadosInvalidos')]
    public function test_recusa_dados_invalidos(array $dados, string $campo, string $mensagem): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos($dados))
            ->assertSessionHasErrors([$campo => $mensagem]);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function dadosInvalidos(): array
    {
        return [
            'arquivo acima de 2 MB' => [
                ['documento_cpf' => UploadedFile::fake()->create('cpf.pdf', 2049, 'application/pdf')],
                'documento_cpf', 'O arquivo deve ter no máximo 2 MB.',
            ],
            'documento que não é PDF nem imagem' => [
                ['documento_rg' => UploadedFile::fake()->create('rg.docx', 10)],
                'documento_rg', 'Envie um PDF ou uma imagem JPG ou PNG.',
            ],
            'foto que não é imagem' => [
                ['foto_perfil' => UploadedFile::fake()->create('foto.pdf', 10, 'application/pdf')],
                'foto_perfil', 'Envie uma imagem JPG, PNG ou GIF.',
            ],
            'país fora da lista' => [['pais_origem' => 'Atlântida'], 'pais_origem', 'Selecione uma opção da lista.'],
            'território fora da lista' => [['territorio' => 'centro'], 'territorio', 'Selecione uma opção da lista.'],
            'estado fora da lista' => [['estado' => 'XX'], 'estado', 'Selecione uma opção da lista.'],
            'CEP incompleto' => [['cep' => '40020-00'], 'cep', 'Digite os 8 números do CEP.'],
            'telefone adicional sem DDD' => [['telefone_adicional' => '9999-8888'], 'telefone_adicional', 'Digite o telefone com DDD.'],
            'data de nascimento que não existe' => [['data_nascimento' => '1990-02-30'], 'data_nascimento', 'Digite uma data válida.'],
            'data de nascimento antes de 1900' => [['data_nascimento' => '1899-12-31'], 'data_nascimento', 'Digite uma data válida.'],
            'data de nascimento depois de hoje' => [['data_nascimento' => '2999-01-01'], 'data_nascimento', 'A data de nascimento precisa ser anterior a hoje.'],
        ];
    }

    public function test_aceita_quem_completa_dezoito_anos_hoje(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-06-15 10:00', 'America/Bahia'));
        $user = User::factory()->create(['phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos(['data_nascimento' => '2008-06-15']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2008-06-15', $user->pessoaFisica()->sole()->data_nascimento->toDateString());
    }

    /** @return array<string, array{string, string}> */
    public static function menoresDeIdade(): array
    {
        return [
            'completa 18 anos amanhã' => ['2026-06-15 10:00', '2008-06-16'],
            'hoje é 29/02 e o aniversário é em 01/03' => ['2028-02-29 10:00', '2010-03-01'],
        ];
    }

    #[DataProvider('menoresDeIdade')]
    public function test_recusa_quem_ainda_nao_tem_dezoito_anos(string $agora, string $nascimento): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse($agora, 'America/Bahia'));

        $this->actingAs(User::factory()->create(['phone' => '71998765432']))
            ->post('/cadastro', $this->dadosValidos(['data_nascimento' => $nascimento]))
            ->assertSessionHasErrors(['data_nascimento' => 'É preciso ter 18 anos ou mais para se cadastrar.']);

        $this->assertDatabaseCount('pessoas_fisicas', 0);
    }

    public function test_salva_a_data_de_nascimento_e_as_areas_marcadas_no_lugar_das_de_antes(): void
    {
        Storage::fake('local');
        $cadastro = PessoaFisica::factory()->comAreas('Teatro', 'Música')->create();

        $this->actingAs($cadastro->user)->post('/cadastro', $this->dadosValidos([
            'data_nascimento' => '1985-04-12',
            'areas_atuacao' => $this->idsDasAreas('Música', 'Dança e Coreografia'),
            'telefone' => '(71) 99876-5432',
        ]))->assertSessionHasNoErrors();

        $cadastro->refresh();
        $this->assertSame('1985-04-12', $cadastro->data_nascimento->toDateString());
        $this->assertSame(['Dança e Coreografia', 'Música'], $cadastro->areasAtuacao()->orderBy('nome')->pluck('nome')->all());
    }

    public function test_formulario_traz_a_data_de_nascimento_no_formato_do_campo_de_data(): void
    {
        $cadastro = PessoaFisica::factory()->create(['data_nascimento' => '1985-04-12']);

        $this->actingAs($cadastro->user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->where('cadastro.data_nascimento', '1985-04-12'));
    }

    public function test_areas_de_atuacao_sao_as_ativas_pelo_nome_com_outros_no_fim(): void
    {
        AreaAtuacao::where('nome', 'Cinema')->update(['ativo' => false]);

        $this->actingAs(User::factory()->create())->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->has('opcoes.areasAtuacao', 30)
            ->where('opcoes.areasAtuacao.0.rotulo', 'Arquitetura')
            // Sem diferenciar acento: Mídias antes de Moda (comparando bytes, como no ORDER BY do Oracle, o í vem depois do o).
            ->where('opcoes.areasAtuacao.17.rotulo', 'Mídias Interativas')
            ->where('opcoes.areasAtuacao.18.rotulo', 'Moda e vestuário')
            ->where('opcoes.areasAtuacao.29.rotulo', 'Outros'));
    }

    public function test_area_desativada_nao_pode_ser_marcada_por_quem_nao_a_tinha(): void
    {
        Storage::fake('local');
        AreaAtuacao::where('nome', 'Cinema')->update(['ativo' => false]);

        $this->actingAs(User::factory()->create(['phone' => '71998765432']))
            ->post('/cadastro', $this->dadosValidos(['areas_atuacao' => $this->idsDasAreas('Música', 'Cinema')]))
            ->assertSessionHasErrors(['areas_atuacao' => 'Uma das áreas marcadas saiu da lista. Confira as áreas e salve de novo.']);

        $this->assertDatabaseCount('pessoas_fisicas', 0);
    }

    public function test_area_desativada_continua_valendo_para_quem_ja_a_marcou(): void
    {
        Storage::fake('local');
        $cadastro = PessoaFisica::factory()->comAreas('Cinema')->create();
        AreaAtuacao::where('nome', 'Cinema')->update(['ativo' => false]);
        $cinema = $this->idsDasAreas('Cinema');

        $this->actingAs($cadastro->user)->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->has('opcoes.areasAtuacao', 31)
            ->where('opcoes.areasAtuacao.11', ['valor' => $cinema[0], 'rotulo' => 'Cinema'])
            ->where('cadastro.areas_atuacao', $cinema));

        $this->actingAs($cadastro->user)
            ->post('/cadastro', $this->dadosValidos(['areas_atuacao' => $cinema, 'telefone' => '(71) 99876-5432']))
            ->assertSessionHasNoErrors();
        $this->assertSame(['Cinema'], $cadastro->areasAtuacao()->pluck('nome')->all());
    }

    public function test_cadastro_salvo_nao_exige_reenviar_documentos_e_troca_o_arquivo_substituido(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('cadastros/cpf-antigo.pdf', 'antigo');
        Storage::disk('local')->put('cadastros/rg-antigo.pdf', 'antigo');
        $cadastro = PessoaFisica::factory()->create([
            'documento_cpf' => 'cadastros/cpf-antigo.pdf',
            'documento_rg' => 'cadastros/rg-antigo.pdf',
        ]);

        $this->actingAs($cadastro->user)->post('/cadastro', [
            ...$this->dadosValidos(['rg' => '7654321', 'telefone' => '(71) 99876-5432']),
            'documento_cpf' => UploadedFile::fake()->create('cpf-novo.pdf', 10, 'application/pdf'),
            'documento_rg' => null,
        ])->assertSessionHasNoErrors();

        $cadastro->refresh();
        $this->assertSame('7654321', $cadastro->rg);
        $this->assertSame('cadastros/rg-antigo.pdf', $cadastro->documento_rg);
        Storage::disk('local')->assertMissing('cadastros/cpf-antigo.pdf');
        Storage::disk('local')->assertExists([$cadastro->documento_cpf, 'cadastros/rg-antigo.pdf']);
        $this->assertDatabaseCount('pessoas_fisicas', 1);
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dadosValidos(array $alteracoes = []): array
    {
        return [
            'data_nascimento' => '1985-04-12',
            'pais_origem' => 'Brasil',
            'rg' => '1234567',
            'documento_cpf' => UploadedFile::fake()->create('cpf.pdf', 100, 'application/pdf'),
            'documento_rg' => UploadedFile::fake()->create('rg.jpg', 100, 'image/jpeg'),
            'identidade_genero' => IdentidadeGenero::MulherCis->value,
            'raca_cor' => RacaCor::Preta->value,
            'escolaridade' => 'medio_completo',
            'renda' => 'de_1_a_3',
            'povo_tradicional' => 'nao_pertence',
            'anos_atuacao' => 12,
            'areas_atuacao' => $this->idsDasAreas('Música'),
            'cep' => '40020-000',
            'logradouro' => 'Rua Chile',
            'numero' => '10',
            'bairro' => 'Centro',
            'cidade' => 'Salvador',
            'estado' => 'BA',
            'territorio' => 'centro_brotas',
            ...$alteracoes,
        ];
    }

    /**
     * Ids das áreas da migration, pelos nomes.
     *
     * @return list<int>
     */
    private function idsDasAreas(string ...$nomes): array
    {
        return AreaAtuacao::query()->whereIn('nome', $nomes)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function test_salva_o_perfil_da_pessoa_fisica(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'povo_tradicional' => 'quilombolas',
        ]))->assertSessionHasNoErrors();

        $cadastro = $user->pessoaFisica()->sole();
        $this->assertSame(Escolaridade::MedioCompleto, $cadastro->escolaridade);
        $this->assertSame(FaixaDeRenda::DeUmATres, $cadastro->renda);
        $this->assertSame(PovoTradicional::Quilombolas, $cadastro->povo_tradicional);
        $this->assertSame(12, $cadastro->anos_atuacao);
    }

    public function test_perfil_e_obrigatorio(): void
    {
        $dados = $this->dadosValidos();
        unset($dados['escolaridade'], $dados['renda'], $dados['povo_tradicional'], $dados['anos_atuacao']);

        $this->actingAs(User::factory()->create())->post('/cadastro', $dados)->assertSessionHasErrors([
            'escolaridade' => 'Este campo é obrigatório.',
            'renda' => 'Este campo é obrigatório.',
            'povo_tradicional' => 'Este campo é obrigatório.',
            'anos_atuacao' => 'Este campo é obrigatório.',
        ]);
    }

    public function test_pcd_exige_o_tipo_e_outra_exige_qual(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos(['pcd' => 1]))
            ->assertSessionHasErrors(['deficiencia' => 'Este campo é obrigatório.']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos(['pcd' => 1, 'deficiencia' => 'outra']))
            ->assertSessionHasErrors(['deficiencia_outra' => 'Diga qual é a deficiência.']);
    }

    public function test_sem_pcd_o_tipo_de_deficiencia_nao_fica_gravado(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'pcd' => 0, 'deficiencia' => 'visual', 'deficiencia_outra' => 'x',
        ]))->assertSessionHasNoErrors();

        $cadastro = $user->pessoaFisica()->sole();
        $this->assertNull($cadastro->deficiencia);
        $this->assertNull($cadastro->deficiencia_outra);

        $this->actingAs($user->fresh())->post('/cadastro', $this->dadosValidos([
            'pcd' => 1, 'deficiencia' => 'visual', 'deficiencia_outra' => 'x',
        ]))->assertSessionHasNoErrors();

        $cadastro = $user->pessoaFisica()->sole();
        $this->assertSame(TipoDeficiencia::Visual, $cadastro->deficiencia);
        $this->assertNull($cadastro->deficiencia_outra);
    }

    public function test_deficiencia_outra_escondida_sem_pcd_nao_barra_o_salvamento(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos([
            'pcd' => 0, 'deficiencia' => 'outra', 'deficiencia_outra' => '',
        ]))->assertSessionHasNoErrors();

        $cadastro = $user->pessoaFisica()->sole();
        $this->assertNull($cadastro->deficiencia);
        $this->assertNull($cadastro->deficiencia_outra);
    }

    public function test_retrato_do_perfil_traz_os_campos_com_enums_como_valor(): void
    {
        $cadastro = PessoaFisica::factory()->create([
            'identidade_genero' => IdentidadeGenero::MulherCis, 'escolaridade' => Escolaridade::MedioCompleto,
            'pcd' => true, 'deficiencia' => TipoDeficiencia::Outra, 'deficiencia_outra' => 'Rara', 'anos_atuacao' => 7,
        ]);

        $retrato = $cadastro->fresh()->retratoDoPerfil();

        $this->assertSame(PessoaFisica::CAMPOS_DO_PERFIL, array_keys($retrato));
        $this->assertSame(IdentidadeGenero::MulherCis->value, $retrato['identidade_genero']);
        $this->assertSame('medio_completo', $retrato['escolaridade']);
        $this->assertSame('outra', $retrato['deficiencia']);
        $this->assertSame('Rara', $retrato['deficiencia_outra']);
        $this->assertTrue($retrato['pcd']);
        $this->assertSame(7, $retrato['anos_atuacao']);
    }

    public function test_anos_de_atuacao_inteiro_de_zero_a_cem(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['email' => 'maria@example.com', 'phone' => '71998765432']);

        foreach ([-1, 101, 2.5] as $invalido) {
            $this->actingAs($user)->post('/cadastro', $this->dadosValidos(['anos_atuacao' => $invalido]))
                ->assertSessionHasErrors('anos_atuacao');
        }

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos(['anos_atuacao' => 0]))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $user->pessoaFisica()->sole()->anos_atuacao);
    }

    public function test_cadastro_antigo_fica_com_perfil_incompleto(): void
    {
        $this->assertFalse(PessoaFisica::factory()->perfilIncompleto()->create()->perfilCompleto());
        $this->assertTrue(PessoaFisica::factory()->create()->perfilCompleto());
        $this->assertFalse(PessoaFisica::factory()->create(['pcd' => true])->perfilCompleto());
    }

    public function test_formulario_traz_as_opcoes_do_perfil(): void
    {
        $this->actingAs(User::factory()->create())->get('/cadastro')->assertInertia(fn (Assert $page) => $page
            ->has('opcoes.povosTradicionais', 30)
            ->where('opcoes.povosTradicionais.0.valor', 'nao_pertence')
            ->has('opcoes.tiposDeficiencia', 7)
            ->has('opcoes.escolaridades', 11)
            ->has('opcoes.faixasDeRenda', 7));
    }

    public function test_tipos_de_cadastro_exigem_login(): void
    {
        $this->get('/cadastro/pessoa-juridica')->assertRedirect('/');
    }

    public function test_rota_antiga_de_pessoa_fisica_nao_existe(): void
    {
        $this->actingAs(User::factory()->create())->get('/cadastro/pessoa-fisica')->assertNotFound();
    }

    public function test_primeiro_salvamento_segue_para_a_etapa_da_organizacao(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['profile_completed_at' => null, 'email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->post('/cadastro', $this->dadosValidos())
            ->assertRedirect(route('cadastro.organizacao'))
            ->assertSessionHas('sucesso', 'Dados salvos. Você também pode cadastrar uma Pessoa Jurídica.');
    }

    public function test_aviso_do_middleware_chega_a_tela_de_dados(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->followingRedirects()->get('/cadastro/organizacao')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cadastro/Completar')
                ->where('flash.aviso', 'Complete seus dados de Pessoa Física antes de cadastrar uma Pessoa Jurídica.'));
    }

    public function test_primeiro_salvamento_mostra_o_sucesso_na_etapa_da_organizacao(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['profile_completed_at' => null, 'email' => 'maria@example.com', 'phone' => '71998765432']);

        $this->actingAs($user)->followingRedirects()->post('/cadastro', $this->dadosValidos())
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cadastro/Organizacao')
                ->where('flash.sucesso', 'Dados salvos. Você também pode cadastrar uma Pessoa Jurídica.'));
    }

    public function test_salvamento_com_cadastro_finalizado_volta_para_o_cadastro(): void
    {
        Storage::fake('local');
        $cadastro = PessoaFisica::factory()->create();
        $cadastro->user->forceFill(['profile_completed_at' => now(), 'tipos_cadastro' => [TipoCadastro::Proponente], 'email' => 'maria@example.com', 'phone' => '71998765432'])->save();

        $this->actingAs($cadastro->user)->post('/cadastro', [
            ...$this->dadosValidos(),
            'documento_cpf' => null,
            'documento_rg' => null,
        ])->assertRedirect(route('cadastro'))->assertSessionMissing('sucesso');
    }

    public function test_salvamento_sem_tipo_de_cadastro_segue_para_a_etapa_da_organizacao(): void
    {
        Storage::fake('local');
        $cadastro = PessoaFisica::factory()->create();
        $cadastro->user->forceFill(['profile_completed_at' => now(), 'email' => 'maria@example.com', 'phone' => '71998765432'])->save();

        $this->actingAs($cadastro->user)->post('/cadastro', [
            ...$this->dadosValidos(),
            'documento_cpf' => null,
            'documento_rg' => null,
        ])->assertRedirect(route('cadastro.organizacao'));
    }

    public function test_etapa_da_organizacao_abre_com_a_pessoa_fisica_completa(): void
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);

        $this->actingAs($user)->get('/cadastro/organizacao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Cadastro/Organizacao')->where('organizacoes', []));
    }

    public function test_etapa_da_organizacao_lista_as_pessoas_juridicas_vinculadas(): void
    {
        $user = User::factory()->create(['profile_completed_at' => now()]);
        $minha = Organizacao::factory()->comResponsavel($user)->create(['razao_social' => 'Coletivo Teste LTDA', 'nome_fantasia' => null]);
        Organizacao::factory()->create();

        $this->actingAs($user)->get('/cadastro/organizacao')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cadastro/Organizacao')
                ->has('organizacoes', 1)
                ->where('organizacoes.0.id', $minha->id)
                ->where('organizacoes.0.nome', 'Coletivo Teste LTDA')
                ->where('organizacoes.0.cnpj', Cnpj::formatar($minha->cnpj))
                ->where('organizacoes.0.responsavel', true));
    }

    public function test_etapa_da_organizacao_exige_pessoa_fisica_completa(): void
    {
        $user = User::factory()->create(['profile_completed_at' => null]);

        $this->actingAs($user)->get('/cadastro/organizacao')
            ->assertRedirect(route('cadastro'))
            ->assertSessionHas('aviso', 'Complete seus dados de Pessoa Física antes de cadastrar uma Pessoa Jurídica.');
    }

    public function test_etapa_da_organizacao_exige_login(): void
    {
        $this->get('/cadastro/organizacao')->assertRedirect('/');
    }

    public function test_etapa_da_organizacao_bloqueia_usuario_da_retaguarda(): void
    {
        $gestor = User::factory()->gestor()->create(['profile_completed_at' => now()]);

        $this->actingAs($gestor)->get('/cadastro/organizacao')
            ->assertRedirect('/')
            ->assertSessionHas('erro');
    }

    public function test_logout_encerra_sessao(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
