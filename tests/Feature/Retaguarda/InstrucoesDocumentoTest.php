<?php

namespace Tests\Feature\Retaguarda;

use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstrucoesDocumentoTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/editais/instrucoes-ia';

    private const string URL_OPENAI = 'https://api.openai.com/v1/responses';

    private const string URL_GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';

    private const array EDITAL = [
        'titulo' => 'Salvador Circula',
        'objeto' => 'Fomento à circulação de espetáculos pelos bairros.',
        'naturezas' => ['pessoa_fisica', 'mei'],
        'categorias' => ['Música', 'Teatro'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // OpenAI de principal e Gemini de redundância, como na Integração com IA.
        ConfiguracaoIa::factory()->comRedundancia()->create();
        ConexaoIa::factory()->create(['modelo' => 'gpt-5.4']);
        ConexaoIa::factory()->gemini()->create(['modelo' => 'gemini-2.5-pro']);

        $this->actingAs(User::factory()->gestor()->create(), 'retaguarda');
    }

    /**
     * @return list<array{nome: string, orientacao: string|null, etapa: string}>
     */
    private static function documentos(int $quantidade): array
    {
        return array_map(fn (int $numero): array => [
            'nome' => "Documento {$numero}",
            'orientacao' => null,
            'etapa' => 'inscricao',
        ], range(1, $quantidade));
    }

    /**
     * @param  list<array<string, mixed>>  $documentos
     */
    private function gerar(array $documentos): TestResponse
    {
        return $this->postJson(self::URL, ['edital' => self::EDITAL, 'documentos' => $documentos]);
    }

    /**
     * @param  list<mixed>  $instrucoes
     * @return array<string, mixed>
     */
    private static function corpoDaOpenAi(array $instrucoes): array
    {
        return [
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['instrucoes' => $instrucoes])]]]],
            'usage' => ['input_tokens' => 800, 'output_tokens' => 300, 'total_tokens' => 1100],
        ];
    }

    /**
     * @param  list<mixed>  $instrucoes
     */
    private static function respostaDoGemini(array $instrucoes): PromiseInterface
    {
        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['instrucoes' => $instrucoes])]]]]],
            'usageMetadata' => ['promptTokenCount' => 800, 'candidatesTokenCount' => 300, 'totalTokenCount' => 1100],
        ]);
    }

    /** O texto (o prompt) do pedido feito à OpenAI. */
    private static function promptDoPedido(Request $pedido): string
    {
        return (string) collect($pedido->data()['input'][0]['content'])->firstWhere('type', 'input_text')['text'];
    }

    public function test_gera_instrucoes_na_ordem_dos_documentos(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([
            'Confira se é um documento de identidade com foto, legível e em nome do proponente.',
            'Confira se é a declaração de residência, assinada e com endereço em Salvador.',
        ]))]);

        $resposta = $this->gerar([
            ['nome' => 'Documento de identidade', 'orientacao' => 'RG ou CNH, frente e verso.', 'etapa' => 'inscricao'],
            ['nome' => 'Declaração de residência', 'orientacao' => null, 'etapa' => 'habilitacao'],
        ]);

        $resposta->assertOk()->assertExactJson(['instrucoes' => [
            'Confira se é um documento de identidade com foto, legível e em nome do proponente.',
            'Confira se é a declaração de residência, assinada e com endereço em Salvador.',
        ]]);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $pedido): bool {
            $prompt = self::promptDoPedido($pedido);
            $conteudo = $pedido->data()['input'][0]['content'];

            // Só texto (nenhum arquivo), temperatura 0,2 e o contexto do edital e dos documentos no prompt, na ordem.
            return count($conteudo) === 1
                && $pedido->data()['temperature'] === 0.2
                && str_contains($prompt, 'Salvador Circula')
                && str_contains($prompt, 'Fomento à circulação de espetáculos pelos bairros.')
                && str_contains($prompt, 'Pessoa Física, MEI')
                && str_contains($prompt, 'Música, Teatro')
                && str_contains($prompt, 'RG ou CNH, frente e verso.')
                && str_contains($prompt, 'Habilitação')
                && strpos($prompt, 'Documento de identidade') < strpos($prompt, 'Declaração de residência');
        });
    }

    public function test_divide_em_lotes_de_quinze(): void
    {
        Http::fake([self::URL_OPENAI => Http::sequence()
            ->push(self::corpoDaOpenAi(array_map(fn (int $numero): string => "Instrução {$numero}", range(1, 15))))
            ->push(self::corpoDaOpenAi(array_map(fn (int $numero): string => "Instrução {$numero}", range(16, 20))))]);

        $resposta = $this->gerar(self::documentos(20));

        $resposta->assertOk()->assertExactJson(['instrucoes' => array_map(fn (int $numero): string => "Instrução {$numero}", range(1, 20))]);
        Http::assertSentCount(2);
        [$primeiro, $segundo] = array_map(fn (array $par): string => self::promptDoPedido($par[0]), Http::recorded()->all());
        $this->assertStringContainsString('Documento 15', $primeiro);
        $this->assertStringNotContainsString('Documento 16', $primeiro);
        $this->assertStringContainsString('Documento 16', $segundo);
        $this->assertStringContainsString('Documento 20', $segundo);
        $this->assertStringNotContainsString('Documento 15', $segundo);
    }

    public function test_falha_da_ia_responde_mensagem_sem_detalhe(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401),
            self::URL_GEMINI => Http::response('erro', 503),
        ]);

        $resposta = $this->gerar(self::documentos(2));

        $resposta->assertUnprocessable()
            ->assertExactJson(['erro' => 'Serviço de IA indisponível no momento. Caso persista, contate o administrador.']);
    }

    public function test_resposta_com_quantidade_errada_passa_para_a_redundancia(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Só uma instrução.'])),
            self::URL_GEMINI => self::respostaDoGemini(['Instrução do primeiro.', 'Instrução do segundo.']),
        ]);

        $resposta = $this->gerar(self::documentos(2));

        $resposta->assertOk()->assertExactJson(['instrucoes' => ['Instrução do primeiro.', 'Instrução do segundo.']]);
        Http::assertSentCount(2);
    }

    public function test_instrucao_longa_e_cortada_em_2000_bytes_sem_quebrar_caractere(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([str_repeat('é', 1500)]))]);

        $resposta = $this->gerar(self::documentos(1));

        $resposta->assertOk()->assertExactJson(['instrucoes' => [str_repeat('é', 1000)]]);
    }

    #[TestWith([0])]
    #[TestWith([61])]
    public function test_sem_documentos_ou_com_mais_de_60_nao_chama_a_ia(int $quantidade): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([]))]);

        $resposta = $this->gerar($quantidade === 0 ? [] : self::documentos($quantidade));

        $resposta->assertUnprocessable()->assertJsonValidationErrors('documentos');
        Http::assertNothingSent();
    }

    public function test_limita_a_dez_geracoes_por_minuto(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Instrução.']))]);
        for ($chamada = 1; $chamada <= 10; $chamada++) {
            $this->gerar(self::documentos(1))->assertOk();
        }

        $resposta = $this->gerar(self::documentos(1));

        $resposta->assertTooManyRequests();
        Http::assertSentCount(10);
    }

    public function test_quem_nao_e_gestor_nao_gera(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Instrução.']))]);
        $this->actingAs(User::factory()->proponente()->create());

        $this->post(self::URL, ['edital' => self::EDITAL, 'documentos' => self::documentos(1)])->assertRedirect('/retaguarda/login');
        Http::assertNothingSent();
    }
}
