<?php

namespace App\Support\DocumentoIa;

use App\Enums\AnexoCadastro;
use App\Support\FalhaNaIa;

/**
 * A IA escreve o que conferir em cada anexo do cadastro (Parametrização > Anexos do cadastro): o roteiro que ela mesma
 * vai seguir ao conferir o arquivo que a pessoa anexar. O cadastro vale para todos os editais, então não há contexto
 * de edital: um pedido só (são no máximo sete anexos), pelo GeradorDeInstrucoes, com o rótulo, a descrição e os dados
 * do cadastro que vão junto com cada arquivo (AnexoCadastro::dadosConferidos).
 */
class GeradorDeInstrucoesDosAnexos
{
    private const string PROMPT = <<<'TEXTO'
        Você vai escrever instruções para outra IA, que confere os arquivos (PDF ou imagem JPG/PNG) que as pessoas anexam ao cadastro de pessoa física no portal de editais de cultura da Fundação Gregório de Mattos (FGM), da Prefeitura de Salvador. O cadastro vale para todos os editais: não há edital nesta etapa.

        Para cada documento da lista abaixo, escreva a instrução que essa IA vai seguir ao conferir o arquivo enviado para ele. Junto com o arquivo, ela recebe só os dados do cadastro indicados no documento. A instrução diz o que verificar no arquivo:
        - o tipo de documento: se o arquivo é mesmo o documento pedido;
        - nos documentos de identificação, a titularidade: se os dados do documento conferem com os dados do cadastro indicados;
        - a legibilidade: se dá para ler o que precisa ser conferido;
        - a validade e as datas, quando o documento tiver;
        - na foto de perfil: se é a foto de uma pessoa, com o rosto visível e sem conteúdo impróprio;
        - no comprovante de residência: se o endereço confere com o do cadastro.

        Regras:
        - Escreva em português, de forma objetiva, com no máximo 600 caracteres por instrução.
        - Não invente exigência que o nome e a descrição do documento não indiquem.
        - Responda com uma instrução por documento, na mesma ordem da lista, no campo "instrucoes".
        TEXTO;

    public function __construct(private GeradorDeInstrucoes $gerador) {}

    /**
     * @param  list<AnexoCadastro>  $anexos
     * @return array<string, string> A instrução de cada anexo, pelo valor do campo, na ordem recebida.
     *
     * @throws FalhaNaIa sem provedores configurados ou quando nenhum responde no formato esperado
     */
    public function gerar(array $anexos): array
    {
        $instrucoes = $this->gerador->pedir(self::PROMPT."\n\n".self::listaDosAnexos($anexos), count($anexos));

        return array_combine(array_map(fn (AnexoCadastro $anexo): string => $anexo->value, $anexos), $instrucoes);
    }

    /**
     * Os anexos numerados, com a descrição, os dados do cadastro que a IA recebe e se são obrigatórios.
     *
     * @param  list<AnexoCadastro>  $anexos
     */
    private static function listaDosAnexos(array $anexos): string
    {
        $linhas = ['Documentos ('.count($anexos).')'];

        foreach ($anexos as $posicao => $anexo) {
            $dados = $anexo->dadosConferidos();

            $linhas[] = ($posicao + 1).'. '.$anexo->rotulo();
            $linhas[] = '   Descrição: '.$anexo->descricao();
            $linhas[] = '   Dados do cadastro que a IA recebe: '.($dados === [] ? 'nenhum' : implode(', ', $dados));
            $linhas[] = $anexo->obrigatorio() ? '   Obrigatório no cadastro.' : '   Opcional no cadastro.';
        }

        return implode("\n", $linhas);
    }
}
