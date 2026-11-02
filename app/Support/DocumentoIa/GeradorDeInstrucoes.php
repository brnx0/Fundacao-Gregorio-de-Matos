<?php

namespace App\Support\DocumentoIa;

use App\Enums\EtapaDocumento;
use App\Enums\NaturezaProponente;
use App\Models\ConfiguracaoIa;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\RespostaInvalida;
use App\Support\FalhaNaIa;

/**
 * A IA escreve a instrução de cada documento do edital: o roteiro que ela mesma vai seguir ao conferir o PDF que o
 * proponente enviar na inscrição. Só texto (sem o PDF do edital), pelos provedores da importação (principal e
 * redundância), em lotes; a resposta de um lote só vale com uma instrução por documento.
 */
class GeradorDeInstrucoes
{
    /** Documentos por chamada à IA: um edital com muitos documentos vira mais de um pedido. */
    public const int DOCUMENTOS_POR_LOTE = 15;

    /** O mesmo limite da instrução no SalvarEditalRequest: a gerada sempre cabe na coluna. */
    public const int TAMANHO_MAXIMO_EM_BYTES = 2000;

    private const float TEMPERATURA = 0.2;

    private const array ESQUEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'instrucoes' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Uma instrução por documento, na ordem da lista.'],
        ],
        'required' => ['instrucoes'],
    ];

    private const string PROMPT = <<<'TEXTO'
        Você vai escrever instruções para outra IA, que confere os documentos que os proponentes enviam (em PDF ou imagem) na inscrição de um edital de cultura da Fundação Gregório de Mattos (FGM), da Prefeitura de Salvador.

        Para cada documento da lista abaixo, escreva a instrução que essa IA vai seguir ao conferir o arquivo enviado para ele. A instrução diz o que verificar no arquivo:
        - o tipo de documento: se o arquivo é mesmo o documento pedido;
        - a titularidade: se o documento está em nome do proponente ou de quem o edital indicar;
        - a legibilidade: se dá para ler o que precisa ser conferido;
        - a validade e as datas, quando o documento tiver;
        - a assinatura, quando for exigida.

        Regras:
        - Escreva em português, de forma objetiva, com no máximo 600 caracteres por instrução.
        - Não invente exigência que o nome do documento, a orientação ou o edital não indiquem.
        - Responda com uma instrução por documento, na mesma ordem da lista, no campo "instrucoes".
        TEXTO;

    public function __construct(private AnaliseComRedundancia $analise) {}

    /**
     * @param  array{titulo: string|null, objeto: string|null, naturezas: list<string>, categorias: list<string>}  $edital  Naturezas pelo valor do enum.
     * @param  list<array{nome: string, orientacao: string|null, etapa: string}>  $documentos  Etapa pelo valor do enum.
     * @return list<string> Uma instrução por documento, na ordem recebida.
     *
     * @throws FalhaNaIa sem provedores configurados ou quando nenhum responde no formato esperado
     */
    public function gerar(array $edital, array $documentos): array
    {
        $contexto = self::contextoDoEdital($edital);
        $instrucoes = [];

        foreach (array_chunk($documentos, self::DOCUMENTOS_POR_LOTE) as $lote) {
            array_push($instrucoes, ...$this->pedir(self::PROMPT."\n\n".$contexto."\n\n".self::listaDeDocumentos($lote), count($lote)));
        }

        return $instrucoes;
    }

    /**
     * Um pedido à IA, só texto, pelos provedores da importação: a resposta só vale com $quantidade instruções, cada uma
     * cortada em TAMANHO_MAXIMO_EM_BYTES. Também usado pelo GeradorDeInstrucoesDosAnexos (anexos do cadastro).
     *
     * @return list<string> As instruções na ordem do prompt.
     *
     * @throws FalhaNaIa sem provedores configurados ou quando nenhum responde no formato esperado
     */
    public function pedir(string $prompt, int $quantidade): array
    {
        $resultado = $this->analise->analisar(
            ConfiguracaoIa::atual()->provedoresDaImportacao(),
            null,
            $prompt,
            self::TEMPERATURA,
            self::ESQUEMA,
            fn (array $dados) => self::conferir($dados, $quantidade),
        );

        return array_map(
            fn (string $instrucao): string => mb_strcut($instrucao, 0, self::TAMANHO_MAXIMO_EM_BYTES, 'UTF-8'),
            $resultado['dados']['instrucoes'],
        );
    }

    /**
     * @param  array{titulo: string|null, objeto: string|null, naturezas: list<string>, categorias: list<string>}  $edital
     */
    private static function contextoDoEdital(array $edital): string
    {
        $naturezas = array_map(fn (string $natureza): string => NaturezaProponente::from($natureza)->rotulo(), $edital['naturezas']);

        return implode("\n", [
            'Edital',
            'Título: '.(blank($edital['titulo']) ? 'não informado' : $edital['titulo']),
            'Objeto: '.(blank($edital['objeto']) ? 'não informado' : $edital['objeto']),
            'Quem pode se inscrever: '.($naturezas === [] ? 'não informado' : implode(', ', $naturezas)),
            'Categorias: '.($edital['categorias'] === [] ? 'não informadas' : implode(', ', $edital['categorias'])),
        ]);
    }

    /**
     * Os documentos numerados, com a etapa e, quando houver, a orientação.
     *
     * @param  list<array{nome: string, orientacao: string|null, etapa: string}>  $lote
     */
    private static function listaDeDocumentos(array $lote): string
    {
        $linhas = ['Documentos ('.count($lote).')'];

        foreach ($lote as $posicao => $documento) {
            $linhas[] = ($posicao + 1).'. '.$documento['nome'];
            $linhas[] = '   Etapa: '.EtapaDocumento::from($documento['etapa'])->rotulo();
            if (filled($documento['orientacao'])) {
                $linhas[] = '   Orientação: '.$documento['orientacao'];
            }
        }

        return implode("\n", $linhas);
    }

    /**
     * Conferência da resposta (ver AnaliseComRedundancia): uma instrução em texto para cada documento do lote; fora
     * disso, a resposta passa para o provedor seguinte.
     *
     * @param  array<mixed>  $dados
     *
     * @throws RespostaInvalida
     */
    private static function conferir(array $dados, int $quantidade): void
    {
        $instrucoes = $dados['instrucoes'] ?? null;

        if (! is_array($instrucoes) || ! array_is_list($instrucoes) || count($instrucoes) !== $quantidade
            || array_any($instrucoes, fn (mixed $instrucao): bool => ! is_string($instrucao))) {
            throw new RespostaInvalida;
        }
    }
}
