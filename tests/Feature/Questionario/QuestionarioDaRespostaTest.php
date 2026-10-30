<?php

namespace Tests\Feature\Questionario;

use App\Enums\NaturezaProponente;
use App\Http\Requests\Retaguarda\RegrasDoQuestionario;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Support\EditalIa\EsquemaDaResposta;
use App\Support\EditalIa\EsquemaDoQuestionario;
use App\Support\EditalIa\EsquemaOpenAi;
use App\Support\EditalIa\PromptDoQuestionario;
use App\Support\EditalIa\QuestionarioDaResposta;
use App\Support\EditalIa\RespostaInvalida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RespostaDoQuestionario;
use Tests\TestCase;

class QuestionarioDaRespostaTest extends TestCase
{
    use RefreshDatabase;
    use RespostaDoQuestionario;

    private Edital $edital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->edital = Edital::factory()->create(['naturezas' => [NaturezaProponente::PessoaFisica, NaturezaProponente::Mei]]);
        EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Teatro', 'naturezas' => null, 'ordem' => 1]);
        EditalCategoria::factory()->create(['edital_id' => $this->edital->id, 'nome' => 'Dança', 'naturezas' => ['pessoa_fisica'], 'ordem' => 2]);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function montar(array $dados): array
    {
        return (new QuestionarioDaResposta)->montar($dados, $this->edital->fresh());
    }

    /**
     * Resposta com uma seção só, com as perguntas dadas.
     *
     * @param  list<array<string, mixed>>  $perguntas
     * @return array<string, mixed>
     */
    private function comPerguntas(array $perguntas): array
    {
        return $this->respostaDoQuestionario([
            'secoes' => [['titulo' => 'Proposta', 'descricao' => null, 'perguntas' => array_map(self::perguntaDaResposta(...), $perguntas)]],
            'atencao' => [],
        ]);
    }

    /**
     * Perguntas montadas, na ordem global.
     *
     * @param  array<string, mixed>  $montado
     * @return list<array<string, mixed>>
     */
    private static function perguntas(array $montado): array
    {
        return array_merge(...array_column($montado['questionario']['secoes'], 'perguntas'));
    }

    /**
     * @param  array<string, mixed>  $montado
     * @return list<string>
     */
    private static function avisos(array $montado): array
    {
        return array_values(array_column(array_filter($montado['atencao'], fn (array $ponto): bool => $ponto['tipo'] === 'incerta'), 'texto'));
    }

    public function test_esquema_traduz_para_o_openai_strict(): void
    {
        $esquema = EsquemaOpenAi::de(EsquemaDoQuestionario::esquema());

        $this->assertSame(['secoes', 'confiabilidade', 'atencao'], $esquema['required']);
        $this->assertFalse($esquema['additionalProperties']);

        $pergunta = $esquema['properties']['secoes']['items']['properties']['perguntas']['items'];
        $this->assertFalse($pergunta['additionalProperties']);
        $this->assertSame(array_keys($pergunta['properties']), $pergunta['required']);
        $this->assertSame('string', $pergunta['properties']['enunciado']['type']);
        $this->assertSame('string', $pergunta['properties']['tipo']['type']);
        $this->assertContains('declaracao', $pergunta['properties']['tipo']['enum']);
        $this->assertSame(['object', 'null'], $pergunta['properties']['condicao']['type']);
        $this->assertFalse($pergunta['properties']['condicao']['additionalProperties']);
        $this->assertSame(['pergunta', 'respostas'], $pergunta['properties']['condicao']['required']);
        $this->assertFalse($pergunta['properties']['opcoes']['items']['additionalProperties']);
        $this->assertSame(['tipo', 'texto', 'itens'], $esquema['properties']['atencao']['items']['required']);
    }

    public function test_esquema_do_edital_nao_muda(): void
    {
        // sha256 do json_encode (JSON_UNESCAPED_UNICODE) de EsquemaDaResposta::esquema() antes de os helpers irem para MontaEsquema.
        $this->assertSame(
            '68781c2919c430a5c96472c197cc9d18350b0ae7a38c6f67f0a039da4ab951e8',
            hash('sha256', (string) json_encode(EsquemaDaResposta::esquema(), JSON_UNESCAPED_UNICODE)),
        );
    }

    public function test_monta_secoes_perguntas_opcoes_e_declaracao_obrigatoria(): void
    {
        $montado = $this->montar($this->respostaDoQuestionario());

        $secoes = $montado['questionario']['secoes'];
        $this->assertSame(['Sobre a proposta', 'Declarações'], array_column($secoes, 'titulo'));
        $this->assertSame(['Conte sobre o projeto.', null], array_column($secoes, 'descricao'));

        [$descricao, $linguagem, $fomento, $editais, $espacos, $declaracao] = self::perguntas($montado);

        $this->assertSame('texto_longo', $descricao['tipo']);
        $this->assertSame(1000, $descricao['limite_caracteres']);
        $this->assertSame('Objetivos e público.', $descricao['ajuda']);
        $this->assertTrue($descricao['obrigatoria']);

        $this->assertSame(['Teatro', 'Dança', 'Outra (especificar)'], array_column($linguagem['opcoes'], 'texto'));
        $this->assertSame([false, false, true], array_column($linguagem['opcoes'], 'outro'));
        foreach ($linguagem['opcoes'] as $opcao) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $opcao['chave']);
        }
        $this->assertNull($fomento['opcoes']);

        $this->assertSame(2, $editais['condicao_pergunta']);
        $this->assertSame(['sim'], $editais['condicao_opcoes']);
        $this->assertSame([0], $espacos['categorias']);
        $this->assertFalse($espacos['todas_categorias']);

        $this->assertSame('declaracao', $declaracao['tipo']);
        $this->assertTrue($declaracao['obrigatoria']);

        $this->assertSame(['geral' => 85, 'blocos' => ['questionario' => ['nota' => 85, 'justificativa' => 'Formulário claro.']]], $montado['confiabilidade']);
        $this->assertSame(
            [['bloco' => 'questionario', 'tipo' => 'incerta', 'texto' => 'A ficha técnica não virou pergunta.', 'itens' => ['Ficha técnica']]],
            $montado['atencao'],
        );

        $validator = Validator::make(['questionario' => $montado['questionario']], RegrasDoQuestionario::regras([0, 1]));
        $validator->after(fn ($validator) => RegrasDoQuestionario::conferir($validator, $montado['questionario']));
        $this->assertSame([], $validator->errors()->all());
    }

    public function test_vincula_pelo_nome_sem_acento_e_caixa(): void
    {
        $montado = $this->montar($this->comPerguntas([['categorias' => ['DANCA', '  teatro ']]]));

        $this->assertSame([1, 0], self::perguntas($montado)[0]['categorias']);
        $this->assertSame([], self::avisos($montado));
    }

    public function test_categoria_desconhecida_deixa_sem_vinculo_com_aviso(): void
    {
        $montado = $this->montar($this->comPerguntas([['enunciado' => 'Repertório', 'categorias' => ['Teatro', 'Circo']]]));

        $pergunta = self::perguntas($montado)[0];
        $this->assertSame([], $pergunta['categorias']);
        $this->assertFalse($pergunta['todas_categorias']);
        $this->assertSame(['A pergunta "Repertório" cita a categoria "Circo", que não está no edital; ficou sem vínculo.'], self::avisos($montado));
    }

    public function test_sem_categorias_fica_sem_vinculo(): void
    {
        $montado = $this->montar($this->comPerguntas([['categorias' => []]]));

        $pergunta = self::perguntas($montado)[0];
        $this->assertSame([], $pergunta['categorias']);
        $this->assertFalse($pergunta['todas_categorias']);
        $this->assertArrayNotHasKey('naturezas', $pergunta);
        $this->assertSame([], self::avisos($montado));
    }

    public function test_condicao_resolve_origem_e_respostas_em_chaves(): void
    {
        $montado = $this->montar($this->comPerguntas([
            [
                'enunciado' => 'Linguagem',
                'tipo' => 'escolha_unica',
                'opcoes' => [['texto' => 'Teatro', 'outro' => false], ['texto' => 'Dança', 'outro' => false], ['texto' => 'Outra', 'outro' => true]],
            ],
            ['enunciado' => 'Já se apresentou?', 'tipo' => 'sim_nao'],
            ['enunciado' => 'Qual grupo?', 'condicao' => ['pergunta' => '  LINGUAGEM ', 'respostas' => ['teatro', 'OUTRA']]],
            ['enunciado' => 'Por quê?', 'condicao' => ['pergunta' => 'Já se apresentou?', 'respostas' => ['Nao']]],
        ]));

        [$linguagem, , $grupo, $porque] = self::perguntas($montado);
        $this->assertSame(0, $grupo['condicao_pergunta']);
        $this->assertSame([$linguagem['opcoes'][0]['chave'], $linguagem['opcoes'][2]['chave']], $grupo['condicao_opcoes']);
        $this->assertSame(1, $porque['condicao_pergunta']);
        $this->assertSame(['nao'], $porque['condicao_opcoes']);
        $this->assertSame([], self::avisos($montado));
    }

    public function test_condicao_que_nao_resolve_vira_aviso(): void
    {
        $montado = $this->montar($this->comPerguntas([
            ['enunciado' => 'Descreva', 'tipo' => 'texto_longo'],
            ['enunciado' => 'Já se apresentou?', 'tipo' => 'sim_nao'],
            ['enunciado' => 'A', 'condicao' => ['pergunta' => 'Descreva', 'respostas' => ['Sim']]],
            ['enunciado' => 'B', 'condicao' => ['pergunta' => 'Não existe', 'respostas' => ['Sim']]],
            ['enunciado' => 'C', 'condicao' => ['pergunta' => 'Já se apresentou?', 'respostas' => ['Talvez']]],
        ]));

        foreach (array_slice(self::perguntas($montado), 2) as $pergunta) {
            $this->assertNull($pergunta['condicao_pergunta']);
            $this->assertNull($pergunta['condicao_opcoes']);
        }
        $avisos = self::avisos($montado);
        $this->assertContains('A condição da pergunta "A" não foi reconhecida; a pergunta ficou sem condição.', $avisos);
        $this->assertContains('A condição da pergunta "B" não foi reconhecida; a pergunta ficou sem condição.', $avisos);
        $this->assertContains('A condição da pergunta "C" não foi reconhecida; a pergunta ficou sem condição.', $avisos);
    }

    public function test_condicao_para_pergunta_posterior_nao_vale(): void
    {
        $montado = $this->montar($this->comPerguntas([
            ['enunciado' => 'Quais editais?', 'condicao' => ['pergunta' => 'Recebeu fomento?', 'respostas' => ['Sim']]],
            ['enunciado' => 'Recebeu fomento?', 'tipo' => 'sim_nao'],
        ]));

        $this->assertNull(self::perguntas($montado)[0]['condicao_pergunta']);
        $this->assertSame(['A condição da pergunta "Quais editais?" não foi reconhecida; a pergunta ficou sem condição.'], self::avisos($montado));
    }

    public function test_tipo_desconhecido_e_escolha_sem_opcoes_sao_descartados(): void
    {
        $montado = $this->montar($this->respostaDoQuestionario([
            'secoes' => [
                ['titulo' => 'Só ruins', 'descricao' => null, 'perguntas' => [
                    self::perguntaDaResposta(['enunciado' => 'Anexe o portfólio', 'tipo' => 'arquivo']),
                    self::perguntaDaResposta(['enunciado' => 'Linguagem', 'tipo' => 'escolha_unica', 'opcoes' => [['texto' => 'Teatro', 'outro' => false]]]),
                ]],
                ['titulo' => 'Boa', 'descricao' => null, 'perguntas' => [self::perguntaDaResposta(['enunciado' => 'Nome do projeto'])]],
            ],
            'atencao' => [],
        ]));

        $this->assertSame(['Boa'], array_column($montado['questionario']['secoes'], 'titulo'));
        $this->assertSame(['Nome do projeto'], array_column(self::perguntas($montado), 'enunciado'));
        $this->assertSame([
            'A pergunta "Anexe o portfólio" tem tipo desconhecido ("arquivo") e foi descartada.',
            'A pergunta "Linguagem" é de escolha e tem menos de duas opções; foi descartada.',
            'A seção "Só ruins" ficou sem perguntas e foi descartada.',
        ], self::avisos($montado));
    }

    public function test_so_a_primeira_opcao_outro_fica(): void
    {
        $montado = $this->montar($this->comPerguntas([[
            'enunciado' => 'Linguagem',
            'tipo' => 'multipla_escolha',
            'opcoes' => [['texto' => 'Teatro', 'outro' => false], ['texto' => 'Outra', 'outro' => true], ['texto' => 'Outro (especificar)', 'outro' => true]],
        ]]));

        $this->assertSame(['Teatro', 'Outra'], array_column(self::perguntas($montado)[0]['opcoes'], 'texto'));
        $this->assertSame(['A pergunta "Linguagem" tinha mais de uma opção "Outro"; só a primeira ficou.'], self::avisos($montado));
    }

    public function test_textos_cortados_em_bytes_e_limite_invalido_anulado(): void
    {
        $montado = $this->montar($this->respostaDoQuestionario([
            'secoes' => [['titulo' => str_repeat('ç', 300), 'descricao' => str_repeat('é', 1500), 'perguntas' => [
                self::perguntaDaResposta(['enunciado' => str_repeat('ã', 1500), 'ajuda' => str_repeat('õ', 1500), 'tipo' => 'texto_longo', 'limite_caracteres' => 50000]),
                self::perguntaDaResposta([
                    'enunciado' => 'Linguagem',
                    'tipo' => 'escolha_unica',
                    'opcoes' => [['texto' => str_repeat('ê', 400), 'outro' => false], ['texto' => 'Dança', 'outro' => false]],
                ]),
                self::perguntaDaResposta(['enunciado' => 'Nome', 'limite_caracteres' => 0]),
            ]]],
            'atencao' => [],
        ]));

        $secao = $montado['questionario']['secoes'][0];
        [$longa, $escolha, $curta] = $secao['perguntas'];

        $this->assertSame(str_repeat('ç', 127), $secao['titulo']);
        $this->assertSame(str_repeat('é', 1000), $secao['descricao']);
        $this->assertSame(str_repeat('ã', 1000), $longa['enunciado']);
        $this->assertSame(str_repeat('õ', 1000), $longa['ajuda']);
        $this->assertSame(str_repeat('ê', 250), $escolha['opcoes'][0]['texto']);
        $this->assertNull($longa['limite_caracteres']);
        $this->assertNull($curta['limite_caracteres']);
        $this->assertContains('O limite de caracteres da pergunta "Nome" é inválido ("0") e ficou vazio.', self::avisos($montado));
        $this->assertCount(2, self::avisos($montado));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function raizesInvalidas(): array
    {
        return [
            'sem seções' => [['secoes' => null]],
            'seções como objeto' => [['secoes' => ['titulo' => 'Proposta']]],
            'confiabilidade como texto' => [['confiabilidade' => 'alta']],
            'atenção como objeto' => [['atencao' => ['texto' => 'x']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $alteracoes
     */
    #[DataProvider('raizesInvalidas')]
    public function test_raiz_fora_do_formato_lanca_resposta_invalida(array $alteracoes): void
    {
        $this->expectException(RespostaInvalida::class);

        QuestionarioDaResposta::conferirRaiz($this->respostaDoQuestionario($alteracoes));
    }

    public function test_contexto_lista_categorias_e_naturezas(): void
    {
        $this->assertSame(
            "Categorias do edital (use exatamente estes nomes) e as naturezas que cada uma aceita:\n"
            ."- Teatro: Pessoa Física, MEI\n"
            .'- Dança: Pessoa Física',
            PromptDoQuestionario::contexto($this->edital->fresh()),
        );

        $semNaturezas = Edital::factory()->create(['naturezas' => null]);
        EditalCategoria::factory()->create(['edital_id' => $semNaturezas->id, 'nome' => 'Circo', 'naturezas' => null]);

        $this->assertStringEndsWith('- Circo: todas', PromptDoQuestionario::contexto($semNaturezas->fresh()));
    }
}
