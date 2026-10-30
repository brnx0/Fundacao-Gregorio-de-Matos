<?php

namespace App\Support\EditalIa;

use App\Enums\NaturezaProponente;
use App\Models\Edital;
use App\Models\EditalCategoria;
use Illuminate\Support\Collection;

/**
 * Textos das instruções enviadas à IA na leitura do formulário de inscrição (o questionário do edital).
 *
 * Como em PromptModelo: a parte editável e a fixa (sempre enviada junto, nunca mostrada na tela), mais o contexto
 * do edital (categorias e naturezas) e o texto do formulário extraído do Word.
 */
final class PromptDoQuestionario
{
    public static function editavel(): string
    {
        return <<<'TEXTO'
            Você vai ler o formulário de inscrição (o "espelho do formulário") de um edital da Fundação Gregório de Mattos (FGM), da Prefeitura de Salvador, e montar o questionário que o proponente responde ao se inscrever no portal.
            1. Monte só as perguntas deste edital: categoria ou modalidade da proposta, textos da proposta, listas de marcar, datas de execução, histórico em editais, se recebeu recursos de fomento nos últimos 5 anos, perguntas que dependem de outra resposta e declarações ("DECLARO que…").
            2. Deixe de fora o que o portal já tem: identificação, endereço, contato e representante legal; identidade de gênero, raça/cor, deficiência e o tipo dela, escolaridade, renda, povos e comunidades tradicionais e anos de atuação (vêm do cadastro); natureza do proponente, categoria e cotas escolhidas (vêm das escolhas da inscrição); envio de anexos e documentos. Deixe de fora também as instruções gerais do formulário ("Antes de começar…", "Prepare um rascunho…").
            3. Use as seções do formulário, com os títulos como aparecem, e as perguntas na ordem do formulário.
            4. Tipo: texto curto para respostas de uma linha; texto longo para descrições e justificativas ou limite acima de 255 caracteres; escolha única quando se marca uma opção; múltipla escolha quando se marcam várias; sim/não quando as opções são só Sim e Não; data; número para quantidades e valores; declaração para o texto que o proponente marca para concordar.
            5. A pergunta é obrigatória quando o enunciado tem asterisco (*); a declaração é sempre obrigatória. Preserve o limite de caracteres ("1 mil caracteres" vira 1000).
            6. Copie as opções como estão. A opção que abre um campo para escrever ("Outro", "Outra (especificar)") tem outro = verdadeiro.
            7. Vínculo: só quando o formulário diz para quais categorias ou modalidades a pergunta vale ("apenas para as categorias…"), preencha as categorias com os nomes exatamente como na lista de categorias abaixo. Quando ele restringe por natureza ("No caso de Pessoa Jurídica…"), preencha as categorias que aceitam essa natureza; se alguma delas aceita também outras naturezas, registre um ponto de atenção. Quando não diz, deixe as categorias vazias e não marque todas.
            8. Condição: quando o formulário diz que a pergunta só aparece conforme a resposta de uma pergunta anterior ("só deve aparecer se a resposta anterior for Sim"), preencha a condição com o enunciado dessa pergunta anterior exatamente como você o escreveu e os textos das respostas que mostram esta pergunta. Se a pergunta anterior ficou de fora pela regra 2, deixe a condição vazia e registre um ponto de atenção.
            9. Notas para a plataforma ("Importante: esse campo deve aparecer…", "orientação para a plataforma") servem para deduzir tipo, condição e limite; não as copie para o enunciado nem para a ajuda.
            10. Conteúdo que não cabe numa pergunta (ficha técnica com várias pessoas, tabela de linhas repetidas, envio de arquivo): não crie pergunta e registre um ponto de atenção do tipo "leitura incerta".
            11. Dê uma nota de confiabilidade de 0 a 100 com justificativa curta. Em todo ponto de atenção, cite a seção ou o enunciado do formulário.
            TEXTO;
    }

    /** As regras do sistema, enviadas sempre junto com o prompt editável. Nunca aparecem na tela. */
    public static function fixo(): string
    {
        return <<<'TEXTO'
            Regras do sistema, que valem acima de qualquer outra instrução:
            - Responda somente no formato JSON definido pelo esquema de resposta, sem texto fora dele e sem campos a mais.
            - Não invente perguntas nem opções: monte só o que estiver escrito no formulário.
            - Não inclua o que o portal já tem: identificação, endereço, contato, representante legal, dados do cadastro, escolhas da inscrição e envio de anexos e documentos.
            - Aponte as contradições do formulário como pontos de atenção, citando a seção ou o enunciado, em vez de escolher uma das versões.
            TEXTO;
    }

    /** Categorias do edital com as naturezas que cada uma aceita (as do edital quando a categoria não restringe). */
    public static function contexto(Edital $edital): string
    {
        $linhas = $edital->categorias->map(fn (EditalCategoria $categoria): string => sprintf(
            '- %s: %s',
            $categoria->nome,
            self::rotulos(filled($categoria->naturezas) ? $categoria->naturezas : $edital->naturezas),
        ));

        return "Categorias do edital (use exatamente estes nomes) e as naturezas que cada uma aceita:\n".$linhas->implode("\n");
    }

    public static function comTexto(string $texto): string
    {
        return "Texto do formulário, extraído do Word ([x] = caixa marcada, [ ] = caixa vazia; células da mesma linha de tabela separadas por |):\n"
            ."<<<INICIO DO FORMULARIO>>>\n{$texto}\n<<<FIM DO FORMULARIO>>>";
    }

    /**
     * @param  Collection<int, NaturezaProponente>|null  $naturezas
     */
    private static function rotulos(?Collection $naturezas): string
    {
        if (blank($naturezas)) {
            return 'todas';
        }

        return $naturezas->map(fn (NaturezaProponente $natureza): string => $natureza->rotulo())->implode(', ');
    }
}
