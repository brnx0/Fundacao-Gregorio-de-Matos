<?php

namespace Tests\Feature\Retaguarda;

use App\Http\Requests\Retaguarda\AjustarDescricaoRequest;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\User;
use App\Support\EditalIa\RedatorDaDescricao;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DescricaoDoCardTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/editais/descricao-ia';

    private const string URL_OPENAI = 'https://api.openai.com/v1/responses';

    private const string URL_GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';

    private const array EDITAL = [
        'titulo' => 'Ateliê Itinerante de Restauro',
        'objeto' => 'Seleção de propostas para um ateliê itinerante de restauro nos bairros.',
        'categoria' => 'Patrimônio',
        'texto' => 'O edital visa a seleção de propostas para a implementação de um ateliê itinerante de restauro.',
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
     * @param  array<string, string|null>  $edital
     */
    private function ajustar(array $edital = self::EDITAL): TestResponse
    {
        return $this->postJson(self::URL, $edital);
    }

    /**
     * @return array<string, mixed>
     */
    private static function corpoDaOpenAi(mixed $descricao): array
    {
        return [
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(['descricao' => $descricao])]]]],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 80, 'total_tokens' => 480],
        ];
    }

    private static function respostaDoGemini(string $descricao): PromiseInterface
    {
        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['descricao' => $descricao])]]]]],
            'usageMetadata' => ['promptTokenCount' => 400, 'candidatesTokenCount' => 80, 'totalTokenCount' => 480],
        ]);
    }

    /** O texto (o prompt) do pedido feito à OpenAI. */
    private static function promptDoPedido(Request $pedido): string
    {
        return (string) collect($pedido->data()['input'][0]['content'])->firstWhere('type', 'input_text')['text'];
    }

    public function test_ajusta_o_texto_com_o_contexto_do_edital(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi('  Oficinas de restauro nos bairros de Salvador para formar jovens auxiliares.  '))]);

        $this->ajustar()->assertOk()->assertExactJson(['descricao' => 'Oficinas de restauro nos bairros de Salvador para formar jovens auxiliares.']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $pedido): bool {
            $prompt = self::promptDoPedido($pedido);

            // Só texto (nenhum arquivo), com o tamanho pedido, os dados do edital e o texto atual.
            return count($pedido->data()['input'][0]['content']) === 1
                && str_contains($prompt, 'no máximo '.RedatorDaDescricao::TAMANHO_IDEAL.' caracteres')
                && str_contains($prompt, 'Ateliê Itinerante de Restauro')
                && str_contains($prompt, 'Seleção de propostas para um ateliê itinerante de restauro nos bairros.')
                && str_contains($prompt, 'Patrimônio')
                && str_contains($prompt, 'O edital visa a seleção de propostas');
        });
    }

    public function test_sem_texto_escreve_a_partir_do_titulo_e_do_objeto(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi('Descrição nova.'))]);

        $this->ajustar([...self::EDITAL, 'texto' => null])->assertOk()->assertExactJson(['descricao' => 'Descrição nova.']);

        Http::assertSent(fn (Request $pedido): bool => str_contains(self::promptDoPedido($pedido), "Texto atual da descrição:\n(vazio)"));
    }

    public function test_sem_titulo_objeto_nem_texto_nao_chama_a_ia(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi('Inventada.'))]);

        $this->ajustar(['titulo' => '', 'objeto' => null, 'categoria' => 'Fomento', 'texto' => ' '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['texto' => AjustarDescricaoRequest::SEM_CONTEXTO]);
        Http::assertNothingSent();
    }

    public function test_resposta_vazia_passa_para_a_redundancia(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(self::corpoDaOpenAi('')),
            self::URL_GEMINI => self::respostaDoGemini('Descrição da redundância.'),
        ]);

        $this->ajustar()->assertOk()->assertExactJson(['descricao' => 'Descrição da redundância.']);
        Http::assertSentCount(2);
    }

    public function test_descricao_longa_e_cortada(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(str_repeat('é', 900)))]);

        $this->ajustar()->assertOk()->assertExactJson(['descricao' => str_repeat('é', RedatorDaDescricao::TAMANHO_MAXIMO)]);
    }

    public function test_falha_da_ia_responde_mensagem_sem_detalhe(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401),
            self::URL_GEMINI => Http::response('erro', 503),
        ]);

        $this->ajustar()
            ->assertUnprocessable()
            ->assertExactJson(['erro' => 'Serviço de IA indisponível no momento. Caso persista, contate o administrador.']);
    }

    public function test_limita_a_dez_ajustes_por_minuto(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi('Descrição.'))]);
        for ($chamada = 1; $chamada <= 10; $chamada++) {
            $this->ajustar()->assertOk();
        }

        $this->ajustar()->assertTooManyRequests();
        Http::assertSentCount(10);
    }

    public function test_quem_nao_e_gestor_nao_ajusta(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi('Descrição.'))]);
        $this->actingAs(User::factory()->proponente()->create());

        $this->post(self::URL, self::EDITAL)->assertRedirect('/retaguarda/login');
        Http::assertNothingSent();
    }
}
