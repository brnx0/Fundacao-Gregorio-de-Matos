<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\IdentidadeGenero;
use App\Enums\ModalidadeCota;
use App\Enums\RacaCor;
use App\Models\AreaAtuacao;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use App\Models\Evento;
use App\Models\Inscricao;
use App\Models\Organizacao;
use App\Models\PessoaFisica;
use App\Models\PrefeituraBairro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PainelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gestor()->create());
    }

    /** Pessoa do portal: usuário que enviou a etapa "Seus dados" (proponente, se não vier outro usuário). */
    private function pessoa(array $dados = [], ?User $user = null): User
    {
        $user ??= User::factory()->proponente()->create();
        PessoaFisica::factory()->for($user)->create($dados);

        return $user;
    }

    public function test_inicio_da_retaguarda_abre_o_painel_so_com_os_filtros(): void
    {
        $this->get('/retaguarda')->assertRedirect('/retaguarda/painel');

        $this->get('/retaguarda/painel')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Retaguarda/Painel')
            ->where('filtros', ['publico' => '', 'territorio' => '', 'edital' => '', 'de' => '', 'ate' => ''])
            ->has('opcoes.territorios', 11)
            ->where('opcoes.territorios.10', ['valor' => PrefeituraBairro::FORA_DE_SALVADOR, 'rotulo' => 'Fora de Salvador'])
            ->missing('pessoas'));
    }

    public function test_visitante_e_pessoa_do_portal_vao_para_o_login(): void
    {
        Auth::logout();
        $this->get('/retaguarda/painel')->assertRedirect('/retaguarda/login');

        $this->actingAs($this->pessoa());
        $this->get('/retaguarda/painel')->assertRedirect('/retaguarda/login');
    }

    public function test_visao_geral_traz_a_serie_dos_ultimos_meses_e_o_funil_do_cadastro(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00', 'America/Bahia'));
        $this->pessoa(user: User::factory()->proponente()->create(['profile_completed_at' => '2026-06-02 09:00']));
        $this->pessoa(user: User::factory()->patrocinador()->create(['profile_completed_at' => '2026-04-20 09:00']));
        User::factory()->create();

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('visao', fn (Assert $p) => $p
                ->where('visao.meses.11', '2026-06')
                ->where('visao.indicadores.pessoas.total', 2)
                ->where('visao.indicadores.pessoas.serie', [0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1])
                ->where('visao.indicadores.proponentes.total', 1)
                ->where('visao.indicadores.patrocinadores.total', 1)
                ->where('visao.funil', [
                    ['rotulo' => 'Entraram pelo gov.br', 'total' => 3, 'percentual' => 100],
                    ['rotulo' => 'Enviaram o perfil', 'total' => 2, 'percentual' => 66.7],
                    ['rotulo' => 'Escolheram o tipo de cadastro', 'total' => 2, 'percentual' => 66.7],
                    ['rotulo' => 'Enviaram inscrição', 'total' => 0, 'percentual' => 0],
                ])));
    }

    public function test_perfil_conta_so_pessoas_do_portal_na_ordem_do_enum(): void
    {
        $this->pessoa(['raca_cor' => RacaCor::Preta, 'identidade_genero' => IdentidadeGenero::MulherCis]);
        $this->pessoa(['raca_cor' => RacaCor::Parda, 'identidade_genero' => IdentidadeGenero::MulherTrans]);
        $this->pessoa(['raca_cor' => RacaCor::Branca, 'identidade_genero' => IdentidadeGenero::HomemCis]);
        $this->pessoa(['raca_cor' => RacaCor::Preta, 'identidade_genero' => IdentidadeGenero::NaoBinaria], User::factory()->gestor()->create());

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p
                ->where('pessoas.total', 3)
                ->where('pessoas.racaCor.itens.0', ['valor' => 'branca', 'rotulo' => 'Branca', 'total' => 1, 'percentual' => 33.3])
                ->where('pessoas.racaCor.itens.1', ['valor' => 'preta', 'rotulo' => 'Preta', 'total' => 1, 'percentual' => 33.3])
                ->where('pessoas.negras', ['total' => 2, 'percentual' => 66.7])
                ->where('pessoas.gruposDeGenero.0', ['valor' => 'mulheres', 'rotulo' => 'Mulheres', 'total' => 2, 'percentual' => 66.7])
                ->where('pessoas.indicadores.4', ['valor' => 'trans', 'rotulo' => 'Pessoas trans, travestis e não binárias', 'total' => 1, 'percentual' => 33.3])));
    }

    public function test_idade_em_anos_completos_por_faixa_com_cadastro_antigo_sem_resposta(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00', 'America/Bahia'));
        $this->pessoa(['data_nascimento' => '1996-06-16']);
        $this->pessoa(['data_nascimento' => '1996-06-15']);
        $this->pessoa(['data_nascimento' => '1966-06-15']);
        PessoaFisica::factory()->for(User::factory()->proponente())->semDataDeNascimento()->create();

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p
                ->where('pessoas.idade', [
                    'faixas' => [
                        ['rotulo' => 'Até 29 anos', 'total' => 1, 'percentual' => 33.3],
                        ['rotulo' => '30 a 39 anos', 'total' => 1, 'percentual' => 33.3],
                        ['rotulo' => '40 a 49 anos', 'total' => 0, 'percentual' => 0],
                        ['rotulo' => '50 a 59 anos', 'total' => 0, 'percentual' => 0],
                        ['rotulo' => '60 anos ou mais', 'total' => 1, 'percentual' => 33.3],
                    ],
                    'respostas' => 3,
                    'semResposta' => 1,
                ])));
    }

    public function test_areas_de_atuacao_contam_cada_pessoa_em_cada_area_marcada(): void
    {
        PessoaFisica::factory()->for(User::factory()->proponente())->comAreas('Música', 'Teatro')->create();
        PessoaFisica::factory()->for(User::factory()->patrocinador())->comAreas('Música', 'Livros')->create();
        PessoaFisica::factory()->for(User::factory()->proponente())->create();
        PessoaFisica::factory()->for(User::factory()->gestor())->comAreas('Música')->create();
        AreaAtuacao::whereIn('nome', ['Livros', 'Cinema'])->update(['ativo' => false]);
        $id = fn (string $nome): string => (string) AreaAtuacao::where('nome', $nome)->value('id');

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p
                // Cinema, desativada e sem ninguém, sai; Livros, desativada, conta para quem a marcou.
                ->has('pessoas.areas.itens', 30)
                ->where('pessoas.areas.itens.0', ['valor' => $id('Música'), 'rotulo' => 'Música', 'total' => 2, 'percentual' => 100])
                ->where('pessoas.areas.itens.1', ['valor' => $id('Livros'), 'rotulo' => 'Livros', 'total' => 1, 'percentual' => 50])
                ->where('pessoas.areas.itens.2', ['valor' => $id('Teatro'), 'rotulo' => 'Teatro', 'total' => 1, 'percentual' => 50])
                ->where('pessoas.areas.itens.3', ['valor' => $id('Arquitetura'), 'rotulo' => 'Arquitetura', 'total' => 0, 'percentual' => 0])
                ->where('pessoas.areas.itens.29.rotulo', 'Outros')
                ->where('pessoas.areas.respostas', 2)
                ->where('pessoas.areas.semResposta', 1)));
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function filtrosDasPessoas(): array
    {
        return [
            'público patrocinador (inclui quem marcou os dois)' => ['publico=patrocinador', 2],
            'território' => ['territorio=centro_brotas', 2],
            'período do cadastro' => ['de=2026-05-01&ate=2026-05-31', 1],
            'período com as datas trocadas' => ['de=2026-05-31&ate=2026-05-01', 1],
            'valores que não existem viram sem filtro' => ['publico=gestor&territorio=lua&de=2026-02-31&edital=999', 3],
        ];
    }

    #[DataProvider('filtrosDasPessoas')]
    public function test_filtros_recortam_as_pessoas(string $consulta, int $total): void
    {
        $this->pessoa(['territorio' => 'centro_brotas'], User::factory()->proponente()->create(['profile_completed_at' => '2026-03-10 09:00']));
        $this->pessoa(['territorio' => 'cajazeiras'], User::factory()->patrocinador()->create(['profile_completed_at' => '2026-05-20 09:00']));
        $this->pessoa(['territorio' => 'centro_brotas'], User::factory()->proponenteEPatrocinador()->create(['profile_completed_at' => '2026-06-01 09:00']));

        $this->get("/retaguarda/painel?{$consulta}")->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p->where('pessoas.total', $total)));
    }

    public function test_filtro_de_edital_limita_inscricoes_e_perfil_a_quem_enviou_inscricao_nele(): void
    {
        $edital = Edital::factory()->create();
        $ana = $this->pessoa();
        $bia = $this->pessoa();
        Inscricao::factory()->finalizada()->for($edital)->for($ana)->create();
        Inscricao::factory()->for($edital)->for($bia)->create();
        Inscricao::factory()->finalizada()->for(Edital::factory())->for($bia)->create();

        $this->get("/retaguarda/painel?edital={$edital->id}")->assertInertia(fn (Assert $p) => $p
            ->where('filtros.edital', (string) $edital->id)
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p->where('pessoas.total', 1))
            ->loadDeferredProps('inscricoes', fn (Assert $p) => $p
                ->where('inscricoes.enviadas', 1)
                ->where('inscricoes.rascunhos', 1)));
    }

    public function test_bairro_digitado_com_acento_ou_caixa_diferente_cai_no_bairro_oficial(): void
    {
        $this->pessoa(['territorio' => 'centro_brotas', 'bairro' => 'Nazaré']);
        $this->pessoa(['territorio' => 'centro_brotas', 'bairro' => '  NAZARE ']);
        $this->pessoa(['territorio' => 'centro_brotas', 'bairro' => 'Bairro Inventado']);
        $this->pessoa(['territorio' => 'centro_brotas', 'bairro' => 'bairro inventado']);
        $this->pessoa(['territorio' => 'centro_brotas', 'bairro' => 'Rua das Flores, 10']);
        $this->pessoa(['territorio' => PrefeituraBairro::FORA_DE_SALVADOR, 'bairro' => 'Nazaré', 'cidade' => 'Feira de Santana']);

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('territorio', fn (Assert $p) => $p
                ->where('territorio.bairros', [['chave' => 'nazare', 'nome' => 'Nazaré', 'total' => 2]])
                ->where('territorio.emSalvador', 5)
                ->where('territorio.mapeadas', 2)
                ->where('territorio.naoReconhecidas', 3)
                ->where('territorio.textosNaoReconhecidos', [['texto' => 'Bairro Inventado', 'total' => 2]])
                ->where('territorio.prefeituras.0', ['codigo' => 'centro_brotas', 'rotulo' => 'I – Centro/Brotas', 'total' => 5, 'percentual' => 83.3])));
    }

    public function test_tabela_por_territorio_esconde_o_percentual_com_menos_de_cinco_pessoas(): void
    {
        foreach (range(1, 5) as $indice) {
            $this->pessoa(['territorio' => 'centro_brotas', 'identidade_genero' => IdentidadeGenero::MulherCis]);
        }
        $this->pessoa(['territorio' => 'cajazeiras', 'identidade_genero' => IdentidadeGenero::MulherCis]);

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('inscricoes', fn (Assert $p) => $p
                ->where('evolucao.territorios.colunas.0', ['valor' => 'mulheres', 'rotulo' => 'Mulheres'])
                ->where('evolucao.territorios.linhas.0.base', 5)
                ->where('evolucao.territorios.linhas.0.valores.0', 100)
                ->where('evolucao.territorios.linhas.2.codigo', 'cajazeiras')
                ->where('evolucao.territorios.linhas.2.base', 1)
                ->where('evolucao.territorios.linhas.2.valores.0', null)));
    }

    public function test_investimento_soma_valor_vezes_vagas_das_categorias_e_dos_itens_dos_editais_publicados(): void
    {
        $edital = Edital::factory()->create();
        EditalCategoria::factory()->for($edital)->create(['valor_centavos' => 1_000_000, 'vagas' => 3]);
        $comItens = EditalCategoria::factory()->for($edital)->create(['valor_centavos' => 99_900_000, 'vagas' => 99]);
        EditalCategoriaItem::factory()->for($comItens, 'categoria')->create(['valor_centavos' => 500_000, 'vagas' => 2]);
        EditalCategoriaItem::factory()->for($comItens, 'categoria')->create(['valor_centavos' => null, 'vagas' => 4]);
        EditalCategoria::factory()->for(Edital::factory()->rascunho())->create(['valor_centavos' => 7_000_000, 'vagas' => 1]);

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('editais', fn (Assert $p) => $p
                ->where('editais.total', 2)
                ->where('editais.publicados', 1)
                ->where('editais.centavos', 4_000_000)
                ->where('editais.vagas', 9)
                ->where('editais.semValor', 1))
            ->loadDeferredProps('visao', fn (Assert $p) => $p
                ->where('visao.investimento', ['centavos' => 4_000_000, 'vagas' => 9])));
    }

    public function test_inscricoes_contam_enviadas_rascunhos_reabertas_cotas_e_procura_por_vaga(): void
    {
        $edital = Edital::factory()->create();
        EditalCategoria::factory()->for($edital)->create(['valor_centavos' => 1_000_000, 'vagas' => 2]);
        $ana = $this->pessoa(['raca_cor' => RacaCor::Preta]);
        $bia = $this->pessoa(['raca_cor' => RacaCor::Branca]);
        Inscricao::factory()->finalizada()->for($edital)->for($ana)->create(['cotas' => [ModalidadeCota::Negros]]);
        Inscricao::factory()->finalizada()->for($edital)->for($bia)->create(['cotas' => []]);
        Inscricao::factory()->for($edital)->for($bia)->create(['numero' => '2026000123']);
        Inscricao::factory()->for($edital)->for($ana)->create();

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('inscricoes', fn (Assert $p) => $p
                ->where('inscricoes.enviadas', 2)
                ->where('inscricoes.rascunhos', 2)
                ->where('inscricoes.reabertas', 1)
                ->where('inscricoes.conclusao', 50)
                ->where('inscricoes.cotas.0', ['valor' => 'negros', 'rotulo' => 'Pessoas negras', 'total' => 1, 'percentual' => 50])
                ->where('inscricoes.comparacao.1', ['valor' => 'negras', 'rotulo' => 'Pessoas negras', 'cadastro' => 50, 'inscricao' => 50]))
            ->loadDeferredProps('editais', fn (Assert $p) => $p
                ->where('editais.demanda.0.enviadas', 2)
                ->where('editais.demanda.0.rascunhos', 2)
                ->where('editais.demanda.0.porVaga', 1)
                ->where('editais.demanda.0.cotistas', 50)));
    }

    public function test_organizacoes_por_natureza_e_municipio(): void
    {
        Organizacao::factory()->create(['mei' => true, 'finalidade_lucrativa' => false]);
        Organizacao::factory()->create(['mei' => false, 'finalidade_lucrativa' => true]);
        Organizacao::factory()->create(['mei' => false, 'finalidade_lucrativa' => null, 'natureza_juridica' => '3999', 'municipio_ibge' => '2910800']);

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p
                ->where('organizacoes.naturezas', [
                    ['valor' => 'mei', 'rotulo' => 'MEI', 'total' => 1, 'percentual' => 33.3],
                    ['valor' => 'sem_fins', 'rotulo' => 'Pessoa Jurídica sem fins lucrativos', 'total' => 1, 'percentual' => 33.3],
                    ['valor' => 'com_fins', 'rotulo' => 'Pessoa Jurídica com fins lucrativos', 'total' => 1, 'percentual' => 33.3],
                ])
                ->where('organizacoes.grupos.2.total', 1)
                ->where('organizacoes.municipios.salvador', 2)
                ->where('organizacoes.municipios.itens', [['rotulo' => 'Feira de Santana/BA', 'total' => 1, 'percentual' => 33.3]])));
    }

    public function test_avaliadores_por_faixa_etaria_e_eventos_dos_proximos_trinta_dias(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00', 'America/Bahia'));
        Avaliador::factory()->create(['data_nascimento' => '1990-06-16']);
        Avaliador::factory()->create(['data_nascimento' => '1966-06-15']);
        Avaliador::factory()->inativo()->create();
        Evento::factory()->create(['data_realizacao' => '2026-07-15']);
        Evento::factory()->create(['data_realizacao' => '2026-07-16']);
        Evento::factory()->create(['data_realizacao' => '2026-06-14']);

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('editais', fn (Assert $p) => $p
                ->where('capacidade.avaliadores.ativos', 2)
                ->where('capacidade.avaliadores.inativos', 1)
                ->where('capacidade.avaliadores.idades.1', ['rotulo' => '30 a 39 anos', 'total' => 1, 'percentual' => 50])
                ->where('capacidade.avaliadores.idades.4', ['rotulo' => '60 anos ou mais', 'total' => 1, 'percentual' => 50])
                ->where('capacidade.eventos.proximos', 1)
                ->where('capacidade.eventos.realizados', 1)));
    }

    public function test_numeros_ficam_guardados_por_dez_minutos(): void
    {
        $this->pessoa();
        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p->where('pessoas.total', 1)));

        $this->pessoa();

        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p->where('pessoas.total', 1)));
        $this->travel(11)->minutes();
        $this->get('/retaguarda/painel')->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps('pessoas', fn (Assert $p) => $p->where('pessoas.total', 2)));
    }

    public function test_menu_abre_com_o_painel(): void
    {
        $menu = file_get_contents(resource_path('js/Components/Retaguarda/menuRetaguarda.ts'));

        $this->assertStringContainsString(route('retaguarda.painel', absolute: false), $menu);
        $this->assertStringContainsString("'Painel'", $menu);
    }
}
