<?php

namespace App\Support\EditalIa;

use App\Enums\TipoPergunta;

/**
 * Formato da resposta pedido à IA na leitura do formulário de inscrição (o questionário do edital), no formato do
 * Gemini e traduzível por EsquemaOpenAi. Categorias e a pergunta da condição vão pelo nome e pelo enunciado;
 * as respostas da condição, pelo texto das opções.
 */
final class EsquemaDoQuestionario
{
    use MontaEsquema;

    /**
     * @return array<string, mixed>
     */
    public static function esquema(): array
    {
        $pergunta = self::objeto([
            'enunciado' => self::texto(null, false),
            'ajuda' => self::texto('Orientação curta que o formulário dá ao proponente sobre a pergunta.'),
            'tipo' => self::enumDe(
                array_map(fn (TipoPergunta $tipo): string => $tipo->value, TipoPergunta::cases()),
                'Tipo da resposta.',
                false,
            ),
            'obrigatoria' => self::booleano('Verdadeiro quando o enunciado tem asterisco (*); a declaração é sempre obrigatória.'),
            'limite_caracteres' => self::inteiro('Limite de caracteres da resposta, ex.: "1 mil caracteres" = 1000.'),
            'opcoes' => self::lista(self::objeto([
                'texto' => self::texto(null, false),
                'outro' => self::booleano('Verdadeiro na opção que abre um campo para escrever ("Outro", "Outra (especificar)").'),
            ], ['texto']), 'Opções da escolha única ou da múltipla escolha, como estão no formulário.'),
            'categorias' => self::lista(
                self::texto(null, false),
                'Nomes das categorias, exatamente como na lista de categorias enviada, só quando o formulário diz para quais a pergunta vale; vazia quando não diz.',
            ),
            'condicao' => self::campo(self::objeto([
                'pergunta' => self::texto('Enunciado da pergunta anterior, exatamente como você o escreveu.', false),
                'respostas' => self::lista(
                    self::texto(null, false),
                    'Textos das opções da pergunta anterior que mostram esta pergunta; "Sim" ou "Não" na pergunta de sim/não.',
                ),
            ], ['pergunta', 'respostas']), 'Só quando a pergunta aparece conforme a resposta de uma pergunta anterior.', true),
        ], ['enunciado', 'tipo']);

        return self::objeto([
            'secoes' => self::lista(self::objeto([
                'titulo' => self::texto('Título da seção, como aparece no formulário.', false),
                'descricao' => self::texto(),
                'perguntas' => self::lista($pergunta, 'Perguntas na ordem do formulário.'),
            ], ['titulo', 'perguntas'])),
            'confiabilidade' => self::objeto([
                'nota' => self::inteiro('De 0 a 100.', false),
                'justificativa' => self::texto(null, false),
            ], ['nota', 'justificativa']),
            'atencao' => self::lista(self::objeto([
                'tipo' => self::enumDe(
                    array_keys(EsquemaDaResposta::TIPOS_ATENCAO),
                    'nao_encontrado, divergencia (o formulário se contradiz) ou incerta (leitura incerta).',
                    false,
                ),
                'texto' => self::texto(null, false),
                'itens' => self::lista(self::texto(null, false), 'Seções ou enunciados do formulário citados.'),
            ], ['tipo', 'texto', 'itens'])),
        ], ['secoes', 'confiabilidade', 'atencao']);
    }
}
