<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\EtapaDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\SituacaoEdital;
use App\Enums\TipoEdital;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use App\Models\EditalChamada;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditalEstruturaSalvarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::factory()->gestor()->create());
    }

    /** Arquivo com o tipo vindo do conteúdo, como num upload real. */
    private function modelo(string $nome = 'modelo.pdf'): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    /**
     * Edital com todos os blocos, como o formulário envia.
     *
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dadosCompletos(array $alteracoes = []): array
    {
        return [
            'com_listas' => '1',
            'titulo' => 'Salvador Circula',
            'resumo' => 'Circulação de espetáculos.',
            'categoria' => 'Artes cênicas',
            'numero' => '007/2026',
            'tipo' => 'chamamento',
            'objeto' => 'Seleção de espetáculos para circulação.',
            'dom_data' => '2026-10-01',
            'dom_numero' => '1234',
            'naturezas' => ['pessoa_fisica', 'mei'],
            'aceita_coletivos' => '1',
            'inscricoes_por_proponente' => '2',
            'limite_por_chamada' => '0',
            'forma_inscricao_original' => 'Formulário on-line',
            'nota_maxima' => '100',
            'nota_corte' => '60.5',
            'publicado' => '0',
            'chamadas' => [
                ['nome' => 'Chamada 1', 'inicio' => '2026-11-10', 'fim' => '2026-11-30'],
                ['nome' => 'Chamada 2', 'inicio' => '2026-11-01', 'fim' => '2026-12-15'],
            ],
            'categorias' => [
                [
                    'nome' => 'Teatro',
                    'descricao' => 'Espetáculos de teatro.',
                    'valor' => '15000.50',
                    'vagas' => '4',
                    'naturezas' => ['pessoa_fisica'],
                    'chamada' => '1',
                    'itens' => [
                        ['nome' => 'Espaço A', 'valor' => '1000', 'vagas' => '1', 'suplentes' => '2'],
                    ],
                ],
                ['nome' => 'Dança'],
            ],
            'documentos' => [
                [
                    'nome' => 'Declaração de endereço',
                    'orientacao' => 'Assinada.',
                    'etapa' => 'habilitacao',
                    'checagem' => 'aceita_sem_validar',
                    'obrigatorio' => '1',
                    'naturezas' => ['mei'],
                    'todas_categorias' => '0',
                    'categorias' => ['1'],
                ],
                [
                    'nome' => 'Autodeclaração racial',
                    'etapa' => 'validacao_cotas',
                    'checagem' => 'rigorosa',
                    'obrigatorio' => '0',
                    'todas_categorias' => '1',
                    'modalidade_cota' => 'negros',
                ],
            ],
            'criterios' => [
                ['nome' => 'Mérito', 'descricao' => 'Qualidade.', 'peso' => '2', 'pontos_maximos' => '50', 'todas_categorias' => '0', 'categorias' => ['0']],
            ],
            'cotas' => [
                'negros' => ['ativa' => '1', 'percentual' => '25', 'quantidade' => ''],
                'indigenas' => ['ativa' => '1', 'percentual' => '10', 'quantidade' => ''],
                'pcd' => ['ativa' => '0', 'percentual' => '', 'quantidade' => ''],
                'base' => 'vagas',
                'alcance' => 'categoria',
                'veda_negro_indigena' => '1',
                'heteroidentificacao' => '0',
            ],
            'pontuacao_extra' => [
                'grupos' => [['grupo' => 'Mulheres', 'pontos_por_pessoa' => '1', 'maximo' => '5']],
                'teto' => '10',
                'conta_para_corte' => '0',
            ],
            'informacoes' => [
                'vedacoes' => 'Servidores da FGM.',
                'contatos' => 'editais@fgm.gov.br',
                'base_legal' => '',
            ],
            ...$alteracoes,
        ];
    }

    public function test_salva_todos_os_blocos_e_le_de_volta_na_edicao(): void
    {
        $this->post('/retaguarda/editais', $this->dadosCompletos())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/editais');

        $edital = Edital::sole();
        $this->assertSame(TipoEdital::Chamamento, $edital->tipo);
        $this->assertEquals([NaturezaProponente::PessoaFisica, NaturezaProponente::Mei], $edital->naturezas->all());
        $this->assertTrue($edital->aceita_coletivos);
        $this->assertFalse($edital->limite_por_chamada);
        $this->assertSame(2, $edital->inscricoes_por_proponente);
        $this->assertSame(['vedacoes' => 'Servidores da FGM.', 'contatos' => 'editais@fgm.gov.br'], $edital->informacoes);
        $this->assertSame(
            ['grupos' => [['grupo' => 'Mulheres', 'pontos_por_pessoa' => 1, 'maximo' => 5]], 'teto' => 10, 'conta_para_corte' => false],
            $edital->pontuacao_extra,
        );
        $this->assertSame(['ativa' => true, 'percentual' => 25, 'quantidade' => null], $edital->cotas['negros']);
        $this->assertSame(['ativa' => false, 'percentual' => null, 'quantidade' => null], $edital->cotas['pcd']);
        $this->assertSame('vagas', $edital->cotas['base']);
        $this->assertTrue($edital->cotas['veda_negro_indigena']);
        $this->assertFalse($edital->cotas['heteroidentificacao']);

        [$teatro, $danca] = $edital->categorias->all();
        $this->assertSame(1500050, $teatro->valor_centavos);
        $this->assertSame('Chamada 2', $teatro->chamada->nome);
        $this->assertNull($danca->naturezas);
        $this->assertSame(['Espaço A'], $teatro->itens->pluck('nome')->all());

        [$declaracao, $autodeclaracao] = $edital->documentos->all();
        $this->assertSame(EtapaDocumento::Habilitacao, $declaracao->etapa);
        $this->assertFalse($declaracao->todas_categorias);
        $this->assertSame(['Dança'], $declaracao->categorias->pluck('nome')->all());
        $this->assertSame(ModalidadeCota::Negros, $autodeclaracao->modalidade_cota);
        $this->assertFalse($autodeclaracao->obrigatorio);
        $this->assertSame(['Teatro'], $edital->criterios->sole()->categorias->pluck('nome')->all());

        $this->get("/retaguarda/editais/{$edital->slug}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Editais/Form')
                ->where('edital.numero', '007/2026')
                ->where('edital.tipo', 'chamamento')
                ->where('edital.naturezas', ['pessoa_fisica', 'mei'])
                ->where('edital.chamadas.1.nome', 'Chamada 2')
                ->where('edital.chamadas.1.inicio', '2026-11-01')
                ->where('edital.categorias.0.valor', 15000.5)
                ->where('edital.categorias.0.chamada', 1)
                ->where('edital.categorias.0.itens.0.suplentes', 2)
                ->where('edital.documentos.0.categorias', [1])
                ->where('edital.criterios.0.categorias', [0])
                ->where('edital.informacoes.vedacoes', 'Servidores da FGM.')
                ->where('edital.informacoes.base_legal', null)
                ->has('naturezas', 4)
                ->has('etapas', 3)
                ->has('modalidadesCota', 3)
                ->has('basesCota', 2)
                ->has('alcancesCota', 3)
                ->has('tiposEdital', 2)
                ->where('pendencias', ['Envie o PDF do edital.']));
    }

    public function test_datas_do_edital_vem_das_chamadas(): void
    {
        $this->travelTo(Carbon::parse('2026-12-10 10:00', 'America/Bahia'));

        $this->post('/retaguarda/editais', $this->dadosCompletos())->assertSessionHasNoErrors();

        $edital = Edital::sole();
        $this->assertSame('2026-11-01', $edital->inscricoes_inicio->toDateString());
        $this->assertSame('2026-12-15', $edital->inscricoes_fim->toDateString());
        $this->assertSame(SituacaoEdital::Aberto, $edital->situacao());

        $this->put("/retaguarda/editais/{$edital->slug}", ['com_listas' => '1', 'titulo' => 'Sem chamadas'])->assertSessionHasNoErrors();

        $edital->refresh();
        $this->assertNull($edital->inscricoes_inicio);
        $this->assertNull($edital->inscricoes_fim);
    }

    public function test_editar_preserva_ids_remove_itens_e_ignora_ids_de_outro_edital(): void
    {
        $edital = Edital::factory()->create();
        $chamada = EditalChamada::factory()->for($edital)->create(['nome' => 'Antiga']);
        $removida = EditalChamada::factory()->for($edital)->create(['nome' => 'Removida']);
        $categoria = EditalCategoria::factory()->for($edital)->create(['nome' => 'Teatro']);
        $item = EditalCategoriaItem::factory()->for($categoria, 'categoria')->create(['nome' => 'Item antigo']);
        EditalCategoriaItem::factory()->for($categoria, 'categoria')->create(['nome' => 'Item removido']);
        $deOutro = EditalChamada::factory()->for(Edital::factory())->create(['nome' => 'De outro edital']);

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'titulo' => $edital->titulo,
            'chamadas' => [
                ['id' => $chamada->id, 'nome' => 'Renomeada', 'inicio' => '2026-11-01', 'fim' => '2026-11-30'],
                ['id' => $deOutro->id, 'nome' => 'Nova', 'inicio' => '2026-12-01', 'fim' => '2026-12-20'],
            ],
            'categorias' => [
                ['id' => $categoria->id, 'nome' => 'Teatro', 'itens' => [['id' => $item->id, 'nome' => 'Item editado']]],
            ],
        ])->assertSessionHasNoErrors();

        $chamadas = $edital->chamadas()->get();
        $this->assertSame(['Renomeada', 'Nova'], $chamadas->pluck('nome')->all());
        $this->assertEquals($chamada->id, $chamadas[0]->id);
        $this->assertNotEquals($deOutro->id, $chamadas[1]->id);
        $this->assertSame('De outro edital', $deOutro->refresh()->nome);
        $this->assertModelMissing($removida);

        $this->assertEquals($categoria->id, $edital->categorias()->sole()->id);
        $this->assertSame(['Item editado'], $categoria->itens()->pluck('nome')->all());
        $this->assertEquals($item->id, $categoria->itens()->sole()->id);
    }

    public function test_sem_marcador_as_listas_ficam_intactas(): void
    {
        $edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::Mei]]);
        EditalChamada::factory()->for($edital)->create();
        EditalCategoria::factory()->for($edital)->create();

        $this->put("/retaguarda/editais/{$edital->slug}", ['titulo' => 'Novo título'])->assertSessionHasNoErrors();

        $edital->refresh();
        $this->assertSame('Novo título', $edital->titulo);
        $this->assertSame(1, $edital->chamadas()->count());
        $this->assertSame(1, $edital->categorias()->count());
        $this->assertEquals([NaturezaProponente::Mei], $edital->naturezas->all());
    }

    public function test_com_marcador_lista_ausente_apaga_todas(): void
    {
        $edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::Mei]]);
        EditalChamada::factory()->for($edital)->create();
        EditalCategoria::factory()->for($edital)->create();

        $this->put("/retaguarda/editais/{$edital->slug}", ['com_listas' => '1', 'titulo' => 'Título'])->assertSessionHasNoErrors();

        $edital->refresh();
        $this->assertSame(0, $edital->chamadas()->count());
        $this->assertSame(0, $edital->categorias()->count());
        $this->assertNull($edital->naturezas);
    }

    public function test_rascunho_so_com_titulo_e_rascunho_sem_titulo(): void
    {
        $this->post('/retaguarda/editais', ['titulo' => 'Só o título'])->assertSessionHasNoErrors();
        $this->post('/retaguarda/editais', [])->assertSessionHasNoErrors();
        $this->post('/retaguarda/editais', [])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['so-o-titulo', 'edital', 'edital-2'], Edital::pluck('slug')->all());

        $semTitulo = Edital::where('slug', 'edital')->sole();
        $this->assertNull($semTitulo->titulo);
        $this->assertFalse($semTitulo->publicado);

        $this->get('/retaguarda/editais/edital/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('edital.titulo', null)
                ->where('edital.inscricoes_inicio', null)
                ->where('edital.chamadas', [])
                ->where('pendencias', [
                    'Informe o título do edital.',
                    'Envie o PDF do edital.',
                    'Cadastre ao menos uma chamada com início e fim das inscrições.',
                    'Marque ao menos uma natureza que pode se inscrever.',
                    'Cadastre ao menos uma categoria.',
                ]));
    }

    public function test_salva_e_reabre_as_fases_de_cada_chamada(): void
    {
        $this->post('/retaguarda/editais', $this->dadosCompletos([
            'chamadas' => [[
                'nome' => 'Chamada 1',
                'inicio' => '2026-11-10',
                'fim' => '2026-11-30',
                'validacao_inicio' => '2026-12-01',
                'validacao_fim' => '2026-12-10',
                'homologacao_inicio' => '2026-12-15',
                'homologacao_fim' => '2026-12-20',
            ]],
            'categorias' => [],
            'documentos' => [],
            'criterios' => [],
        ]))->assertSessionHasNoErrors();

        $edital = Edital::sole();
        $chamada = $edital->chamadas()->sole();
        $this->assertSame('2026-12-01', $chamada->validacao_inicio->toDateString());
        $this->assertSame('2026-12-10', $chamada->validacao_fim->toDateString());
        $this->assertSame('2026-12-15', $chamada->homologacao_inicio->toDateString());
        $this->assertSame('2026-12-20', $chamada->homologacao_fim->toDateString());

        $this->get("/retaguarda/editais/{$edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p
                ->where('edital.chamadas.0.validacao_inicio', '2026-12-01')
                ->where('edital.chamadas.0.validacao_fim', '2026-12-10')
                ->where('edital.chamadas.0.homologacao_inicio', '2026-12-15')
                ->where('edital.chamadas.0.homologacao_fim', '2026-12-20'));
    }

    public function test_fases_sem_datas_ficam_vazias(): void
    {
        $this->post('/retaguarda/editais', $this->dadosCompletos())->assertSessionHasNoErrors();

        $chamada = Edital::sole()->chamadas()->first();
        $this->assertNull($chamada->validacao_inicio);
        $this->assertNull($chamada->validacao_fim);
        $this->assertNull($chamada->homologacao_inicio);
        $this->assertNull($chamada->homologacao_fim);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function fasesComSoUmaData(): array
    {
        return [
            'só início da avaliação' => ['validacao_inicio', 'validacao_fim'],
            'só fim da avaliação' => ['validacao_fim', 'validacao_inicio'],
            'só início da homologação' => ['homologacao_inicio', 'homologacao_fim'],
            'só fim da homologação' => ['homologacao_fim', 'homologacao_inicio'],
        ];
    }

    #[DataProvider('fasesComSoUmaData')]
    public function test_fase_com_so_uma_data_e_recusada(string $enviada, string $faltante): void
    {
        $mensagens = [
            'homologacao_inicio' => 'Preencha também o início da homologação.',
            'homologacao_fim' => 'Preencha também o fim da homologação.',
            'validacao_inicio' => 'Preencha também o início da avaliação.',
            'validacao_fim' => 'Preencha também o fim da avaliação.',
        ];

        $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'chamadas' => [['nome' => 'C', 'inicio' => '2026-11-01', 'fim' => '2026-11-30', $enviada => '2026-12-05']],
        ])->assertSessionHasErrors(["chamadas.0.{$faltante}" => $mensagens[$faltante]]);

        if (str_ends_with($enviada, '_fim')) {
            $this->assertFalse(session('errors')->has("chamadas.0.{$enviada}"));
        }

        $this->assertSame(0, Edital::count());
    }

    public function test_fim_da_fase_antes_do_inicio_e_recusado(): void
    {
        $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'chamadas' => [['nome' => 'C', 'inicio' => '2026-11-01', 'fim' => '2026-11-30', 'validacao_inicio' => '2026-12-10', 'validacao_fim' => '2026-12-01']],
        ])->assertSessionHasErrors(['chamadas.0.validacao_fim' => 'O fim deve ser igual ou posterior ao início.']);
    }

    public function test_periodos_mostram_homologacao_antes_da_avaliacao(): void
    {
        $vue = file_get_contents(resource_path('js/Components/Retaguarda/Edital/ChamadasEdital.vue'));

        $this->assertStringNotContainsString('>Validação<', $vue);
        $this->assertLessThan(strpos($vue, '>Avaliação</legend>'), strpos($vue, '>Homologação</legend>'));
    }

    public function test_publica_sem_validacao_e_homologacao(): void
    {
        $this->post('/retaguarda/editais', $this->dadosCompletos(['publicado' => '1', 'pdf' => $this->modelo('edital.pdf')]))
            ->assertSessionHasNoErrors();

        $edital = Edital::sole();
        $this->assertTrue($edital->publicado);
        $this->assertNull($edital->chamadas()->first()->validacao_inicio);
    }

    public function test_validacao_das_listas_com_mensagens_sem_chave_tecnica(): void
    {
        $resposta = $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'chamadas' => [['nome' => '', 'inicio' => '2026-11-30', 'fim' => '2026-11-01']],
            'categorias' => [['nome' => 'Teatro', 'valor' => '0', 'vagas' => '0', 'chamada' => '5', 'naturezas' => ['outra']]],
            'documentos' => [['nome' => 'Doc', 'etapa' => 'qualquer', 'todas_categorias' => '0']],
            'criterios' => [['nome' => 'Crit', 'peso' => '-1']],
            'cotas' => ['negros' => ['percentual' => '120']],
            'pontuacao_extra' => ['grupos' => [['grupo' => '']]],
        ]);

        $resposta->assertSessionHasErrors([
            'chamadas.0.nome' => 'Este campo é obrigatório.',
            'chamadas.0.fim' => 'O fim deve ser igual ou posterior ao início.',
            'categorias.0.valor' => 'Informe um valor maior que zero.',
            'categorias.0.vagas' => 'Informe um valor de no mínimo 1.',
            'categorias.0.chamada' => 'Selecione uma opção da lista.',
            'categorias.0.naturezas.0' => 'Selecione uma opção da lista.',
            'documentos.0.etapa' => 'Selecione uma opção da lista.',
            'documentos.0.categorias' => 'Escolha ao menos uma categoria.',
            'criterios.0.peso' => 'Informe um valor de no mínimo 0.',
            'cotas.negros.percentual' => 'Informe um valor de no máximo 100.',
            'pontuacao_extra.grupos.0.grupo' => 'Este campo é obrigatório.',
        ]);
        $this->assertSame(0, Edital::count());

        foreach (session('errors')->getBag('default')->all() as $mensagem) {
            $this->assertDoesNotMatchRegularExpression('/[a-z_]+\.(\d+|\*)/', $mensagem);
        }
    }

    public function test_itens_diferentes_podem_repetir_a_mesma_escolha(): void
    {
        $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'titulo' => 'Repetições',
            'categorias' => [
                ['nome' => 'Teatro', 'naturezas' => ['pessoa_fisica']],
                ['nome' => 'Dança', 'naturezas' => ['pessoa_fisica']],
            ],
            'documentos' => [
                ['nome' => 'Doc 1', 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '0', 'categorias' => ['0'], 'naturezas' => ['mei']],
                ['nome' => 'Doc 2', 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '0', 'categorias' => ['0'], 'naturezas' => ['mei']],
            ],
            'criterios' => [
                ['nome' => 'Crit 1', 'todas_categorias' => '0', 'categorias' => ['1']],
                ['nome' => 'Crit 2', 'todas_categorias' => '0', 'categorias' => ['1']],
            ],
        ])->assertSessionHasNoErrors();

        $edital = Edital::sole();
        $this->assertSame([['Teatro'], ['Teatro']], $edital->documentos->map(fn ($d) => $d->categorias->pluck('nome')->all())->all());
        $this->assertSame([['Dança'], ['Dança']], $edital->criterios->map(fn ($c) => $c->categorias->pluck('nome')->all())->all());
        $this->assertEquals([[NaturezaProponente::PessoaFisica], [NaturezaProponente::PessoaFisica]], $edital->categorias->map(fn ($c) => $c->naturezas->all())->all());
    }

    public function test_edital_publicado_nao_fica_sem_titulo_resumo_ou_categoria(): void
    {
        $edital = Edital::factory()->create(['publicado' => true, 'titulo' => 'Título original']);

        $this->put("/retaguarda/editais/{$edital->slug}", ['titulo' => '', 'resumo' => '', 'categoria' => '', 'publicado' => '1'])
            ->assertSessionHasErrors([
                'titulo' => 'Este campo é obrigatório.',
                'resumo' => 'Este campo é obrigatório.',
                'categoria' => 'Este campo é obrigatório.',
            ]);

        $edital->refresh();
        $this->assertSame('Título original', $edital->titulo);
        $this->assertNotNull($edital->resumo);
        $this->assertNotNull($edital->categoria);
        $this->assertTrue($edital->publicado);
    }

    public function test_texto_acentuado_que_cabe_em_caracteres_mas_nao_em_bytes_e_recusado(): void
    {
        // 600 caracteres e 1200 bytes: passa no max:1000, estouraria o VARCHAR2(1000) em bytes.
        $acentuado = str_repeat('ã', 600);
        $mensagem = 'Texto longo demais: use no máximo 1000 caracteres sem acento (o limite conta acentos como mais de um).';

        $this->post('/retaguarda/editais', [
            'com_listas' => '1',
            'titulo' => 'Edital com acentos',
            'objeto' => $acentuado,
        ])->assertSessionHasErrors(['objeto' => $mensagem]);

        $this->assertSame(0, Edital::count());
    }

    public function test_lista_com_mais_de_50_itens_e_recusada(): void
    {
        $chamadas = array_fill(0, 51, ['nome' => 'C', 'inicio' => '2026-11-01', 'fim' => '2026-11-02']);

        $this->post('/retaguarda/editais', ['com_listas' => '1', 'chamadas' => $chamadas])
            ->assertSessionHasErrors(['chamadas' => 'Cadastre no máximo 50 itens.']);
    }

    public function test_publicar_incompleto_pela_tela_recusa_com_as_pendencias_e_completo_publica(): void
    {
        $rascunho = Edital::factory()->rascunho()->create(['titulo' => 'Rascunho', 'pdf' => 'editais/r.pdf']);

        $this->patch("/retaguarda/editais/{$rascunho->slug}/publicacao")
            ->assertSessionHas('erro', 'Cadastre ao menos uma chamada com início e fim das inscrições. Marque ao menos uma natureza que pode se inscrever. Cadastre ao menos uma categoria.');
        $this->assertFalse($rascunho->refresh()->publicado);

        $rascunho->update(['naturezas' => [NaturezaProponente::PessoaFisica]]);
        EditalChamada::factory()->for($rascunho)->create();
        EditalCategoria::factory()->for($rascunho)->create();

        $this->patch("/retaguarda/editais/{$rascunho->slug}/publicacao")->assertSessionHas('sucesso', 'Edital publicado.');
        $this->assertTrue($rascunho->refresh()->publicado);
    }

    public function test_salvar_publicando_exige_o_edital_completo(): void
    {
        $pdf = $this->modelo('edital.pdf');

        $this->post('/retaguarda/editais', ['titulo' => 'Incompleto', 'resumo' => 'R', 'categoria' => 'C', 'publicado' => '1', 'pdf' => $pdf])
            ->assertSessionHasErrors(['publicado' => 'Cadastre ao menos uma chamada com início e fim das inscrições. Marque ao menos uma natureza que pode se inscrever. Cadastre ao menos uma categoria.']);
        $this->assertSame(0, Edital::count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->post('/retaguarda/editais', $this->dadosCompletos(['publicado' => '1', 'pdf' => $this->modelo('edital.pdf')]))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Edital::sole()->publicado);
    }

    public function test_edital_ja_publicado_continua_editavel_sem_os_blocos_novos(): void
    {
        $antigo = Edital::factory()->create(['publicado' => true]);

        $this->put("/retaguarda/editais/{$antigo->slug}", ['titulo' => 'Antigo editado', 'resumo' => 'R', 'categoria' => 'C', 'publicado' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($antigo->refresh()->publicado);
    }

    /**
     * @param  list<EditalDocumento>  $documentos
     * @return array<string, mixed>
     */
    private function envioDeDocumentos(Edital $edital, array $documentos): array
    {
        return [
            'com_listas' => '1',
            'titulo' => $edital->titulo,
            'documentos' => array_map(fn (EditalDocumento $documento): array => [
                'id' => $documento->id, 'nome' => $documento->nome, 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '1',
            ], $documentos),
        ];
    }

    public function test_com_inscricao_decidida_nao_remove_documento_exigido(): void
    {
        $edital = Edital::factory()->create();
        [$primeiro] = EditalDocumento::factory()->count(2)->for($edital)->create()->all();
        Inscricao::factory()->homologada()->create(['edital_id' => $edital->id]);

        $this->put("/retaguarda/editais/{$edital->slug}", $this->envioDeDocumentos($edital, [$primeiro]))
            ->assertSessionHasErrors(['documentos' => Edital::AVISO_DOCUMENTOS_TRAVADOS]);

        $this->assertSame(2, $edital->documentos()->count());
    }

    public function test_com_inscricao_decidida_acrescenta_e_edita_documento(): void
    {
        $edital = Edital::factory()->create();
        [$primeiro, $segundo] = EditalDocumento::factory()->count(2)->for($edital)->create()->all();
        Inscricao::factory()->naoHomologada()->create(['edital_id' => $edital->id]);

        $dados = $this->envioDeDocumentos($edital, [$primeiro, $segundo]);
        $dados['documentos'][0]['nome'] = 'Nome trocado';
        $dados['documentos'][] = ['nome' => 'Documento novo', 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '1'];

        $this->put("/retaguarda/editais/{$edital->slug}", $dados)->assertSessionHasNoErrors();

        $this->assertSame(3, $edital->documentos()->count());
        $this->assertSame('Nome trocado', $primeiro->refresh()->nome);
        $this->assertTrue($edital->documentos()->where('nome', 'Documento novo')->exists());
    }

    public function test_sem_decisao_remove_documento_normalmente(): void
    {
        $edital = Edital::factory()->create();
        [$primeiro] = EditalDocumento::factory()->count(2)->for($edital)->create()->all();
        Inscricao::factory()->finalizada()->create(['edital_id' => $edital->id]);

        $this->put("/retaguarda/editais/{$edital->slug}", $this->envioDeDocumentos($edital, [$primeiro]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $edital->documentos()->count());
    }

    public function test_decisao_durante_o_salvamento_trava_a_remocao(): void
    {
        $edital = Edital::factory()->create();
        [$primeiro] = EditalDocumento::factory()->count(2)->for($edital)->create()->all();

        $ativo = true;
        Edital::saving(function (Edital $salvando) use (&$ativo): void {
            if ($ativo && $salvando->exists) {
                $ativo = false;
                Inscricao::factory()->homologada()->create(['edital_id' => $salvando->id]);
            }
        });

        $this->put("/retaguarda/editais/{$edital->slug}", $this->envioDeDocumentos($edital, [$primeiro]))
            ->assertSessionHasErrors(['documentos' => Edital::AVISO_DOCUMENTOS_TRAVADOS]);

        $this->assertFalse($ativo);
        $this->assertSame(2, $edital->documentos()->count());
    }

    public function test_tela_de_edicao_avisa_a_trava_dos_documentos(): void
    {
        $edital = Edital::factory()->create();

        $this->get("/retaguarda/editais/{$edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('documentosTravados', false)->where('avisoDocumentosTravados', Edital::AVISO_DOCUMENTOS_TRAVADOS));

        Inscricao::factory()->homologada()->create(['edital_id' => $edital->id]);

        $this->get("/retaguarda/editais/{$edital->slug}/edit")
            ->assertInertia(fn (Assert $p) => $p->where('documentosTravados', true));
    }

    public function test_arquivo_modelo_enviar_manter_trocar_e_remover(): void
    {
        $edital = Edital::factory()->create();
        $documento = fn (array $extra = []): array => [
            'com_listas' => '1',
            'titulo' => $edital->titulo,
            'documentos' => [['nome' => 'Declaração', 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '1', ...$extra]],
        ];

        $this->put("/retaguarda/editais/{$edital->slug}", $documento(['modelo' => $this->modelo('declaração.pdf')]))
            ->assertSessionHasNoErrors();
        $salvo = EditalDocumento::sole();
        $this->assertSame('declaração.pdf', $salvo->modelo_nome);
        Storage::disk('local')->assertExists($salvo->modelo);
        $primeiro = $salvo->modelo;

        $this->put("/retaguarda/editais/{$edital->slug}", $documento(['id' => $salvo->id]))->assertSessionHasNoErrors();
        $this->assertSame($primeiro, $salvo->refresh()->modelo);

        $this->put("/retaguarda/editais/{$edital->slug}", $documento(['id' => $salvo->id, 'modelo' => $this->modelo('novo.pdf')]))
            ->assertSessionHasNoErrors();
        $salvo->refresh();
        $this->assertNotSame($primeiro, $salvo->modelo);
        Storage::disk('local')->assertMissing($primeiro);
        Storage::disk('local')->assertExists($salvo->modelo);
        $segundo = $salvo->modelo;

        $this->put("/retaguarda/editais/{$edital->slug}", $documento(['id' => $salvo->id, 'remover_modelo' => '1']))
            ->assertSessionHasNoErrors();
        $salvo->refresh();
        $this->assertNull($salvo->modelo);
        $this->assertNull($salvo->modelo_nome);
        Storage::disk('local')->assertMissing($segundo);
    }

    public function test_remover_documento_apaga_o_arquivo_modelo(): void
    {
        $edital = Edital::factory()->create();
        $documento = EditalDocumento::factory()->for($edital)->create(['modelo' => 'editais/modelos/m.pdf', 'modelo_nome' => 'm.pdf']);
        Storage::disk('local')->put('editais/modelos/m.pdf', 'pdf');

        $this->put("/retaguarda/editais/{$edital->slug}", ['com_listas' => '1', 'titulo' => 'T'])->assertSessionHasNoErrors();

        $this->assertModelMissing($documento);
        Storage::disk('local')->assertMissing('editais/modelos/m.pdf');
    }

    public function test_arquivo_modelo_de_tipo_nao_aceito_e_recusado(): void
    {
        $edital = Edital::factory()->create();

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'documentos' => [['nome' => 'Doc', 'etapa' => 'inscricao', 'checagem' => 'nenhuma', 'todas_categorias' => '1', 'modelo' => UploadedFile::fake()->createWithContent('x.exe', 'MZ binário')]],
        ])->assertSessionHasErrors(['documentos.0.modelo' => 'Envie um arquivo PDF, DOC, DOCX, XLS, XLSX, ODT ou ODS.']);
    }

    public function test_retaguarda_baixa_o_modelo_so_do_proprio_edital(): void
    {
        $edital = Edital::factory()->create(['publicado' => false]);
        $documento = EditalDocumento::factory()->for($edital)->create(['modelo' => 'editais/modelos/m.pdf', 'modelo_nome' => 'Modelo.pdf']);
        Storage::disk('local')->put('editais/modelos/m.pdf', 'pdf');
        $outro = Edital::factory()->create();

        $this->get("/retaguarda/editais/{$edital->slug}/documentos/{$documento->id}/modelo")
            ->assertOk()
            ->assertDownload('Modelo.pdf');
        $this->get("/retaguarda/editais/{$outro->slug}/documentos/{$documento->id}/modelo")->assertNotFound();
    }
}
