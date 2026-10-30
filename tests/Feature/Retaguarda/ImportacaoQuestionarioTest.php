<?php

namespace Tests\Feature\Retaguarda;

use App\Console\Commands\AnalisarQuestionarioCommand;
use App\Enums\NaturezaProponente;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Enums\TipoPergunta;
use App\Http\Controllers\Retaguarda\ImportacaoQuestionarioController;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\ImportacaoQuestionario;
use App\Models\Inscricao;
use App\Models\User;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\EsquemaDoQuestionario;
use App\Support\EditalIa\LancadorDeAnaliseDoQuestionario;
use App\Support\EditalIa\PromptDoQuestionario;
use App\Support\EditalIa\TextoDoDocx;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RespostaDoQuestionario;
use Tests\TestCase;
use ZipArchive;

class ImportacaoQuestionarioTest extends TestCase
{
    use RefreshDatabase;
    use RespostaDoQuestionario;

    private const string CHAVE = 'AIzaChaveFalsaDeTeste-0123456789-x4f2';

    private const string URL_ANALISE = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';

    private const string CHAVE_OPENAI = 'sk-teste-ChaveFalsaDaOpenAi-0123456789';

    private const string URL_OPENAI = 'https://api.openai.com/v1/responses';

    private const string CONTEUDO_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const string ERRO_TIPO = 'O arquivo precisa ser PDF ou Word (.docx).';

    private const string CORPO_DOCX = '<w:p><w:r><w:t>Linguagem principal: ☒ Teatro ☐ Dança</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>Descreva a proposta (até 1000 caracteres)</w:t></w:r></w:p>';

    private ConfiguracaoIa $configuracao;

    private Edital $edital;

    private EditalCategoria $teatro;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
        $this->configuracao = ConfiguracaoIa::factory()->create(['ativo' => false, 'provedor_principal' => ProvedorIa::Gemini]);
        ConexaoIa::factory()->gemini()->create(['modelo' => 'gemini-2.5-pro', 'chave_api' => self::CHAVE]);

        // Sem processo em segundo plano nos testes: a análise roda na hora.
        $this->app->instance(LancadorDeAnaliseDoQuestionario::class, new class extends LancadorDeAnaliseDoQuestionario
        {
            public function lancar(ImportacaoQuestionario $importacao): void
            {
                Artisan::call('questionarios:analisar', ['importacao' => $importacao->getKey(), 'execucao' => $importacao->execucao]);
            }
        });

        // Dança é criada antes (id menor), mas vem depois na ordem: as posições seguem a ordem do edital, não o id.
        $this->edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::PessoaFisica, NaturezaProponente::Mei]]);
        EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Dança', 'naturezas' => ['pessoa_fisica'], 'ordem' => 2]);
        $this->teatro = EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Teatro', 'naturezas' => null, 'ordem' => 1]);

        $this->actingAs(User::factory()->gestor()->create());
    }

    private function url(?Edital $edital = null): string
    {
        return '/retaguarda/editais/'.($edital ?? $this->edital)->slug.'/questionario/importacoes';
    }

    /** Arquivo com o tipo vindo do conteúdo, como num upload real. */
    private function arquivo(string $conteudo, string $nome): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function pdf(): UploadedFile
    {
        return $this->arquivo(self::CONTEUDO_PDF, 'espelho.pdf');
    }

    private function docx(string $corpo = self::CORPO_DOCX, string $parte = 'word/document.xml', ?string $xml = null): UploadedFile
    {
        $xml ??= '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$corpo.'</w:body></w:document>';

        $caminho = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::OVERWRITE);
        $zip->addFromString($parte, $xml);
        $zip->close();

        return new UploadedFile($caminho, 'espelho.docx', null, null, true);
    }

    /**
     * @param  array<mixed>|null  $dados
     */
    private static function respostaDoGemini(?array $dados): Closure
    {
        return fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($dados)]]]]],
            'usageMetadata' => ['promptTokenCount' => 8000, 'candidatesTokenCount' => 2000, 'totalTokenCount' => 10000],
        ]);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function importar(UploadedFile $arquivo, array $dados = []): ImportacaoQuestionario
    {
        $this->from('/retaguarda/editais/'.$this->edital->slug.'/edit')
            ->post($this->url(), ['arquivo' => $arquivo, ...$dados])
            ->assertRedirect('/retaguarda/editais/'.$this->edital->slug.'/edit')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('sucesso', 'Análise iniciada. Você pode continuar usando o sistema.');

        return ImportacaoQuestionario::query()->latest('id')->firstOrFail();
    }

    /** Questionário de uma seção com uma pergunta, para conferir se fica ou é substituído. */
    private function questionarioExistente(): EditalPergunta
    {
        $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $this->edital->id, 'titulo' => 'Seção antiga']);

        return EditalPergunta::factory()->create([
            'edital_id' => $this->edital->id,
            'edital_questionario_secao_id' => $secao->id,
            'enunciado' => 'Pergunta antiga?',
        ]);
    }

    /** @return list<string> */
    private function titulosDasSecoes(): array
    {
        return $this->edital->secoesDoQuestionario()->pluck('titulo')->all();
    }

    public function test_pdf_monta_o_questionario_com_vinculos_condicoes_limites_e_declaracao(): void
    {
        $resposta = $this->respostaDoQuestionario();
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($resposta)]);

        $importacao = $this->importar($this->pdf());

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertNull($importacao->erro);
        $this->assertSame(['pdf', 'espelho.pdf'], [$importacao->tipo_arquivo, $importacao->arquivo_nome]);
        $this->assertSame([ProvedorIa::Gemini, 'gemini-2.5-pro'], [$importacao->provedor, $importacao->modelo]);
        $this->assertSame([8000, 2000, 10000], [$importacao->tokens_entrada, $importacao->tokens_saida, $importacao->tokens_total]);
        $this->assertSame($resposta, $importacao->resposta);
        $this->assertSame(85, $importacao->confiabilidade['geral']);
        $this->assertSame('A ficha técnica não virou pergunta.', $importacao->atencao[0]['texto']);
        $this->assertSame('questionario', $importacao->atencao[0]['bloco']);
        Storage::disk('local')->assertExists($importacao->arquivo);

        $this->assertSame(['Sobre a proposta', 'Declarações'], $this->titulosDasSecoes());
        $perguntas = EditalPergunta::query()->where('edital_id', $this->edital->id)->with('categorias')->get()->keyBy('enunciado');
        $this->assertCount(6, $perguntas);
        $this->assertSame(1000, $perguntas['Descreva a proposta']->limite_caracteres);

        $origem = $perguntas['Recebeu recursos de fomento nos últimos 5 anos?'];
        $condicionada = $perguntas['Quais editais?'];
        $this->assertSame((int) $origem->id, (int) $condicionada->condicao_pergunta_id);
        $this->assertSame(['sim'], $condicionada->condicao_opcoes);

        $espacos = $perguntas['Espaços onde pretende se apresentar'];
        $this->assertFalse($espacos->todas_categorias);
        $this->assertSame([(int) $this->teatro->id], $espacos->categorias->map(fn (EditalCategoria $categoria): int => (int) $categoria->id)->all());

        $declaracao = $perguntas['DECLARO que as informações são verdadeiras.'];
        $this->assertSame(TipoPergunta::Declaracao, $declaracao->tipo);
        $this->assertTrue($declaracao->obrigatoria);

        Http::assertSent(fn (Request $pedido): bool => $pedido->header('x-goog-api-key') === [self::CHAVE]
            && ! str_contains($pedido->url(), self::CHAVE)
            && $pedido['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'application/pdf'
            && $pedido['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode(self::CONTEUDO_PDF)
            && ! str_contains($pedido['contents'][0]['parts'][1]['text'], '<<<INICIO DO FORMULARIO>>>'));
    }

    public function test_docx_vai_como_texto_sem_arquivo(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);

        $importacao = $this->importar($this->docx());

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame(['docx', 'espelho.docx'], [$importacao->tipo_arquivo, $importacao->arquivo_nome]);
        $this->assertSame(['Sobre a proposta', 'Declarações'], $this->titulosDasSecoes());
        Http::assertSent(function (Request $pedido): bool {
            $partes = $pedido['contents'][0]['parts'];
            $texto = $partes[0]['text'] ?? '';

            return count($partes) === 1
                && ! isset($partes[0]['inline_data'])
                && str_contains($texto, '<<<INICIO DO FORMULARIO>>>')
                && str_contains($texto, 'Linguagem principal: [x] Teatro [ ] Dança')
                && str_starts_with($texto, PromptDoQuestionario::fixo());
        });
    }

    public function test_openai_recebe_o_docx_so_como_input_text(): void
    {
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi]);
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        Http::fake([self::URL_OPENAI => fn () => Http::response([
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($this->respostaDoQuestionario())]]]],
            'usage' => ['input_tokens' => 8000, 'output_tokens' => 2000, 'total_tokens' => 10000],
        ])]);

        $importacao = $this->importar($this->docx());

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame([ProvedorIa::OpenAi, 'gpt-5.4'], [$importacao->provedor, $importacao->modelo]);
        Http::assertSent(function (Request $pedido): bool {
            $conteudo = $pedido['input'][0]['content'];

            return $pedido->header('Authorization') === ['Bearer '.self::CHAVE_OPENAI]
                && count($conteudo) === 1
                && $conteudo[0]['type'] === 'input_text'
                && str_contains($conteudo[0]['text'], '[x] Teatro');
        });
    }

    public function test_prompt_leva_categorias_e_naturezas_do_edital(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);

        $this->importar($this->pdf());

        $contexto = PromptDoQuestionario::contexto($this->edital->fresh());
        $this->assertStringContainsString("- Teatro: Pessoa Física, MEI\n- Dança: Pessoa Física", $contexto);
        Http::assertSent(fn (Request $pedido): bool => $pedido['contents'][0]['parts'][1]['text']
                === PromptDoQuestionario::fixo()."\n\n".PromptDoQuestionario::editavel()."\n\n".$contexto
            && $pedido['generationConfig']['temperature'] === 0.1
            && $pedido['generationConfig']['responseSchema'] === EsquemaDoQuestionario::esquema());
    }

    public function test_substitui_o_questionario_existente_so_com_confirmacao(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);
        $antiga = $this->questionarioExistente();

        $this->post($this->url(), ['arquivo' => $this->pdf()])
            ->assertSessionHasErrors(['arquivo' => ImportacaoQuestionarioController::ERRO_CONFIRMAR]);
        $this->assertSame(0, ImportacaoQuestionario::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();

        $importacao = $this->importar($this->pdf(), ['substituir' => '1']);

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertTrue($importacao->substituir);
        $this->assertModelMissing($antiga);
        $this->assertSame(['Sobre a proposta', 'Declarações'], $this->titulosDasSecoes());
    }

    public function test_falha_da_ia_deixa_o_questionario_intacto(): void
    {
        Http::fake([self::URL_ANALISE => Http::response(['error' => ['message' => 'Invalid request for '.self::CHAVE]], 400)]);
        $antiga = $this->questionarioExistente();

        $importacao = $this->importar($this->pdf(), ['substituir' => '1']);

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(AnaliseComRedundancia::INDISPONIVEL.' Detalhe: Google Gemini recusou o pedido (erro 400): Invalid request for [chave]', $importacao->erro);
        $this->assertStringNotContainsString(self::CHAVE, $importacao->erro);
        $this->assertNull($importacao->confiabilidade);
        $this->assertModelExists($antiga);
        $this->assertSame(['Seção antiga'], $this->titulosDasSecoes());
    }

    public function test_docx_sem_document_xml_e_recusado_no_envio(): void
    {
        Http::fake();

        $this->post($this->url(), ['arquivo' => $this->docx(parte: 'word/outro.xml')])
            ->assertSessionHasErrors(['arquivo' => self::ERRO_TIPO]);

        $this->assertSame(0, ImportacaoQuestionario::count());
        Http::assertNothingSent();
    }

    public function test_docx_ilegivel_na_analise_grava_o_erro_de_leitura_e_nao_chama_a_ia(): void
    {
        Http::fake();

        $importacao = $this->importar($this->docx(xml: '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p>'));

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(TextoDoDocx::ERRO_ARQUIVO, $importacao->erro);
        Http::assertNothingSent();
    }

    public function test_sem_categoria_ou_travado_ou_em_andamento_recusa_antes_de_guardar_o_arquivo(): void
    {
        Http::fake();

        $semCategoria = Edital::factory()->create();
        $this->post($this->url($semCategoria), ['arquivo' => $this->pdf()])
            ->assertSessionHasErrors(['arquivo' => ImportacaoQuestionarioController::ERRO_SEM_CATEGORIA]);

        $emAndamento = ImportacaoQuestionario::factory()->create(['edital_id' => $this->edital->id, 'iniciada_em' => now()->subMinutes(14)]);
        $this->post($this->url(), ['arquivo' => $this->pdf()])
            ->assertSessionHasErrors(['arquivo' => ImportacaoQuestionarioController::ERRO_EM_ANDAMENTO]);

        Inscricao::factory()->finalizada()->create(['edital_id' => $this->edital->id]);
        $this->post($this->url(), ['arquivo' => $this->pdf()])
            ->assertSessionHasErrors(['arquivo' => Edital::AVISO_QUESTIONARIO_TRAVADO]);

        $this->configuracao->update(['provedor_principal' => null]);
        $this->post($this->url(), ['arquivo' => $this->pdf()])
            ->assertSessionHasErrors(['configuracao' => AnaliseComRedundancia::SEM_CONFIGURACAO]);

        $this->assertSame([(int) $emAndamento->id], ImportacaoQuestionario::query()->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_analise_perdida_nao_impede_nova_importacao_e_sai_da_execucao(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);
        $perdida = ImportacaoQuestionario::factory()->create(['edital_id' => $this->edital->id, 'iniciada_em' => now()->subMinutes(16)]);

        $importacao = $this->importar($this->pdf());

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $perdida->refresh();
        $this->assertSame(SituacaoImportacao::Erro, $perdida->situacao);
        $this->assertSame('A análise não terminou. Tente de novo.', $perdida->erro);
        $this->artisan('questionarios:analisar', ['importacao' => $perdida->id, 'execucao' => $perdida->execucao])->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_trava_durante_a_analise_nao_substitui(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);
        $antiga = $this->questionarioExistente();
        $this->app->instance(LancadorDeAnaliseDoQuestionario::class, new class extends LancadorDeAnaliseDoQuestionario
        {
            public function lancar(ImportacaoQuestionario $importacao): void
            {
                Inscricao::factory()->finalizada()->create(['edital_id' => $importacao->edital_id]);
                Artisan::call('questionarios:analisar', ['importacao' => $importacao->getKey(), 'execucao' => $importacao->execucao]);
            }
        });

        $importacao = $this->importar($this->pdf(), ['substituir' => '1']);

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(Edital::AVISO_QUESTIONARIO_TRAVADO, $importacao->erro);
        $this->assertModelExists($antiga);
        $this->assertSame(['Seção antiga'], $this->titulosDasSecoes());
    }

    public function test_questionario_criado_durante_a_analise_sem_confirmacao_nao_e_substituido(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);
        $this->app->instance(LancadorDeAnaliseDoQuestionario::class, new class extends LancadorDeAnaliseDoQuestionario
        {
            public function lancar(ImportacaoQuestionario $importacao): void
            {
                $secao = EditalQuestionarioSecao::factory()->create(['edital_id' => $importacao->edital_id, 'titulo' => 'Seção salva no meio']);
                EditalPergunta::factory()->create(['edital_id' => $importacao->edital_id, 'edital_questionario_secao_id' => $secao->id]);
                Artisan::call('questionarios:analisar', ['importacao' => $importacao->getKey(), 'execucao' => $importacao->execucao]);
            }
        });

        $importacao = $this->importar($this->pdf());

        $this->assertFalse($importacao->substituir);
        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(AnalisarQuestionarioCommand::ERRO_QUESTIONARIO_ALTERADO, $importacao->erro);
        $this->assertSame(['Seção salva no meio'], $this->titulosDasSecoes());
    }

    public function test_arquivo_que_nao_e_pdf_nem_docx_e_recusado(): void
    {
        Http::fake();

        $this->post($this->url(), ['arquivo' => $this->arquivo('só texto', 'espelho.txt')])
            ->assertSessionHasErrors(['arquivo' => self::ERRO_TIPO]);
        $this->post($this->url(), ['arquivo' => $this->arquivo('só texto, sem PDF', 'espelho.pdf')])
            ->assertSessionHasErrors(['arquivo' => self::ERRO_TIPO]);
        $this->post($this->url(), ['arquivo' => $this->arquivo('não é zip', 'espelho.docx')])
            ->assertSessionHasErrors(['arquivo' => self::ERRO_TIPO]);

        $this->assertSame(0, ImportacaoQuestionario::count());
        Http::assertNothingSent();
    }

    public function test_acima_de_14_mb_e_recusado(): void
    {
        Http::fake();

        $this->post($this->url(), ['arquivo' => UploadedFile::fake()->create('espelho.pdf', 15 * 1024, 'application/pdf')])
            ->assertSessionHasErrors(['arquivo' => 'O arquivo deve ter no máximo 14 MB.']);

        $this->assertSame(0, ImportacaoQuestionario::count());
        Http::assertNothingSent();
    }

    public function test_consulta_da_situacao_e_marcar_como_conferida(): void
    {
        $perdida = ImportacaoQuestionario::factory()->create(['edital_id' => $this->edital->id, 'iniciada_em' => now()->subMinutes(16)]);
        $comErro = ImportacaoQuestionario::factory()->comErro('Falhou.')->create(['edital_id' => $this->edital->id]);

        $this->getJson("/retaguarda/editais/questionario/importacoes/{$perdida->id}")
            ->assertExactJson(['situacao' => 'erro', 'erro' => 'A análise não terminou. Tente de novo.']);
        $this->getJson("/retaguarda/editais/questionario/importacoes/{$comErro->id}")
            ->assertExactJson(['situacao' => 'erro', 'erro' => 'Falhou.']);
        $this->getJson('/retaguarda/editais/questionario/importacoes/abc')->assertNotFound();
        $this->getJson('/retaguarda/editais/questionario/importacoes/999999')->assertNotFound();

        $conferida = "/retaguarda/editais/{$this->edital->slug}/questionario/importacao/conferida";
        $this->post($conferida)->assertNotFound();

        Carbon::setTestNow('2026-10-30 15:00:00');
        $concluida = ImportacaoQuestionario::factory()->concluida()->create(['edital_id' => $this->edital->id]);
        $this->getJson("/retaguarda/editais/questionario/importacoes/{$concluida->id}")
            ->assertExactJson(['situacao' => 'concluida', 'erro' => null]);

        $this->post($conferida)->assertSessionHas('sucesso', 'Importação marcada como conferida.');

        $this->assertSame('2026-10-30 15:00:00', $concluida->refresh()->conferida_em->format('Y-m-d H:i:s'));
        $this->assertNull($comErro->refresh()->conferida_em);
    }

    public function test_tela_do_edital_traz_a_ultima_importacao_e_o_quadro_ate_conferir(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaDoQuestionario())]);
        $this->importar($this->pdf());
        $editar = '/retaguarda/editais/'.$this->edital->slug.'/edit';

        $this->get($editar)->assertInertia(fn (Assert $pagina) => $pagina
            ->where('importacaoQuestionario.situacao', 'concluida')
            ->where('importacaoQuestionario.erro', null)
            ->where('importacaoQuestionario.arquivoNome', 'espelho.pdf')
            ->where('quadroQuestionario.confiabilidade.geral', 85)
            ->where('quadroQuestionario.confiabilidade.blocos.0.rotulo', 'Questionário')
            ->where('quadroQuestionario.atencao.0.blocoRotulo', 'Questionário')
            ->where('quadroQuestionario.atencao.0.texto', 'A ficha técnica não virou pergunta.')
            ->where('importacaoQuestionarioConfigurada', true)
            ->has('tamanhoMaximoArquivoMb'));

        $this->post("/retaguarda/editais/{$this->edital->slug}/questionario/importacao/conferida");

        $this->get($editar)->assertInertia(fn (Assert $pagina) => $pagina
            ->where('importacaoQuestionario.situacao', 'concluida')
            ->where('quadroQuestionario', null));
    }

    public function test_proponente_nao_acessa(): void
    {
        $this->actingAs(User::factory()->proponente()->create());
        $importacao = ImportacaoQuestionario::factory()->concluida()->create(['edital_id' => $this->edital->id]);

        $this->post($this->url(), ['arquivo' => $this->pdf()])->assertRedirect('/retaguarda/login');
        $this->get("/retaguarda/editais/questionario/importacoes/{$importacao->id}")->assertRedirect('/retaguarda/login');
        $this->post("/retaguarda/editais/{$this->edital->slug}/questionario/importacao/conferida")->assertRedirect('/retaguarda/login');

        $this->assertSame(1, ImportacaoQuestionario::count());
        $this->assertNull($importacao->refresh()->conferida_em);
    }
}
