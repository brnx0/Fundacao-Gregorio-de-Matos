<?php

namespace Tests\Support;

/** Resposta da IA no formato de EsquemaDoQuestionario, com todos os blocos preenchidos. */
trait RespostaDoQuestionario
{
    /**
     * Duas seções: texto longo com limite, escolha única com "Outro", sim/não, pergunta condicionada ao "Sim",
     * múltipla escolha vinculada a "Teatro" e declaração.
     *
     * @param  array<string, mixed>  $alteracoes  Blocos da raiz a trocar.
     * @return array<string, mixed>
     */
    protected function respostaDoQuestionario(array $alteracoes = []): array
    {
        return [
            'secoes' => [
                [
                    'titulo' => 'Sobre a proposta',
                    'descricao' => 'Conte sobre o projeto.',
                    'perguntas' => [
                        self::perguntaDaResposta([
                            'enunciado' => 'Descreva a proposta',
                            'ajuda' => 'Objetivos e público.',
                            'tipo' => 'texto_longo',
                            'obrigatoria' => true,
                            'limite_caracteres' => 1000,
                        ]),
                        self::perguntaDaResposta([
                            'enunciado' => 'Linguagem principal',
                            'tipo' => 'escolha_unica',
                            'obrigatoria' => true,
                            'opcoes' => [
                                ['texto' => 'Teatro', 'outro' => false],
                                ['texto' => 'Dança', 'outro' => false],
                                ['texto' => 'Outra (especificar)', 'outro' => true],
                            ],
                        ]),
                        self::perguntaDaResposta([
                            'enunciado' => 'Recebeu recursos de fomento nos últimos 5 anos?',
                            'tipo' => 'sim_nao',
                            'obrigatoria' => true,
                        ]),
                        self::perguntaDaResposta([
                            'enunciado' => 'Quais editais?',
                            'tipo' => 'texto_curto',
                            'condicao' => ['pergunta' => 'Recebeu recursos de fomento nos últimos 5 anos?', 'respostas' => ['Sim']],
                        ]),
                    ],
                ],
                [
                    'titulo' => 'Declarações',
                    'descricao' => null,
                    'perguntas' => [
                        self::perguntaDaResposta([
                            'enunciado' => 'Espaços onde pretende se apresentar',
                            'tipo' => 'multipla_escolha',
                            'opcoes' => [
                                ['texto' => 'Teatro Gregório de Mattos', 'outro' => false],
                                ['texto' => 'Espaço Xisto Bahia', 'outro' => false],
                            ],
                            'categorias' => ['Teatro'],
                        ]),
                        self::perguntaDaResposta([
                            'enunciado' => 'DECLARO que as informações são verdadeiras.',
                            'tipo' => 'declaracao',
                            'obrigatoria' => false,
                        ]),
                    ],
                ],
            ],
            'confiabilidade' => ['nota' => 85, 'justificativa' => 'Formulário claro.'],
            'atencao' => [
                ['tipo' => 'incerta', 'texto' => 'A ficha técnica não virou pergunta.', 'itens' => ['Ficha técnica']],
            ],
            ...$alteracoes,
        ];
    }

    /**
     * Pergunta da resposta com os campos vazios preenchidos.
     *
     * @param  array<string, mixed>  $campos
     * @return array<string, mixed>
     */
    protected static function perguntaDaResposta(array $campos): array
    {
        return [
            'enunciado' => 'Pergunta',
            'ajuda' => null,
            'tipo' => 'texto_curto',
            'obrigatoria' => false,
            'limite_caracteres' => null,
            'opcoes' => [],
            'categorias' => [],
            'condicao' => null,
            ...$campos,
        ];
    }
}
