<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\EtapaDocumento;
use App\Enums\NaturezaProponente;
use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Enums\TipoEdital;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\ImportacaoEdital;
use App\Models\User;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\EditalDaResposta;
use App\Support\EditalIa\EsquemaDaResposta;
use App\Support\EditalIa\EsquemaOpenAi;
use App\Support\EditalIa\LancadorDeAnalise;
use App\Support\EditalIa\PromptModelo;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\RespostaDaIa;
use Tests\TestCase;

class ImportacaoEditalTest extends TestCase
{
    use RefreshDatabase;
    use RespostaDaIa;

    private const string URL = '/retaguarda/editais/importacoes';

    private const string CHAVE = 'AIzaChaveFalsaDeTeste-0123456789-x4f2';

    private const string URL_ANALISE = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';

    private const string CHAVE_OPENAI = 'sk-teste-ChaveFalsaDaOpenAi-0123456789';

    private const string URL_OPENAI = 'https://api.openai.com/v1/responses';

    private const string CONTEUDO_PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private ConfiguracaoIa $configuracao;

    private ConexaoIa $conexao;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
        // A validação por IA desligada não impede a importação.
        $this->configuracao = ConfiguracaoIa::factory()->create([
            'ativo' => false,
            'provedor_principal' => ProvedorIa::Gemini,
        ]);
        $this->conexao = ConexaoIa::factory()->gemini()->create(['modelo' => 'gemini-2.5-pro', 'chave_api' => self::CHAVE]);

        // Sem processo em segundo plano nos testes: a análise roda na hora.
        $this->app->instance(LancadorDeAnalise::class, new class extends LancadorDeAnalise
        {
            public function lancar(ImportacaoEdital $importacao): void
            {
                Artisan::call('editais:analisar', ['importacao' => $importacao->getKey(), 'execucao' => $importacao->execucao]);
            }
        });

        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
    }

    /** Arquivo com o tipo vindo do conteúdo, como num upload real. */
    private function pdf(string $conteudo = self::CONTEUDO_PDF): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'edital-007.pdf', null, null, true);
    }

    /**
     * @param  array<mixed>|string  $dados  Resposta da IA (texto puro quando não é JSON).
     */
    private static function respostaDoGemini(array|string $dados): Closure
    {
        return fn () => Http::response(self::corpoDoGemini($dados));
    }

    /**
     * @param  array<mixed>|string  $dados
     * @return array<string, mixed>
     */
    private static function corpoDoGemini(array|string $dados): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => is_string($dados) ? $dados : json_encode($dados)]]]]],
            'usageMetadata' => ['promptTokenCount' => 12000, 'candidatesTokenCount' => 3000, 'totalTokenCount' => 15000],
        ];
    }

    /**
     * @param  array<mixed>  $dados
     */
    private static function respostaDaOpenAi(array $dados): Closure
    {
        return fn () => Http::response([
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($dados)]]]],
            'usage' => ['input_tokens' => 12000, 'output_tokens' => 3000, 'total_tokens' => 15000],
        ]);
    }

    private function importar(): ImportacaoEdital
    {
        $this->post(self::URL, ['pdf' => $this->pdf()])
            ->assertRedirect(route('retaguarda.editais.index'))
            ->assertSessionHas('sucesso', 'Análise iniciada. Você pode continuar usando o sistema.');

        return ImportacaoEdital::query()->sole();
    }

    public function test_pdf_valido_cria_edital_nao_publicado_com_os_blocos_o_pdf_e_a_confiabilidade(): void
    {
        $resposta = $this->respostaCompleta();
        $resposta['confiabilidade']['cotas']['nota'] = 35;
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($resposta)]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertNull($importacao->erro);
        $this->assertSame([12000, 3000, 15000], [$importacao->tokens_entrada, $importacao->tokens_saida, $importacao->tokens_total]);
        $this->assertSame(ProvedorIa::Gemini, $importacao->provedor);
        $this->assertSame('gemini-2.5-pro', $importacao->modelo);
        $this->assertSame('edital-007.pdf', $importacao->pdf_nome);
        $this->assertSame($resposta, $importacao->resposta);
        // (7 × 80 + 35) / 8 = 74
        $this->assertSame(74, $importacao->confiabilidade['geral']);
        $this->assertSame(['nota' => 35, 'justificativa' => 'Texto claro.'], $importacao->confiabilidade['blocos']['cotas']);
        $this->assertSame([[
            'bloco' => 'inscricao',
            'tipo' => 'divergencia',
            'texto' => 'O item 5.1 diz 01 a 30/11 e o 11.1 dá outro prazo.',
            'itens' => ['item 5.1', 'item 11.1'],
        ]], $importacao->atencao);
        Storage::disk('local')->assertExists($importacao->pdf);

        $edital = $importacao->edital;
        $this->assertFalse($edital->publicado);
        $this->assertSame('Salvador Circula', $edital->titulo);
        $this->assertSame('salvador-circula', $edital->slug);
        $this->assertSame(TipoEdital::Chamamento, $edital->tipo);
        $this->assertSame('2026-10-01', $edital->dom_data->toDateString());
        $this->assertEquals([NaturezaProponente::PessoaFisica, NaturezaProponente::Mei], $edital->naturezas->all());
        $this->assertSame(['negros', 'indigenas', 'pcd', 'base', 'alcance', 'veda_negro_indigena', 'heteroidentificacao'], array_keys($edital->cotas));
        $this->assertSame(['ativa' => true, 'percentual' => 25, 'quantidade' => null], $edital->cotas['negros']);
        $this->assertSame(['vedacoes' => 'É vedada a participação de servidores da FGM.'], $edital->informacoes);
        $this->assertSame(['grupo' => 'Mulheres', 'pontos_por_pessoa' => 1, 'maximo' => 5], $edital->pontuacao_extra['grupos'][0]);
        $this->assertSame(self::CONTEUDO_PDF, Storage::disk('local')->get($edital->pdf));
        $this->assertNotSame($importacao->pdf, $edital->pdf);
        $this->assertSame('2026-11-01', $edital->inscricoes_inicio->toDateString());
        $this->assertSame('2027-02-10', $edital->inscricoes_fim->toDateString());

        [$teatro, $danca] = $edital->categorias->all();
        $this->assertSame(1500050, (int) $teatro->valor_centavos);
        $this->assertSame('Chamada 1', $teatro->chamada->nome);
        $this->assertSame('Teatro Gregório de Mattos', $teatro->itens->sole()->nome);
        $this->assertSame('Chamada 2', $danca->chamada->nome);

        [$portfolio, $autodeclaracao] = $edital->documentos->all();
        $this->assertFalse($portfolio->todas_categorias);
        $this->assertSame([(int) $danca->id], $portfolio->categorias->map(fn ($c): int => (int) $c->id)->all());
        $this->assertTrue($autodeclaracao->todas_categorias);
        $this->assertSame(EtapaDocumento::ValidacaoCotas, $autodeclaracao->etapa);
        $this->assertSame([(int) $teatro->id], $edital->criterios->sole()->categorias->map(fn ($c): int => (int) $c->id)->all());

        Http::assertSent(fn (Request $pedido): bool => $pedido->header('x-goog-api-key') === [self::CHAVE]
            && ! str_contains($pedido->url(), self::CHAVE)
            && $pedido['contents'][0]['parts'][0]['inline_data']['data'] === base64_encode(self::CONTEUDO_PDF)
            && str_contains($pedido['contents'][0]['parts'][1]['text'], PromptModelo::fixo())
            && str_contains($pedido['contents'][0]['parts'][1]['text'], PromptModelo::editavel())
            && $pedido['generationConfig']['responseSchema'] === EsquemaDaResposta::esquema());
    }

    public function test_usa_modelo_e_chave_da_integracao_e_prompt_e_temperatura_fixos_da_importacao(): void
    {
        $this->conexao->update(['modelo' => 'gemini-3.0-pro', 'chave_api' => 'gemini-outra-chave-de-teste']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.0-pro:generateContent' => self::respostaDoGemini($this->respostaCompleta()),
        ]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame('gemini-3.0-pro', $importacao->modelo);
        Http::assertSent(fn (Request $pedido): bool => $pedido->header('x-goog-api-key') === ['gemini-outra-chave-de-teste']
            && $pedido['contents'][0]['parts'][1]['text'] === PromptModelo::fixo()."\n\n".PromptModelo::editavel()
            && $pedido['generationConfig']['temperature'] === 0.1);
    }

    /** A mesma resposta, vinda da OpenAI ou do Gemini, cria o mesmo edital. */
    public function test_openai_e_gemini_de_principal_criam_o_mesmo_edital_da_mesma_resposta(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        Http::fake([
            self::URL_ANALISE => self::respostaDoGemini($this->respostaCompleta()),
            self::URL_OPENAI => self::respostaDaOpenAi($this->respostaCompleta()),
        ]);

        $doGemini = $this->importar();
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi]);
        $this->post(self::URL, ['pdf' => $this->pdf()])->assertSessionHas('sucesso');
        $daOpenAi = ImportacaoEdital::query()->whereKeyNot($doGemini->getKey())->sole();

        $this->assertSame([ProvedorIa::Gemini, 'gemini-2.5-pro'], [$doGemini->provedor, $doGemini->modelo]);
        $this->assertSame([ProvedorIa::OpenAi, 'gpt-5.4'], [$daOpenAi->provedor, $daOpenAi->modelo]);
        $this->assertSame([12000, 3000, 15000], [$daOpenAi->tokens_entrada, $daOpenAi->tokens_saida, $daOpenAi->tokens_total]);
        $this->assertSame(SituacaoImportacao::Concluida, $daOpenAi->situacao);
        $this->assertSame($doGemini->resposta, $daOpenAi->resposta);
        $this->assertSame($doGemini->confiabilidade, $daOpenAi->confiabilidade);
        $this->assertSame($doGemini->atencao, $daOpenAi->atencao);

        $campos = fn (Edital $edital): array => [
            ...$edital->only(['titulo', 'tipo', 'numero', 'objeto', 'resumo', 'naturezas', 'cotas', 'informacoes', 'pontuacao_extra']),
            'categorias' => $edital->categorias->map(fn ($categoria): array => [$categoria->nome, (int) $categoria->valor_centavos, $categoria->chamada->nome])->all(),
            'documentos' => $edital->documentos->map(fn ($documento): array => [$documento->nome, $documento->etapa])->all(),
            'criterios' => $edital->criterios->pluck('nome')->all(),
        ];
        $this->assertEquals($campos($doGemini->edital), $campos($daOpenAi->edital));
        Http::assertSent(fn (Request $pedido): bool => $pedido->url() === self::URL_OPENAI
            && $pedido->header('Authorization') === ['Bearer '.self::CHAVE_OPENAI]
            && $pedido['input'][0]['content'][0]['file_data'] === 'data:application/pdf;base64,'.base64_encode(self::CONTEUDO_PDF)
            && $pedido['input'][0]['content'][1]['text'] === PromptModelo::fixo()."\n\n".PromptModelo::editavel()
            && $pedido['text']['format']['schema'] === EsquemaOpenAi::de(EsquemaDaResposta::esquema()));
    }

    public function test_principal_que_falha_passa_para_a_redundancia_com_ponto_de_atencao(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi, 'provedor_redundancia' => ProvedorIa::Gemini]);
        Http::fake([
            self::URL_OPENAI => Http::response(['error' => ['message' => 'Rate limit reached.']], 429),
            self::URL_ANALISE => self::respostaDoGemini($this->respostaCompleta()),
        ]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame([ProvedorIa::Gemini, 'gemini-2.5-pro'], [$importacao->provedor, $importacao->modelo]);
        $this->assertSame([
            'bloco' => 'identificacao',
            'tipo' => 'incerta',
            'texto' => 'Analisado pelo provedor de redundância (Google Gemini) porque o principal falhou: OpenAI: limite de uso ou cota da conta esgotados. Confira o plano e o saldo da conta.',
            'itens' => [],
        ], $importacao->atencao[0]);
        $this->assertCount(2, $importacao->atencao);
        Http::assertSentCount(2);
    }

    public function test_principal_com_json_sem_os_blocos_da_raiz_passa_para_a_redundancia(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi, 'provedor_redundancia' => ProvedorIa::Gemini]);
        Http::fake([
            self::URL_OPENAI => self::respostaDaOpenAi(['titulo' => 'Salvador Circula']),
            self::URL_ANALISE => self::respostaDoGemini($this->respostaCompleta()),
        ]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame([ProvedorIa::Gemini, 'gemini-2.5-pro'], [$importacao->provedor, $importacao->modelo]);
        $this->assertSame($this->respostaCompleta(), $importacao->resposta);
        $this->assertSame('Analisado pelo provedor de redundância (Google Gemini) porque o principal falhou: OpenAI respondeu fora do formato esperado.', $importacao->atencao[0]['texto']);
        $this->assertSame(1, Edital::count());
    }

    public function test_principal_e_redundancia_com_json_sem_os_blocos_da_raiz_nao_criam_edital(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi, 'provedor_redundancia' => ProvedorIa::Gemini]);
        Http::fake([
            self::URL_OPENAI => self::respostaDaOpenAi(['titulo' => 'Salvador Circula']),
            self::URL_ANALISE => self::respostaDoGemini(['categorias' => []]),
        ]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame('Serviço de IA indisponível no momento. Caso persista, contate o administrador. Detalhe: OpenAI: OpenAI respondeu fora do formato esperado. Google Gemini: Google Gemini respondeu fora do formato esperado.', $importacao->erro);
        $this->assertSame(0, Edital::withTrashed()->count());
    }

    public function test_principal_e_redundancia_falhando_grava_o_motivo_de_cada_um(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi, 'provedor_redundancia' => ProvedorIa::Gemini]);
        Http::fake([
            self::URL_OPENAI => Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::CHAVE_OPENAI]], 401),
            self::URL_ANALISE => Http::response('erro', 503),
        ]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(
            'Serviço de IA indisponível no momento. Caso persista, contate o administrador. Detalhe: OpenAI: OpenAI recusou a chave da API. Confira se ela foi copiada inteira e se ainda está ativa. '
            .'Google Gemini: Google Gemini está com instabilidade. Tente de novo em alguns minutos.',
            $importacao->erro,
        );
        $this->assertSame(0, Edital::count());
    }

    public function test_nova_tentativa_usa_o_provedor_principal_atual(): void
    {
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_OPENAI]);
        $this->configuracao->update(['provedor_principal' => ProvedorIa::OpenAi]);
        Http::fake([self::URL_OPENAI => self::respostaDaOpenAi($this->respostaCompleta())]);
        $importacao = ImportacaoEdital::factory()->comErro()->create();
        Storage::disk('local')->put($importacao->pdf, self::CONTEUDO_PDF);

        $this->post(self::URL."/{$importacao->id}/nova-tentativa")->assertSessionHas('sucesso');

        $importacao->refresh();
        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame([ProvedorIa::OpenAi, 'gpt-5.4'], [$importacao->provedor, $importacao->modelo]);
    }

    public function test_resposta_sem_titulo_recebe_titulo_provisorio(): void
    {
        Carbon::setTestNow('2026-10-28 15:00:00');
        $resposta = $this->respostaCompleta();
        $resposta['identificacao']['titulo'] = null;
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($resposta)]);

        $edital = $this->importar()->edital;

        $this->assertSame('Edital importado em 28/10/2026', $edital->titulo);
        $this->assertSame('edital-importado-em-28102026', $edital->slug);
    }

    /**
     * @return array<string, array{Closure, string}>
     */
    public static function falhas(): array
    {
        return [
            'JSON inválido' => [self::respostaDoGemini('{"identificacao": '), 'Google Gemini respondeu fora do formato esperado.'],
            'raiz sem os blocos' => [self::respostaDoGemini(['titulo' => 'Salvador Circula']), 'Google Gemini respondeu fora do formato esperado.'],
            'chave recusada (400)' => [fn () => Http::response(['error' => ['message' => 'API key '.self::CHAVE.' not valid', 'details' => [['reason' => 'API_KEY_INVALID']]]], 400), 'Google Gemini recusou a chave da API. Confira se ela foi copiada inteira e se ainda está ativa.'],
            'outro 400 que repete a chave' => [fn () => Http::response(['error' => ['message' => 'Invalid request for '.self::CHAVE]], 400), 'Google Gemini recusou o pedido (erro 400): Invalid request for [chave]'],
            'limite de uso (429)' => [fn () => Http::response(['error' => ['code' => 429]], 429), 'Google Gemini: limite de uso ou cota da conta esgotados. Confira o plano e o saldo da conta.'],
            'serviço fora do ar (500)' => [fn () => Http::response('erro', 500), 'Google Gemini está com instabilidade. Tente de novo em alguns minutos.'],
            'tempo esgotado' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 300000 milliseconds'), 'Google Gemini demorou demais para responder e a chamada foi interrompida. Tente de novo.'],
        ];
    }

    #[DataProvider('falhas')]
    public function test_falha_deixa_a_importacao_com_erro_e_nenhum_edital(Closure $resposta, string $mensagem): void
    {
        Http::fake([self::URL_ANALISE => $resposta]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame(AnaliseComRedundancia::INDISPONIVEL.' Detalhe: '.$mensagem, $importacao->erro);
        $this->assertStringNotContainsString(self::CHAVE, $importacao->erro);
        $this->assertNull($importacao->edital_id);
        $this->assertSame(0, Edital::withTrashed()->count());
        Storage::disk('local')->assertExists($importacao->pdf);
        $this->assertSame([$importacao->pdf], Storage::disk('local')->allFiles());
    }

    public function test_chave_repetida_pelo_provedor_no_ponto_do_corte_nao_fica_no_erro_gravado(): void
    {
        Http::fake([self::URL_ANALISE => Http::response(['error' => ['message' => str_repeat('x', 185).' '.self::CHAVE.' is not allowed.']], 400)]);

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertStringStartsWith(AnaliseComRedundancia::INDISPONIVEL.' Detalhe: Google Gemini recusou o pedido (erro 400): ', $importacao->erro);
        for ($inicio = 0; $inicio <= strlen(self::CHAVE) - 8; $inicio++) {
            $this->assertStringNotContainsString(substr(self::CHAVE, $inicio, 8), $importacao->erro);
        }
    }

    public function test_erro_inesperado_grava_mensagem_generica_e_loga_sem_a_chave(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaCompleta())]);
        $this->app->instance(EditalDaResposta::class, new class extends EditalDaResposta
        {
            public function criar(array $dados, ImportacaoEdital $importacao): Edital
            {
                throw new RuntimeException('Falhou com a chave AIzaChaveFalsaDeTeste-0123456789-x4f2 no meio.');
            }
        });
        Log::spy();

        $importacao = $this->importar();

        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame('Erro inesperado na análise.', $importacao->erro);
        $this->assertSame(0, Edital::withTrashed()->count());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $mensagem, array $contexto): bool => ! str_contains($mensagem.json_encode($contexto), self::CHAVE)
            && $contexto['excecao'] === RuntimeException::class);
    }

    public function test_pdf_acima_de_14_mb_e_recusado_antes_de_chamar_a_ia(): void
    {
        Http::fake();

        $resposta = $this->post(self::URL, ['pdf' => UploadedFile::fake()->create('edital.pdf', 15 * 1024, 'application/pdf')]);

        $resposta->assertSessionHasErrors(['pdf' => 'O PDF deve ter no máximo 14 MB.']);
        $this->assertSame(0, ImportacaoEdital::count());
        Http::assertNothingSent();
    }

    public function test_chave_que_deixou_de_decifrar_durante_a_analise_grava_o_erro(): void
    {
        Http::fake();
        $importacao = ImportacaoEdital::factory()->create();
        Storage::disk('local')->put($importacao->pdf, self::CONTEUDO_PDF);
        DB::table('conexoes_ia')->update(['chave_api' => 'cifrada-com-outra-app-key']);

        $this->artisan('editais:analisar', ['importacao' => $importacao->id])->assertFailed();

        $importacao->refresh();
        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);
        $this->assertSame('Serviço de IA indisponível no momento. Caso persista, contate o administrador.', $importacao->erro);
        Http::assertNothingSent();
    }

    public function test_arquivo_que_nao_e_pdf_e_recusado(): void
    {
        Http::fake();

        $resposta = $this->post(self::URL, ['pdf' => $this->pdf('só texto, sem PDF')]);

        $resposta->assertSessionHasErrors(['pdf' => 'O arquivo precisa ser um PDF.']);
        $this->assertSame(0, ImportacaoEdital::count());
    }

    /**
     * @return array<string, array{Closure(ConfiguracaoIa, ConexaoIa): void}>
     */
    public static function importacaoIndisponivel(): array
    {
        return [
            'sem provedor principal' => [fn (ConfiguracaoIa $configuracao) => $configuracao->update(['provedor_principal' => null])],
            'sem chave' => [fn (ConfiguracaoIa $configuracao, ConexaoIa $conexao) => $conexao->update(['chave_api' => null])],
            'sem modelo' => [fn (ConfiguracaoIa $configuracao, ConexaoIa $conexao) => $conexao->update(['modelo' => null])],
            'principal sem conexão, mesmo com a redundância' => [fn (ConfiguracaoIa $configuracao) => $configuracao->update([
                'provedor_principal' => ProvedorIa::OpenAi,
                'provedor_redundancia' => ProvedorIa::Gemini,
            ])],
            'chave que não decifra' => [fn () => DB::table('conexoes_ia')->update(['chave_api' => 'cifrada-com-outra-app-key'])],
        ];
    }

    /**
     * @param  Closure(ConfiguracaoIa, ConexaoIa): void  $preparar
     */
    #[DataProvider('importacaoIndisponivel')]
    public function test_sem_configuracao_recusa_o_envio_e_a_nova_tentativa_antes_de_chamar_a_ia(Closure $preparar): void
    {
        Http::fake();
        $preparar($this->configuracao, $this->conexao);
        $comErro = ImportacaoEdital::factory()->comErro()->create();

        $this->from('/retaguarda/editais')->post(self::URL, ['pdf' => $this->pdf()])
            ->assertRedirect('/retaguarda/editais')
            ->assertSessionHasErrors(['configuracao' => AnaliseComRedundancia::SEM_CONFIGURACAO]);
        $this->from('/retaguarda/editais')->post(self::URL."/{$comErro->id}/nova-tentativa")
            ->assertRedirect('/retaguarda/editais')
            ->assertSessionHas('erro', AnaliseComRedundancia::SEM_CONFIGURACAO);

        $this->assertSame('Serviço de IA indisponível no momento. Caso persista, contate o administrador.', AnaliseComRedundancia::SEM_CONFIGURACAO);
        $this->assertSame(1, ImportacaoEdital::count());
        $comErro->refresh();
        $this->assertSame(SituacaoImportacao::Erro, $comErro->situacao);
        $this->assertSame('Google Gemini está com instabilidade. Tente de novo em alguns minutos.', $comErro->erro);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNothingSent();
    }

    public function test_consulta_de_importacao_inexistente_ou_com_id_nao_numerico_responde_404(): void
    {
        $this->getJson(self::URL.'/abc')->assertNotFound();
        $this->getJson(self::URL.'/999999')->assertNotFound();
        $this->delete(self::URL.'/abc')->assertNotFound();
    }

    public function test_proponente_nao_acessa(): void
    {
        $this->actingAs(User::factory()->proponente()->create());
        $importacao = ImportacaoEdital::factory()->comErro()->create();

        $this->post(self::URL, ['pdf' => $this->pdf()])->assertRedirect('/retaguarda/login');
        $this->get(self::URL."/{$importacao->id}")->assertRedirect('/retaguarda/login');
        $this->delete(self::URL."/{$importacao->id}")->assertRedirect('/retaguarda/login');

        $this->assertSame(1, ImportacaoEdital::count());
    }

    public function test_analise_sem_termino_ha_mais_de_15_minutos_aparece_como_erro(): void
    {
        $perdida = ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes(16)]);
        $emAndamento = ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes(14)]);

        $this->getJson(self::URL."/{$perdida->id}")
            ->assertExactJson(['situacao' => 'erro', 'erro' => 'A análise não terminou. Tente de novo.', 'editalUrl' => null]);
        $this->getJson(self::URL."/{$emAndamento->id}")
            ->assertExactJson(['situacao' => 'analisando', 'erro' => null, 'editalUrl' => null]);
    }

    public function test_consulta_da_importacao_concluida_aponta_para_o_edital(): void
    {
        $importacao = ImportacaoEdital::factory()->concluida()->create();

        $this->getJson(self::URL."/{$importacao->id}")->assertExactJson([
            'situacao' => 'concluida',
            'erro' => null,
            'editalUrl' => route('retaguarda.editais.edit', $importacao->edital),
        ]);
    }

    public function test_tentar_de_novo_reusa_o_registro_e_cria_um_so_edital(): void
    {
        Http::fake([self::URL_ANALISE => Http::sequence()
            ->push('erro', 503)
            ->push(self::corpoDoGemini($this->respostaCompleta()))]);
        $importacao = $this->importar();
        $this->assertSame(SituacaoImportacao::Erro, $importacao->situacao);

        $this->post(self::URL."/{$importacao->id}/nova-tentativa")
            ->assertRedirect(route('retaguarda.editais.index'))
            ->assertSessionHas('sucesso', 'Análise iniciada. Você pode continuar usando o sistema.');
        $this->post(self::URL."/{$importacao->id}/nova-tentativa")
            ->assertSessionHas('erro', 'Só a importação com erro pode ser analisada de novo.');

        $importacao->refresh();
        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertNull($importacao->erro);
        $this->assertSame(1, ImportacaoEdital::count());
        $this->assertSame(1, Edital::count());
        $this->assertSame((int) Edital::query()->sole()->id, (int) $importacao->edital_id);
        Http::assertSentCount(2);
    }

    public function test_comando_nao_refaz_importacao_ja_concluida(): void
    {
        Http::fake();
        $importacao = ImportacaoEdital::factory()->concluida()->create();

        $this->artisan('editais:analisar', ['importacao' => $importacao->id])->assertFailed();

        $this->assertSame(1, Edital::count());
        Http::assertNothingSent();
    }

    public function test_descartar_apaga_o_registro_e_o_pdf(): void
    {
        $importacao = ImportacaoEdital::factory()->comErro()->create();
        Storage::disk('local')->put($importacao->pdf, self::CONTEUDO_PDF);

        $this->delete(self::URL."/{$importacao->id}")
            ->assertRedirect(route('retaguarda.editais.index'))
            ->assertSessionHas('sucesso', 'Importação descartada.');

        $this->assertModelMissing($importacao);
        Storage::disk('local')->assertMissing($importacao->pdf);
    }

    public function test_novo_envio_apaga_com_o_pdf_as_importacoes_com_erro_que_sairam_do_painel_ha_mais_de_uma_hora(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 15:00'));
        $this->app->instance(LancadorDeAnalise::class, new class extends LancadorDeAnalise
        {
            public function lancar(ImportacaoEdital $importacao): void {}
        });
        $comPdf = function (ImportacaoEdital $importacao): ImportacaoEdital {
            Storage::disk('local')->put($importacao->pdf, self::CONTEUDO_PDF);

            return $importacao;
        };
        // O erro sai do painel aos 30 minutos e é apagado 1 hora depois; a análise sem término vira erro aos 15.
        $apagarErroAntes = ImportacaoEdital::MINUTOS_ERRO_NO_PAINEL + ImportacaoEdital::MINUTOS_ATE_APAGAR;
        $apagarSemTerminoAntes = ImportacaoEdital::MINUTOS_LIMITE + $apagarErroAntes;

        $erroAntigo = $comPdf(ImportacaoEdital::factory()->comErro()->create(['updated_at' => now()->subMinutes($apagarErroAntes + 1)]));
        $semTerminoAntiga = $comPdf(ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes($apagarSemTerminoAntes + 1)]));
        $erroNaFolga = $comPdf(ImportacaoEdital::factory()->comErro()->create(['updated_at' => now()->subMinutes($apagarErroAntes - 1)]));
        $semTerminoNaFolga = $comPdf(ImportacaoEdital::factory()->create(['iniciada_em' => now()->subMinutes($apagarSemTerminoAntes - 1)]));
        $concluidaAntiga = $comPdf(ImportacaoEdital::factory()->concluida()->create(['updated_at' => now()->subDays(30)]));

        $this->post(self::URL, ['pdf' => $this->pdf()])->assertRedirect(route('retaguarda.editais.index'));

        foreach ([$erroAntigo, $semTerminoAntiga] as $apagada) {
            $this->assertModelMissing($apagada);
            Storage::disk('local')->assertMissing($apagada->pdf);
        }
        foreach ([$erroNaFolga, $semTerminoNaFolga, $concluidaAntiga] as $mantida) {
            $this->assertModelExists($mantida);
            Storage::disk('local')->assertExists($mantida->pdf);
        }
        $this->assertSame(4, ImportacaoEdital::count());
    }

    public function test_importacao_concluida_nao_pode_ser_descartada(): void
    {
        $importacao = ImportacaoEdital::factory()->concluida()->create();

        $this->delete(self::URL."/{$importacao->id}")->assertSessionHas('erro', 'Só a importação com erro pode ser descartada.');

        $this->assertModelExists($importacao);
    }

    public function test_marcar_como_conferida(): void
    {
        Carbon::setTestNow('2026-10-28 15:00:00');
        $importacao = ImportacaoEdital::factory()->concluida()->create();

        $this->post("/retaguarda/editais/{$importacao->edital->slug}/importacao/conferida")
            ->assertSessionHas('sucesso', 'Importação marcada como conferida.');

        $this->assertSame('2026-10-28 15:00:00', $importacao->refresh()->conferida_em->format('Y-m-d H:i:s'));
    }

    public function test_marcar_como_conferida_edital_sem_importacao_responde_404(): void
    {
        $edital = Edital::factory()->create();

        $this->post("/retaguarda/editais/{$edital->slug}/importacao/conferida")->assertNotFound();
    }

    public function test_lancador_inicia_o_comando_em_segundo_plano(): void
    {
        Process::fake();
        $importacao = ImportacaoEdital::factory()->create();

        (new LancadorDeAnalise)->lancar($importacao);

        Process::assertRan(fn (PendingProcess $processo): bool => str_contains(
            $processo->command,
            "editais:analisar {$importacao->id} ".escapeshellarg($importacao->execucao),
        ));
    }

    public function test_comando_repetido_na_mesma_execucao_cria_um_so_edital(): void
    {
        Http::fake([self::URL_ANALISE => self::respostaDoGemini($this->respostaCompleta())]);
        $importacao = $this->importar();
        $editalId = $importacao->edital_id;

        $this->artisan('editais:analisar', ['importacao' => $importacao->id, 'execucao' => $importacao->execucao])->assertFailed();

        $this->assertSame(1, Edital::count());
        $importacao->refresh();
        $this->assertSame(SituacaoImportacao::Concluida, $importacao->situacao);
        $this->assertSame((int) $editalId, (int) $importacao->edital_id);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function fimDoProcessoAntigo(): array
    {
        return ['com sucesso' => [true], 'com erro' => [false]];
    }

    /** Enquanto o processo antigo espera a IA, a nova tentativa abre outra execução e conclui; depois o antigo termina. */
    #[DataProvider('fimDoProcessoAntigo')]
    public function test_processo_antigo_nao_grava_depois_da_nova_tentativa(bool $antigoTemSucesso): void
    {
        $importacao = ImportacaoEdital::factory()->create();
        Storage::disk('local')->put($importacao->pdf, self::CONTEUDO_PDF);
        $chamadas = 0;
        Http::fake([self::URL_ANALISE => function () use ($importacao, $antigoTemSucesso, &$chamadas) {
            if (++$chamadas === 1) {
                $importacao->update(['iniciada_em' => now()->subMinutes(16)]);
                $this->post(self::URL."/{$importacao->id}/nova-tentativa")->assertSessionHas('sucesso');

                return $antigoTemSucesso ? Http::response(self::corpoDoGemini($this->respostaCompleta())) : Http::response('erro', 500);
            }

            return Http::response(self::corpoDoGemini($this->respostaCompleta()));
        }]);

        $this->artisan('editais:analisar', ['importacao' => $importacao->id, 'execucao' => $importacao->execucao])->assertFailed();

        $this->assertSame(1, Edital::count());
        $depois = $importacao->fresh();
        $this->assertSame(SituacaoImportacao::Concluida, $depois->situacao);
        $this->assertSame((int) Edital::query()->sole()->id, (int) $depois->edital_id);
        $this->assertNull($depois->erro);
        $this->assertNotSame($importacao->execucao, $depois->execucao);
        // Só o PDF da importação e o do edital criado: a cópia do processo antigo não fica.
        $this->assertCount(2, Storage::disk('local')->allFiles());
        Http::assertSentCount(2);
    }

    public function test_dois_pedidos_de_nova_tentativa_lancam_uma_so_analise(): void
    {
        $lancador = new class extends LancadorDeAnalise
        {
            public int $lancamentos = 0;

            public function lancar(ImportacaoEdital $importacao): void
            {
                $this->lancamentos++;
            }
        };
        $this->app->instance(LancadorDeAnalise::class, $lancador);
        $importacao = ImportacaoEdital::factory()->comErro()->create();

        $this->post(self::URL."/{$importacao->id}/nova-tentativa")->assertSessionHas('sucesso');
        $this->post(self::URL."/{$importacao->id}/nova-tentativa")
            ->assertSessionHas('erro', 'Só a importação com erro pode ser analisada de novo.');

        $this->assertSame(1, $lancador->lancamentos);
        $this->assertSame(SituacaoImportacao::Analisando, $importacao->refresh()->situacao);
    }

    public function test_duas_leituras_com_erro_abrem_uma_so_nova_tentativa(): void
    {
        $importacao = ImportacaoEdital::factory()->comErro()->create();
        $outraLeitura = ImportacaoEdital::query()->findOrFail($importacao->id);
        $execucaoAnterior = $importacao->execucao;

        // As duas leituras viram "erro"; só a primeira atualização condicional abre a nova tentativa.
        $this->assertTrue($importacao->abrirNovaTentativa(ProvedorIa::OpenAi, 'gpt-5.4'));
        $this->assertFalse($outraLeitura->abrirNovaTentativa(ProvedorIa::Gemini, 'gemini-2.5-flash'));

        $this->assertSame(SituacaoImportacao::Analisando, $importacao->situacao);
        $this->assertNull($importacao->erro);
        $this->assertSame([ProvedorIa::OpenAi, 'gpt-5.4'], [$importacao->provedor, $importacao->modelo]);
        $this->assertNotSame($execucaoAnterior, $importacao->execucao);
    }
}
