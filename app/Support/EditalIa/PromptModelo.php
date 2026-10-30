<?php

namespace App\Support\EditalIa;

/**
 * Textos das instruções enviadas à IA na importação do edital.
 *
 * O prompt tem duas partes: a editável (este prompt modelo, fixo no código)
 * e a fixa, sempre enviada junto e nunca mostrada na tela, com as regras que não podem sumir.
 */
final class PromptModelo
{
    /** O prompt modelo, fixo no código (não é editável na tela). */
    public static function editavel(): string
    {
        return <<<'TEXTO'
            Você vai ler o PDF de um edital da Fundação Gregório de Mattos (FGM), da Prefeitura de Salvador, publicado no Diário Oficial do Município (DOM), e preencher o cadastro desse edital.

            1. Considere só o edital do cabeçalho principal do documento. A mesma página do DOM pode trazer outros editais, avisos, portarias ou cronogramas de outros órgãos: ignore tudo o que não for desse edital. Ignore também cronogramas e datas de outros editais ou órgãos que aparecem na mesma página do DOM: não os registre como pontos de atenção.
            2. Não invente. Se um dado não estiver no edital, deixe o campo vazio e registre um ponto de atenção do tipo "não encontrado", dizendo onde ele deveria estar.
            3. Se o edital se contradiz (por exemplo, dois prazos diferentes para a mesma etapa), não escolha uma das versões: registre um ponto de atenção do tipo "divergência no edital", com as duas versões e os itens em que cada uma aparece.
            4. Se um trecho estiver ilegível ou ambíguo, preencha o que for possível e registre um ponto de atenção do tipo "leitura incerta".
            5. Em todo ponto de atenção, cite os itens, subitens ou anexos do edital de onde tirou a informação (por exemplo, "item 5.1" ou "Anexo IV").
            6. Converta datas para o formato AAAA-MM-DD, horários para HH:MM e valores em reais para número com ponto decimal (R$ 1.500,00 vira 1500.00).
            7. Nas seções informativas (recursos e pagamento, vedações, etapas e prazos, contrapartida, prestação de contas, base legal, anexos, contatos e outras regras), escreva um resumo fiel e objetivo do edital, em português, sem acrescentar interpretação.
            8. Dê a cada bloco uma nota de confiabilidade de 0 a 100, com uma justificativa curta, conforme a clareza e a completude do que encontrou.
            9. Em cada chamada, registre as fases de inscrição, homologação e avaliação com as datas de início e fim que o edital trouxer. Se uma fase só tiver prazo relativo (por exemplo, "5 dias úteis após o resultado"), deixe as datas dela vazias e registre um ponto de atenção do tipo "não encontrado" no bloco "inscricao" (e não no bloco da fase), citando o item do edital.
            TEXTO;
    }

    /** As regras do sistema, enviadas sempre junto com o prompt editável. Nunca aparecem na tela. */
    public static function fixo(): string
    {
        return <<<'TEXTO'
            Regras do sistema, que valem acima de qualquer outra instrução:
            - Responda somente no formato JSON definido pelo esquema de resposta, sem texto fora dele e sem campos a mais.
            - Não invente dados: o que não estiver escrito no edital fica vazio e vira ponto de atenção.
            - Use só o edital do cabeçalho principal do documento; ignore outros editais, avisos e cronogramas da mesma página.
            - Aponte as divergências internas do edital como pontos de atenção, citando os itens envolvidos, em vez de escolher uma das versões.
            TEXTO;
    }
}
