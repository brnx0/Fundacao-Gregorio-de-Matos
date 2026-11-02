<?php

namespace Tests\Feature\Retaguarda;

use App\Enums\AnexoCadastro;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstrucoesDosAnexosTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = '/retaguarda/anexos-cadastro/instrucoes-ia';

    private const string URL_OPENAI = 'https://api.openai.com/v1/responses';

    private const string URL_GEMINI = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent';

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
     * @param  list<mixed>  $campos
     */
    private function gerar(array $campos): TestResponse
    {
        return $this->postJson(self::URL, ['campos' => $campos]);
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

    public function test_gera_uma_instrucao_por_anexo_na_ordem_pedida(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([
            'Confira se é a cópia do RG da pessoa do cadastro, legível.',
            'Confira se é a foto de uma pessoa com o rosto visível.',
            'Confira se o endereço do comprovante é o do cadastro.',
        ]))]);

        $resposta = $this->gerar(['documento_rg', 'foto_perfil', 'comprovante_residencia']);

        $resposta->assertOk()->assertExactJson(['instrucoes' => [
            'documento_rg' => 'Confira se é a cópia do RG da pessoa do cadastro, legível.',
            'foto_perfil' => 'Confira se é a foto de uma pessoa com o rosto visível.',
            'comprovante_residencia' => 'Confira se o endereço do comprovante é o do cadastro.',
        ]]);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $pedido): bool {
            $prompt = self::promptDoPedido($pedido);

            // Só texto (nenhum arquivo), temperatura 0,2, sem edital e, na ordem pedida, cada anexo com a descrição,
            // os dados do cadastro que a IA recebe e se é obrigatório.
            return count($pedido->data()['input'][0]['content']) === 1
                && $pedido->data()['temperature'] === 0.2
                && str_contains($prompt, 'não há edital')
                && ! str_contains($prompt, 'Título:')
                && str_contains($prompt, "1. Cópia do RG\n   Descrição: ".AnexoCadastro::DocumentoRg->descricao()
                    ."\n   Dados do cadastro que a IA recebe: nome completo, número do RG, data de nascimento\n   Obrigatório no cadastro.")
                && str_contains($prompt, "2. Foto de perfil\n   Descrição: ".AnexoCadastro::FotoPerfil->descricao()
                    ."\n   Dados do cadastro que a IA recebe: nenhum\n   Opcional no cadastro.")
                && str_contains($prompt, '3. Comprovante de residência');
        });
    }

    public function test_gera_os_sete_anexos_num_pedido_so(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(array_map(fn (string $campo): string => "Instrução de {$campo}.", AnexoCadastro::valores())))]);

        $resposta = $this->gerar(AnexoCadastro::valores());

        $resposta->assertOk()->assertExactJson(['instrucoes' => array_combine(
            AnexoCadastro::valores(),
            array_map(fn (string $campo): string => "Instrução de {$campo}.", AnexoCadastro::valores()),
        )]);
        Http::assertSentCount(1);
    }

    public function test_gera_mesmo_com_a_validacao_por_ia_desligada(): void
    {
        ConfiguracaoIa::query()->update(['ativo' => false]);
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Confira o CPF.']))]);

        $resposta = $this->gerar(['documento_cpf']);

        $resposta->assertOk()->assertExactJson(['instrucoes' => ['documento_cpf' => 'Confira o CPF.']]);
    }

    public function test_falha_da_ia_responde_mensagem_sem_detalhe(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(['error' => ['message' => 'Incorrect API key provided.']], 401),
            self::URL_GEMINI => Http::response('erro', 503),
        ]);

        $resposta = $this->gerar(['documento_rg']);

        $resposta->assertUnprocessable()
            ->assertExactJson(['erro' => 'Serviço de IA indisponível no momento. Caso persista, contate o administrador.']);
    }

    public function test_resposta_com_quantidade_errada_passa_para_a_redundancia(): void
    {
        Http::fake([
            self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Só uma instrução.'])),
            self::URL_GEMINI => self::respostaDoGemini(['Instrução do CPF.', 'Instrução do RG.']),
        ]);

        $resposta = $this->gerar(['documento_cpf', 'documento_rg']);

        $resposta->assertOk()->assertExactJson(['instrucoes' => ['documento_cpf' => 'Instrução do CPF.', 'documento_rg' => 'Instrução do RG.']]);
        Http::assertSentCount(2);
    }

    public function test_instrucao_longa_e_cortada_em_2000_bytes_sem_quebrar_caractere(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([str_repeat('é', 1500)]))]);

        $resposta = $this->gerar(['documento_rg']);

        $resposta->assertOk()->assertExactJson(['instrucoes' => ['documento_rg' => str_repeat('é', 1000)]]);
    }

    /**
     * @return array<string, array{0: list<mixed>, 1: string}>
     */
    public static function camposInvalidos(): array
    {
        return [
            'lista vazia' => [[], 'campos'],
            'campo fora da lista' => [['documento_cnh'], 'campos.0'],
            'campo repetido' => [['documento_rg', 'documento_rg'], 'campos.1'],
        ];
    }

    /**
     * @param  list<mixed>  $campos
     */
    #[DataProvider('camposInvalidos')]
    public function test_campo_invalido_nao_chama_a_ia(array $campos, string $erro): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi([]))]);

        $resposta = $this->gerar($campos);

        $resposta->assertUnprocessable()->assertJsonValidationErrors($erro);
        Http::assertNothingSent();
    }

    public function test_limita_a_dez_geracoes_por_minuto(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Instrução.']))]);
        for ($chamada = 1; $chamada <= 10; $chamada++) {
            $this->gerar(['documento_rg'])->assertOk();
        }

        $resposta = $this->gerar(['documento_rg']);

        $resposta->assertTooManyRequests();
        Http::assertSentCount(10);
    }

    public function test_quem_nao_e_gestor_nao_gera(): void
    {
        Http::fake([self::URL_OPENAI => Http::response(self::corpoDaOpenAi(['Instrução.']))]);
        $this->actingAs(User::factory()->proponente()->create());

        $this->post(self::URL, ['campos' => ['documento_rg']])->assertRedirect('/retaguarda/login');
        Http::assertNothingSent();
    }
}
