<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\NaturezaProponente;
use App\Enums\ProvedorIa;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalChamada;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\ImportacaoEdital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class EditaisTest extends TestCase
{
    use RefreshDatabase;

    /** PNG de 1×1 pixel (o container não tem GD para UploadedFile::fake()->image()). */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::factory()->gestor()->create());
    }

    /**
     * Arquivo enviado como num upload real: o tipo vem do conteúdo (finfo), não da extensão.
     * UploadedFile::fake() informa o tipo pela extensão e não serviria para o arquivo falso.
     */
    private function arquivoEnviado(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function pdf(string $nome = 'edital.pdf'): UploadedFile
    {
        return $this->arquivoEnviado($nome, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function imagem(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('capa.png', base64_decode(self::PNG));
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     * @return array<string, mixed>
     */
    private function dados(array $alteracoes = []): array
    {
        return [
            'titulo' => 'Prêmio Cultura Viva',
            'resumo' => 'Apoio a pontos de cultura.',
            'categoria' => 'Fomento',
            'publicado' => '0',
            ...$alteracoes,
        ];
    }

    /**
     * Edital com tudo o que a publicação exige além do PDF.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function editalCompleto(array $atributos): Edital
    {
        $edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::PessoaFisica], ...$atributos]);
        EditalChamada::factory()->for($edital)->create();
        EditalCategoria::factory()->for($edital)->create();

        return $edital;
    }

    public function test_lista_diz_se_o_edital_tem_categoria_e_quantas_perguntas(): void
    {
        $comPerguntas = Edital::factory()->create(['titulo' => 'Com perguntas', 'inscricoes_inicio' => '2026-12-01']);
        EditalCategoria::factory()->for($comPerguntas)->create();
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $comPerguntas->id]);
        EditalPergunta::factory()->count(2)->create(['edital_id' => $comPerguntas->id, 'edital_questionario_secao_id' => $secao->id]);
        Edital::factory()->create(['titulo' => 'Sem categoria', 'inscricoes_inicio' => '2026-11-01']);

        $this->get('/retaguarda/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('editais.data.0.titulo', 'Com perguntas')
                ->where('editais.data.0.temCategorias', true)
                ->where('editais.data.0.perguntas', 2)
                ->where('editais.data.1.titulo', 'Sem categoria')
                ->where('editais.data.1.temCategorias', false)
                ->where('editais.data.1.perguntas', 0));
    }

    public function test_lista_com_busca_e_filtros(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Bahia'));
        Edital::factory()->create(['titulo' => 'Prêmio Cultura Viva', 'inscricoes_inicio' => '2026-11-01', 'inscricoes_fim' => '2026-11-30']);
        Edital::factory()->create(['titulo' => 'Edital de Audiovisual', 'inscricoes_inicio' => '2026-12-01', 'inscricoes_fim' => '2026-12-20']);
        Edital::factory()->semPdf()->create(['titulo' => 'Rede de Leitura', 'publicado' => false, 'inscricoes_inicio' => '2026-10-01', 'inscricoes_fim' => '2026-11-09']);
        Edital::factory()->create(['titulo' => 'Cultura excluída'])->delete();

        $this->get('/retaguarda/editais')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Editais/Index')
                ->has('editais.data', 3)
                ->where('editais.per_page', 15));

        $this->get('/retaguarda/editais?busca=CULTURA')
            ->assertInertia(fn (Assert $p) => $p
                ->has('editais.data', 1)
                ->where('editais.data.0.titulo', 'Prêmio Cultura Viva')
                ->where('editais.data.0.periodo', '01/11/2026 a 30/11/2026')
                ->where('editais.data.0.situacao', 'aberto')
                ->where('editais.data.0.publicado', true)
                ->where('editais.data.0.temPdf', true)
                ->where('filtros.busca', 'CULTURA'));

        $this->get('/retaguarda/editais?situacao=em_breve')
            ->assertInertia(fn (Assert $p) => $p->has('editais.data', 1)->where('editais.data.0.titulo', 'Edital de Audiovisual'));

        $this->get('/retaguarda/editais?situacao=encerrado')
            ->assertInertia(fn (Assert $p) => $p->has('editais.data', 1)->where('editais.data.0.titulo', 'Rede de Leitura'));

        $this->get('/retaguarda/editais?situacao=aberto')
            ->assertInertia(fn (Assert $p) => $p->has('editais.data', 1)->where('editais.data.0.titulo', 'Prêmio Cultura Viva'));

        $this->get('/retaguarda/editais?publicado=nao')
            ->assertInertia(fn (Assert $p) => $p
                ->has('editais.data', 1)
                ->where('editais.data.0.titulo', 'Rede de Leitura')
                ->where('editais.data.0.temPdf', false));

        $this->get('/retaguarda/editais?publicado=sim')
            ->assertInertia(fn (Assert $p) => $p->has('editais.data', 2));
    }

    public function test_cria_edital_com_pdf_e_imagem(): void
    {
        $this->post('/retaguarda/editais', $this->dados([
            'com_listas' => '1',
            'naturezas' => ['pessoa_fisica'],
            'chamadas' => [['nome' => 'Inscrições', 'inicio' => '2026-11-01', 'fim' => '2026-11-30']],
            'categorias' => [['nome' => 'Pontos de cultura']],
            'publicado' => '1',
            'pdf' => $this->pdf(),
            'imagem' => $this->imagem(),
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/editais')
            ->assertSessionHas('sucesso', 'Edital salvo.');

        $edital = Edital::sole();
        $this->assertSame('premio-cultura-viva', $edital->slug);
        $this->assertTrue($edital->publicado);
        $this->assertSame('2026-11-30', $edital->inscricoes_fim->toDateString());
        $this->assertStringStartsWith('editais/', $edital->pdf);
        $this->assertStringStartsWith('editais/', $edital->imagem);
        Storage::disk('local')->assertExists($edital->pdf);
        Storage::disk('public')->assertExists($edital->imagem);
    }

    public function test_nao_publica_sem_pdf(): void
    {
        $this->post('/retaguarda/editais', $this->dados(['publicado' => '1']))
            ->assertSessionHasErrors(['pdf' => 'Envie o PDF do edital para publicar.']);

        $this->assertSame(0, Edital::count());

        // Rascunho sem PDF pode ser salvo.
        $this->post('/retaguarda/editais', $this->dados())->assertSessionHasNoErrors();
        $this->assertNull(Edital::sole()->pdf);
    }

    public function test_arquivo_falso_com_extensao_pdf_e_recusado(): void
    {
        $this->post('/retaguarda/editais', $this->dados([
            'pdf' => $this->arquivoEnviado('edital.pdf', 'texto qualquer'),
        ]))->assertSessionHasErrors(['pdf' => 'O arquivo precisa ser um PDF.']);

        $this->assertSame(0, Edital::count());
    }

    public function test_editar_sem_reenviar_mantem_arquivos(): void
    {
        $edital = $this->editalCompleto(['pdf' => 'editais/atual.pdf', 'imagem' => 'editais/atual.png', 'publicado' => true]);
        Storage::disk('local')->put('editais/atual.pdf', 'pdf');
        Storage::disk('public')->put('editais/atual.png', 'png');

        $this->put("/retaguarda/editais/{$edital->slug}", $this->dados(['titulo' => 'Título novo', 'publicado' => '1']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/retaguarda/editais')
            ->assertSessionHas('sucesso', 'Edital salvo.');

        $edital->refresh();
        $this->assertSame('Título novo', $edital->titulo);
        $this->assertSame('editais/atual.pdf', $edital->pdf);
        $this->assertSame('editais/atual.png', $edital->imagem);
        Storage::disk('local')->assertExists('editais/atual.pdf');
        Storage::disk('public')->assertExists('editais/atual.png');
    }

    public function test_slug_nao_muda_ao_editar_titulo(): void
    {
        $edital = Edital::factory()->create(['slug' => 'slug-original']);

        $this->put('/retaguarda/editais/slug-original', $this->dados(['titulo' => 'Outro título']))->assertSessionHasNoErrors();

        $this->assertSame('slug-original', $edital->refresh()->slug);
    }

    public function test_trocar_pdf_apaga_o_antigo(): void
    {
        $edital = Edital::factory()->create(['pdf' => 'editais/antigo.pdf']);
        Storage::disk('local')->put('editais/antigo.pdf', 'pdf');

        $this->put("/retaguarda/editais/{$edital->slug}", $this->dados(['pdf' => $this->pdf('novo.pdf')]))
            ->assertSessionHasNoErrors();

        $edital->refresh();
        $this->assertNotSame('editais/antigo.pdf', $edital->pdf);
        Storage::disk('local')->assertMissing('editais/antigo.pdf');
        Storage::disk('local')->assertExists($edital->pdf);
    }

    public function test_falha_ao_salvar_mantem_o_arquivo_antigo_e_descarta_o_novo(): void
    {
        $edital = Edital::factory()->create(['pdf' => 'editais/antigo.pdf']);
        Storage::disk('local')->put('editais/antigo.pdf', 'pdf');
        Edital::saving(fn () => false);

        $this->withoutExceptionHandling();

        try {
            $this->put("/retaguarda/editais/{$edital->slug}", $this->dados(['pdf' => $this->pdf('novo.pdf')]));
            $this->fail('A falha ao salvar deveria interromper a requisição.');
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertSame('editais/antigo.pdf', $edital->refresh()->pdf);
        Storage::disk('local')->assertExists('editais/antigo.pdf');
        $this->assertCount(1, Storage::disk('local')->allFiles('editais'));
    }

    public function test_remover_imagem_apaga_o_arquivo(): void
    {
        $edital = Edital::factory()->create(['imagem' => 'editais/capa.png']);
        Storage::disk('public')->put('editais/capa.png', 'png');

        $this->put("/retaguarda/editais/{$edital->slug}", $this->dados(['remover_imagem' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertNull($edital->refresh()->imagem);
        Storage::disk('public')->assertMissing('editais/capa.png');
    }

    public function test_slug_unico_mesmo_com_excluido(): void
    {
        Edital::factory()->create(['titulo' => 'Prêmio Cultura Viva', 'slug' => 'premio-cultura-viva'])->delete();

        $this->post('/retaguarda/editais', $this->dados())->assertSessionHasNoErrors();
        $this->post('/retaguarda/editais', $this->dados())->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['premio-cultura-viva-2', 'premio-cultura-viva-3'],
            Edital::pluck('slug')->all(),
        );
    }

    public function test_exclui_logicamente(): void
    {
        $edital = Edital::factory()->create(['pdf' => 'editais/fica.pdf']);
        Storage::disk('local')->put('editais/fica.pdf', 'pdf');

        $this->delete("/retaguarda/editais/{$edital->slug}")
            ->assertRedirect('/retaguarda/editais')
            ->assertSessionHas('sucesso', 'Edital excluído.');

        $this->assertSoftDeleted($edital);
        Storage::disk('local')->assertExists('editais/fica.pdf');
    }

    public function test_alterna_publicacao(): void
    {
        $edital = $this->editalCompleto(['publicado' => false]);

        $this->patch("/retaguarda/editais/{$edital->slug}/publicacao")
            ->assertSessionHas('sucesso', 'Edital publicado.');
        $this->assertTrue($edital->refresh()->publicado);

        $this->patch("/retaguarda/editais/{$edital->slug}/publicacao")
            ->assertSessionHas('sucesso', 'Edital despublicado.');
        $this->assertFalse($edital->refresh()->publicado);

        $semPdf = $this->editalCompleto(['publicado' => false, 'pdf' => null]);

        $this->patch("/retaguarda/editais/{$semPdf->slug}/publicacao")
            ->assertSessionHas('erro', 'Envie o PDF do edital.');
        $this->assertFalse($semPdf->refresh()->publicado);
    }

    public function test_gestor_baixa_pdf_de_edital_despublicado(): void
    {
        $edital = Edital::factory()->create(['pdf' => 'editais/rascunho.pdf', 'publicado' => false]);
        Storage::disk('local')->put('editais/rascunho.pdf', 'pdf');

        $this->get("/retaguarda/editais/{$edital->slug}/pdf")->assertOk()->assertDownload("edital-{$edital->slug}.pdf");
        $this->get("/editais/{$edital->slug}/pdf")->assertNotFound();
    }

    public function test_lista_mostra_as_importacoes_em_andamento_com_erro_e_as_concluidas_do_ultimo_dia(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 15:00'));
        $configuracao = ConfiguracaoIa::factory()->create([
            'ativo' => false,
            'provedor_principal' => ProvedorIa::Gemini,
        ]);
        ConexaoIa::factory()->gemini()->create();
        $analisando = ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes(2)]);
        $semTermino = ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes(16)]);
        $comErro = ImportacaoEdital::factory()->comErro('Chave inválida.')->create();
        $concluida = ImportacaoEdital::factory()->concluida()->create();
        ImportacaoEdital::factory()->concluida()->create(['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)]);

        $linha = fn (ImportacaoEdital $importacao, string $situacao, ?string $erro = null, ?string $editalUrl = null): array => [
            'id' => (int) $importacao->id,
            'pdfNome' => $importacao->pdf_nome,
            'situacao' => $situacao,
            'erro' => $erro,
            'editalUrl' => $editalUrl,
            'enviadaEm' => '28/10/2026 15:00',
        ];

        // Mais recentes primeiro; a concluída há mais de 24 h fica de fora.
        $this->get('/retaguarda/editais')->assertInertia(fn (Assert $p) => $p
            ->where('importacaoConfigurada', true)
            ->missing('importacaoAtiva')
            ->where('tamanhoMaximoPdfMb', 14)
            ->where('importacoes', [
                $linha($concluida, 'concluida', editalUrl: route('retaguarda.editais.edit', $concluida->edital)),
                $linha($comErro, 'erro', 'Chave inválida.'),
                $linha($semTermino, 'erro', 'A análise não terminou. Tente de novo.'),
                $linha($analisando, 'analisando'),
            ]));

        // Sem o principal, a importação continua na tela (o envio é que é recusado, com o que fazer).
        $configuracao->update(['provedor_principal' => null]);
        $this->get('/retaguarda/editais')->assertInertia(fn (Assert $p) => $p
            ->where('importacaoConfigurada', false)
            ->where('tamanhoMaximoPdfMb', 14)
            ->has('importacoes', 4));
    }

    public function test_importacao_antiga_com_bloco_homologacao_nao_oferece_a_aba_extinta(): void
    {
        $importacao = ImportacaoEdital::factory()->concluida()->create([
            'confiabilidade' => [
                'geral' => 80,
                'blocos' => [
                    'homologacao' => ['nota' => 80, 'justificativa' => 'Bloco extinto.'],
                    'identificacao' => ['nota' => 80, 'justificativa' => 'Claro.'],
                ],
            ],
            'atencao' => [
                ['bloco' => 'homologacao', 'tipo' => 'incerta', 'texto' => 'Requisito ilegível.', 'itens' => []],
            ],
        ]);

        $this->get("/retaguarda/editais/{$importacao->edital->slug}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('importacao.confiabilidade.blocos', [
                ['bloco' => 'identificacao', 'rotulo' => 'Identificação', 'nota' => 80, 'justificativa' => 'Claro.'],
            ])
            ->where('importacao.atencao.0.bloco', null)
            ->where('importacao.atencao.0.blocoRotulo', 'Geral'));
    }

    public function test_edital_importado_e_nao_conferido_traz_o_quadro_da_importacao(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 15:00'));
        $importacao = ImportacaoEdital::factory()->concluida()->create([
            'modelo' => 'gemini-2.5-pro',
            'tokens_total' => 12345,
            'confiabilidade' => [
                'geral' => 70,
                'blocos' => [
                    'inscricao' => ['nota' => 60, 'justificativa' => 'Prazos divergentes.'],
                    'identificacao' => ['nota' => 90, 'justificativa' => 'Cabeçalho claro.'],
                ],
            ],
            'atencao' => [
                ['bloco' => 'inscricao', 'tipo' => 'divergencia', 'texto' => 'O item 5.1 diz 01 a 31/07 e o 11.1 dá outro prazo.', 'itens' => ['5.1', '11.1']],
                ['bloco' => null, 'tipo' => 'incerta', 'texto' => 'Página ilegível.', 'itens' => []],
            ],
        ]);
        $edital = $importacao->edital;

        $this->get("/retaguarda/editais/{$edital->slug}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('importacao', [
                'confiabilidade' => [
                    'geral' => 70,
                    'blocos' => [
                        ['bloco' => 'identificacao', 'rotulo' => 'Identificação', 'nota' => 90, 'justificativa' => 'Cabeçalho claro.'],
                        ['bloco' => 'inscricao', 'rotulo' => 'Períodos', 'nota' => 60, 'justificativa' => 'Prazos divergentes.'],
                    ],
                ],
                'atencao' => [
                    [
                        'bloco' => 'inscricao',
                        'blocoRotulo' => 'Períodos',
                        'tipo' => 'divergencia',
                        'tipoRotulo' => 'Divergência no edital',
                        'texto' => 'O item 5.1 diz 01 a 31/07 e o 11.1 dá outro prazo.',
                        'itens' => ['5.1', '11.1'],
                    ],
                    ['bloco' => null, 'blocoRotulo' => 'Geral', 'tipo' => 'incerta', 'tipoRotulo' => 'Leitura incerta', 'texto' => 'Página ilegível.', 'itens' => []],
                ],
                'conferida' => false,
                'modelo' => 'gemini-2.5-pro',
                'tokensTotal' => 12345,
                'importadaEm' => '28/10/2026 15:00',
            ]));

        $importacao->update(['conferida_em' => now()]);
        $this->get("/retaguarda/editais/{$edital->slug}/edit")->assertInertia(fn (Assert $p) => $p->where('importacao', null));

        $manual = Edital::factory()->create();
        $this->get("/retaguarda/editais/{$manual->slug}/edit")->assertInertia(fn (Assert $p) => $p->where('importacao', null));
    }

    public function test_visualizar_abre_o_formulario_so_para_leitura(): void
    {
        $edital = ImportacaoEdital::factory()->concluida()->create()->edital;

        $this->get("/retaguarda/editais/{$edital->slug}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Retaguarda/Editais/Form')
                ->where('somenteLeitura', true)
                ->where('edital.slug', $edital->slug)
                // Conferir a importação é de quem edita: só para ler, o quadro não vem.
                ->where('importacao', null));

        $this->get("/retaguarda/editais/{$edital->slug}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('somenteLeitura', false)
                ->whereNot('importacao', null));
    }

    public function test_salva_modo_de_checagem_e_instrucao_do_documento(): void
    {
        $edital = Edital::factory()->create();

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'documentos' => [[
                'nome' => 'Comprovante de residência',
                'etapa' => 'inscricao',
                'todas_categorias' => '1',
                'checagem' => 'rigorosa',
                'instrucao_ia' => 'Conferir se o comprovante tem no máximo 90 dias.',
            ]],
        ])->assertSessionHasNoErrors();

        $this->get("/retaguarda/editais/{$edital->slug}/edit")->assertInertia(fn (Assert $p) => $p
            ->where('edital.documentos.0.checagem', 'rigorosa')
            ->where('edital.documentos.0.instrucao_ia', 'Conferir se o comprovante tem no máximo 90 dias.')
            ->where('checagensDocumento.0', ['valor' => 'rigorosa', 'rotulo' => 'Rigorosa']));
    }

    public function test_modo_de_checagem_invalido_e_recusado(): void
    {
        $edital = Edital::factory()->create();

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'documentos' => [['nome' => 'Doc', 'etapa' => 'inscricao', 'todas_categorias' => '1', 'checagem' => 'qualquer']],
        ])->assertSessionHasErrors('documentos.0.checagem');

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'documentos' => [['nome' => 'Doc', 'etapa' => 'inscricao', 'todas_categorias' => '1']],
        ])->assertSessionHasErrors('documentos.0.checagem');
    }

    public function test_instrucao_acima_de_2000_bytes_e_recusada(): void
    {
        $edital = Edital::factory()->create();

        $this->put("/retaguarda/editais/{$edital->slug}", [
            'com_listas' => '1',
            'documentos' => [['nome' => 'Doc', 'etapa' => 'inscricao', 'todas_categorias' => '1', 'checagem' => 'rigorosa', 'instrucao_ia' => str_repeat('ç', 1100)]],
        ])->assertSessionHasErrors('documentos.0.instrucao_ia');
    }

    public function test_proponente_nao_acessa(): void
    {
        $edital = Edital::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get('/retaguarda/editais')->assertRedirect('/retaguarda/login');
        $this->get("/retaguarda/editais/{$edital->slug}")->assertRedirect('/retaguarda/login');
        $this->post('/retaguarda/editais', $this->dados())->assertRedirect('/retaguarda/login');
        $this->delete("/retaguarda/editais/{$edital->slug}")->assertRedirect('/retaguarda/login');

        $this->assertSame(1, Edital::count());
    }

    public function test_pdf_que_o_php_recusou_mostra_aviso_de_tamanho(): void
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        $pdfGrandeDemais = new UploadedFile($caminho, 'edital.pdf', null, UPLOAD_ERR_INI_SIZE, true);

        $this->post('/retaguarda/editais', $this->dados(['pdf' => $pdfGrandeDemais]))
            ->assertSessionHasErrors(['pdf' => 'Não foi possível enviar o arquivo. Ele pode ser maior que o permitido.']);
    }

    public function test_envio_acima_do_limite_do_php_volta_ao_formulario_com_aviso(): void
    {
        $this->call('POST', '/retaguarda/editais', server: [
            'CONTENT_LENGTH' => (string) (1024 ** 3),
            'HTTP_X_INERTIA' => 'true',
            'HTTP_REFERER' => url('/retaguarda/editais/create'),
        ])
            ->assertRedirect('/retaguarda/editais/create')
            ->assertSessionHasErrors(['pdf' => 'Os arquivos enviados passam do tamanho máximo permitido.']);
    }

    public function test_envio_acima_do_limite_do_php_no_portal_volta_com_aviso_geral(): void
    {
        $this->call('POST', '/cadastro', server: [
            'CONTENT_LENGTH' => (string) (1024 ** 3),
            'HTTP_X_INERTIA' => 'true',
            'HTTP_REFERER' => url('/cadastro/pessoa-fisica'),
        ])
            ->assertRedirect('/cadastro/pessoa-fisica')
            ->assertSessionHas('erro', 'Os arquivos enviados passam do tamanho máximo permitido.');
    }
}
