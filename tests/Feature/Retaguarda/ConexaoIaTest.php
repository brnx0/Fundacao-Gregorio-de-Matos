<?php

namespace Tests\Feature\Retaguarda;

use App\Models\ConexaoIa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConexaoIaTest extends TestCase
{
    use RefreshDatabase;

    private const string URL_MODELOS = '/retaguarda/integracao-ia/modelos';

    private const string URL_TESTE = '/retaguarda/integracao-ia/teste';

    private const string MODELOS_OPENAI = 'api.openai.com/v1/models';

    private const string MODELOS_GEMINI = 'generativelanguage.googleapis.com/v1beta/models?pageSize=1000';

    private const string TESTE_OPENAI = 'api.openai.com/v1/responses';

    private const string TESTE_GEMINI = 'generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent';

    // Fora do formato real das chaves, que os scanners de segredo do GitHub barram.
    private const string CHAVE_DIGITADA = 'sk-teste-chave-digitada-0123456789abcdef';

    private const string CHAVE_GUARDADA = 'gemini-teste-chave-guardada-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_lista_os_modelos_de_texto_da_openai_com_a_chave_digitada_e_os_mais_novos_primeiro(): void
    {
        ConexaoIa::factory()->create(['chave_api' => 'sk-teste-chave-antiga-guardada-0123456789']);
        Http::fake([self::MODELOS_OPENAI => Http::response(['object' => 'list', 'data' => [
            ['id' => 'gpt-5.4-mini', 'object' => 'model', 'created' => 1780000000, 'owned_by' => 'system'],
            ['id' => 'text-embedding-3-small', 'object' => 'model', 'created' => 1790000000, 'owned_by' => 'system'],
            ['id' => 'gpt-5.4', 'object' => 'model', 'created' => 1790000000, 'owned_by' => 'system'],
            ['id' => 'whisper-1', 'object' => 'model', 'created' => 1600000000, 'owned_by' => 'system'],
            ['id' => 'gpt-image-1', 'object' => 'model', 'created' => 1750000000, 'owned_by' => 'system'],
            ['id' => 'gpt-realtime', 'object' => 'model', 'created' => 1760000000, 'owned_by' => 'system'],
            ['id' => 'o3-deep-research', 'object' => 'model', 'created' => 1770000000, 'owned_by' => 'system'],
            ['id' => 'o4-mini', 'object' => 'model', 'created' => 1700000000, 'owned_by' => 'system'],
        ]])]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_MODELOS, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertOk()->assertExactJson(['ok' => true, 'modelos' => ['gpt-5.4', 'gpt-5.4-mini', 'o4-mini']]);
        Http::assertSent(fn (Request $pedido) => $pedido->url() === 'https://'.self::MODELOS_OPENAI
            && $pedido->hasHeader('Authorization', 'Bearer '.self::CHAVE_DIGITADA));
    }

    public function test_lista_os_modelos_do_gemini_com_a_chave_guardada_so_no_cabecalho(): void
    {
        ConexaoIa::factory()->gemini()->create(['chave_api' => self::CHAVE_GUARDADA]);
        Http::fake([self::MODELOS_GEMINI => Http::response(['models' => [
            ['name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => ['generateContent', 'countTokens']],
            ['name' => 'models/text-embedding-005', 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'models/gemini-3.8-flash-image', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/imagen-5', 'supportedGenerationMethods' => ['predict']],
            ['name' => 'models/gemini-3.5-pro', 'supportedGenerationMethods' => ['generateContent']],
        ]])]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_MODELOS, ['provedor' => 'gemini']);

        $resposta->assertOk()->assertExactJson(['ok' => true, 'modelos' => ['gemini-3.8-flash', 'gemini-3.5-pro']]);
        Http::assertSent(fn (Request $pedido) => $pedido->hasHeader('x-goog-api-key', self::CHAVE_GUARDADA)
            && ! str_contains($pedido->url(), self::CHAVE_GUARDADA));
    }

    /**
     * @return array<string, array{string, string, string, string, array<string, mixed>}>
     */
    public static function perguntasDeTeste(): array
    {
        return [
            'OpenAI' => ['openai', 'gpt-5.4', self::TESTE_OPENAI, 'OpenAI', [
                'model' => 'gpt-5.4',
                'input' => 'Responda apenas: OK',
                'max_output_tokens' => 16,
                'store' => false,
            ]],
            'Google Gemini' => ['gemini', 'gemini-3.8-flash', self::TESTE_GEMINI, 'Google Gemini', [
                'contents' => [['parts' => [['text' => 'Responda apenas: OK']]]],
                'generationConfig' => ['maxOutputTokens' => 16],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    #[DataProvider('perguntasDeTeste')]
    public function test_teste_de_conexao_faz_uma_pergunta_curta_ao_modelo_escolhido(string $provedor, string $modelo, string $endereco, string $rotulo, array $corpo): void
    {
        Http::fake([$endereco => Http::response(['resposta' => 'OK'])]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_TESTE, ['provedor' => $provedor, 'modelo' => $modelo, 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertOk()->assertJsonPath('ok', true);
        $this->assertMatchesRegularExpression(
            '/^Conexão funcionando: '.preg_quote($rotulo, '/').' respondeu em \d+,\d s com o modelo '.preg_quote($modelo, '/').'\.$/u',
            $resposta->json('mensagem'),
        );
        Http::assertSent(fn (Request $pedido) => $pedido->method() === 'POST'
            && $pedido->url() === 'https://'.$endereco
            && $pedido->data() === $corpo);
    }

    /**
     * @return array<string, array{string, string, string, int, array<string, mixed>, string}>
     */
    public static function falhasDoProvedor(): array
    {
        return [
            'chave recusada pela OpenAI' => [
                'openai', 'gpt-5.4', self::TESTE_OPENAI, 401, ['error' => ['message' => 'Incorrect API key provided.']],
                'OpenAI recusou a chave da API. Confira se ela foi copiada inteira e se ainda está ativa.',
            ],
            'chave recusada pelo Gemini' => [
                'gemini', 'gemini-3.8-flash', self::TESTE_GEMINI, 400,
                ['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT', 'details' => [
                    ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com'],
                ]]],
                'Google Gemini recusou a chave da API. Confira se ela foi copiada inteira e se ainda está ativa.',
            ],
            'acesso negado' => [
                'gemini', 'gemini-3.8-flash', self::TESTE_GEMINI, 403, ['error' => ['code' => 403, 'message' => 'Permission denied.', 'status' => 'PERMISSION_DENIED']],
                'Google Gemini recusou o acesso desta chave. Confira as permissões dela e o projeto no painel do provedor.',
            ],
            'modelo inexistente' => [
                'openai', 'gpt-5.4', self::TESTE_OPENAI, 404, ['error' => ['message' => 'The model `gpt-5.4` does not exist or you do not have access to it.']],
                'O modelo gpt-5.4 não existe ou não está liberado para esta chave.',
            ],
            'cota esgotada' => [
                'openai', 'gpt-5.4', self::TESTE_OPENAI, 429, ['error' => ['message' => 'You exceeded your current quota.']],
                'OpenAI: limite de uso ou cota da conta esgotados. Confira o plano e o saldo da conta.',
            ],
            'instabilidade' => [
                'gemini', 'gemini-3.8-flash', self::TESTE_GEMINI, 503, ['error' => ['message' => 'The model is overloaded.']],
                'Google Gemini está com instabilidade. Tente de novo em alguns minutos.',
            ],
            'pedido recusado' => [
                'openai', 'gpt-5.4', self::TESTE_OPENAI, 400, ['error' => ['message' => "Unsupported parameter: 'store'."]],
                "OpenAI recusou o pedido (erro 400): Unsupported parameter: 'store'.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    #[DataProvider('falhasDoProvedor')]
    public function test_falha_do_provedor_volta_com_o_motivo_em_portugues(string $provedor, string $modelo, string $endereco, int $status, array $corpo, string $mensagem): void
    {
        Http::fake([$endereco => Http::response($corpo, $status)]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_TESTE, ['provedor' => $provedor, 'modelo' => $modelo, 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertOk()->assertExactJson(['ok' => false, 'mensagem' => $mensagem]);
    }

    public function test_provedor_sem_resposta_volta_com_o_motivo(): void
    {
        Http::fake([self::TESTE_OPENAI => Http::failedConnection()]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_TESTE, ['provedor' => 'openai', 'modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertOk()->assertExactJson([
            'ok' => false,
            'mensagem' => 'OpenAI não respondeu a tempo. Confira a conexão do servidor com a internet e tente de novo.',
        ]);
    }

    public function test_lista_fora_do_formato_esperado_volta_com_aviso(): void
    {
        Http::fake([self::MODELOS_OPENAI => Http::response('<html>Proxy da rede</html>')]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_MODELOS, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertOk()->assertExactJson([
            'ok' => false,
            'mensagem' => 'OpenAI respondeu num formato inesperado. Tente de novo mais tarde.',
        ]);
    }

    public function test_falha_registra_no_log_so_provedor_operacao_e_status(): void
    {
        Log::spy();
        Http::fake([self::TESTE_OPENAI => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401)]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $this->postJson(self::URL_TESTE, ['provedor' => 'openai', 'modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_DIGITADA]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Integração com IA: chamada ao provedor falhou.', ['provedor' => 'openai', 'operacao' => 'teste', 'status' => 401]);
    }

    public function test_sem_chave_digitada_nem_guardada_nao_chama_o_provedor(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_MODELOS, ['provedor' => 'gemini']);

        $resposta->assertUnprocessable()->assertJsonValidationErrors(['chave_api' => 'Informe a chave da API.']);
        Http::assertNothingSent();
    }

    public function test_teste_sem_modelo_nao_chama_o_provedor(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');

        $resposta = $this->postJson(self::URL_TESTE, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertUnprocessable()->assertJsonValidationErrors(['modelo' => 'Este campo é obrigatório.']);
        Http::assertNothingSent();
    }

    public function test_proponente_nao_chega_ao_provedor(): void
    {
        Http::fake();
        $this->actingAs(User::factory()->proponente()->create());

        $this->post(self::URL_MODELOS, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA])->assertRedirect('/retaguarda/login');
        $this->post(self::URL_TESTE, ['provedor' => 'openai', 'modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_DIGITADA])->assertRedirect('/retaguarda/login');

        Http::assertNothingSent();
    }

    public function test_visitante_recebe_401_sem_chegar_ao_provedor(): void
    {
        Http::fake();

        $resposta = $this->postJson(self::URL_TESTE, ['provedor' => 'openai', 'modelo' => 'gpt-5.4', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_limita_as_chamadas_ao_provedor_a_dez_por_minuto(): void
    {
        Http::fake([self::MODELOS_OPENAI => Http::response(['object' => 'list', 'data' => []])]);
        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
        for ($chamada = 1; $chamada <= 10; $chamada++) {
            $this->postJson(self::URL_MODELOS, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA])->assertOk();
        }

        $resposta = $this->postJson(self::URL_MODELOS, ['provedor' => 'openai', 'chave_api' => self::CHAVE_DIGITADA]);

        $resposta->assertTooManyRequests();
        Http::assertSentCount(10);
    }
}
