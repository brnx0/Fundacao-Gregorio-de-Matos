<?php

namespace App\Support\EditalIa;

use App\Models\ConfiguracaoIa;
use App\Support\FalhaNaIa;

/**
 * A IA ajusta (ou escreve, sem texto) a descrição do card do edital: o resumo que aparece nos cards do portal e no topo
 * da página do edital. Só texto, pelos provedores da importação (principal e redundância); a resposta só vale com a
 * descrição preenchida.
 */
class RedatorDaDescricao
{
    /** O card mostra só as primeiras linhas; a IA mira neste tamanho. */
    public const int TAMANHO_IDEAL = 300;

    /** Corte de segurança, caso a IA passe muito do pedido. */
    public const int TAMANHO_MAXIMO = 600;

    private const float TEMPERATURA = 0.4;

    private const array ESQUEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'descricao' => ['type' => 'STRING', 'description' => 'A descrição do card, pronta para publicar.'],
        ],
        'required' => ['descricao'],
    ];

    private const string PROMPT = <<<'TEXTO'
        Você escreve a descrição do card de um edital de cultura da Fundação Gregório de Mattos (FGM), da Prefeitura de Salvador, no portal de editais. Ela aparece embaixo do título, nos cards da lista de editais e da página inicial, e inteira no topo da página do edital.

        Ajuste o texto atual da descrição (ou escreva um, se não houver) a partir dos dados do edital abaixo.

        Regras:
        - Escreva em português, em 2 ou 3 frases e no máximo %d caracteres.
        - Comece pelo que o edital oferece ou seleciona e diga a quem se destina, numa linguagem clara, direta e convidativa para artistas e agentes culturais.
        - Não repita o título nem o número do edital, e não use listas, emojis ou aspas.
        - Não invente valores, datas, prazos, vagas ou exigências que o texto atual e os dados não tragam.
        - Responda só com a descrição, no campo "descricao".
        TEXTO;

    public function __construct(private AnaliseComRedundancia $analise) {}

    /**
     * @param  array{titulo: string|null, objeto: string|null, categoria: string|null, texto: string|null}  $edital
     *
     * @throws FalhaNaIa sem provedores configurados ou quando nenhum responde no formato esperado
     */
    public function ajustar(array $edital): string
    {
        $resultado = $this->analise->analisar(
            ConfiguracaoIa::atual()->provedoresDaImportacao(),
            null,
            sprintf(self::PROMPT, self::TAMANHO_IDEAL)."\n\n".self::contexto($edital),
            self::TEMPERATURA,
            self::ESQUEMA,
            self::conferir(...),
        );

        return mb_substr(trim($resultado['dados']['descricao']), 0, self::TAMANHO_MAXIMO);
    }

    /**
     * @param  array{titulo: string|null, objeto: string|null, categoria: string|null, texto: string|null}  $edital
     */
    private static function contexto(array $edital): string
    {
        $ou = fn (?string $valor, string $vazio): string => blank($valor) ? $vazio : trim($valor);

        return implode("\n", [
            'Edital',
            'Título: '.$ou($edital['titulo'], 'não informado'),
            'Objeto: '.$ou($edital['objeto'], 'não informado'),
            'Categoria da vitrine: '.$ou($edital['categoria'], 'não informada'),
            '',
            'Texto atual da descrição:',
            $ou($edital['texto'], '(vazio)'),
        ]);
    }

    /**
     * Conferência da resposta (ver AnaliseComRedundancia): a descrição em texto, não vazia; fora disso, a resposta passa
     * para o provedor seguinte.
     *
     * @param  array<mixed>  $dados
     *
     * @throws RespostaInvalida
     */
    private static function conferir(array $dados): void
    {
        if (! is_string($dados['descricao'] ?? null) || trim($dados['descricao']) === '') {
            throw new RespostaInvalida;
        }
    }
}
