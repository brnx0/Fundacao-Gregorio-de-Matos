<?php

namespace App\Support\EditalIa;

use App\Enums\TipoPergunta;
use App\Http\Requests\Retaguarda\RegrasDoQuestionario;
use App\Http\Requests\Retaguarda\SalvarEditalRequest;
use App\Models\Edital;
use App\Models\EditalCategoria;
use Illuminate\Support\Str;

/**
 * Transforma a resposta da IA (no formato de EsquemaDoQuestionario) no questionário do edital, no formato de
 * SincronizadorDoQuestionario / RegrasDoQuestionario, sem gravar nada.
 *
 * - Raiz fora do formato: RespostaInvalida.
 * - Pergunta sem enunciado, de tipo desconhecido ou de escolha com menos de duas opções: descartada; seção sem
 *   pergunta aceita: descartada. Limites de seções, perguntas e opções: o que passa sai.
 * - Categorias pelo nome (como EditalDaResposta::chaveDoNome), viram posições em `$edital->categorias`; algum nome
 *   sem par deixa a pergunta sem vínculo. Condição pelo enunciado de uma pergunta anterior já aceita que pode ser
 *   origem e pelos textos das opções dela.
 * - Textos cortados nos limites das colunas, em bytes (VARCHAR2 do Oracle).
 *
 * Cada descarte ou ajuste vira ponto de atenção "incerta" no bloco do questionário, depois dos pontos da IA.
 */
class QuestionarioDaResposta
{
    public const string BLOCO = 'questionario';

    private const int MAXIMO_OPCOES = 50;

    private const int BYTES_TITULO = 255;

    private const int BYTES_TEXTO = 2000;

    private const int BYTES_OPCAO = 500;

    private const int LIMITE_MAXIMO = 20000;

    /** @var list<array{bloco: string, tipo: string, texto: string, itens: list<string>}> */
    private array $avisos = [];

    /**
     * Perguntas já aceitas, por posição global, para resolver as condições.
     *
     * @var list<array{chave: string, tipo: TipoPergunta, respostas: array<string, string>}>
     */
    private array $aceitas = [];

    /**
     * @param  array<mixed>  $dados
     *
     * @throws RespostaInvalida
     */
    public static function conferirRaiz(array $dados): void
    {
        if (! isset($dados['secoes']) || ! is_array($dados['secoes']) || ! array_is_list($dados['secoes'])) {
            throw new RespostaInvalida;
        }
        if (! isset($dados['confiabilidade']) || ! is_array($dados['confiabilidade'])) {
            throw new RespostaInvalida;
        }
        if (! isset($dados['atencao']) || ! is_array($dados['atencao']) || ! array_is_list($dados['atencao'])) {
            throw new RespostaInvalida;
        }
    }

    /**
     * @param  array<mixed>  $dados  A resposta da IA, já decodificada.
     * @return array{questionario: array{secoes: list<array<string, mixed>>}, confiabilidade: array{geral: ?int, blocos: array{questionario?: array{nota: int, justificativa: string}}}, atencao: list<array{bloco: string, tipo: string, texto: string, itens: list<string>}>}
     *
     * @throws RespostaInvalida
     */
    public function montar(array $dados, Edital $edital): array
    {
        self::conferirRaiz($dados);
        $this->avisos = [];
        $this->aceitas = [];

        $secoes = $this->secoes($dados['secoes'], $this->posicoesDasCategorias($edital));

        return [
            'questionario' => ['secoes' => $secoes],
            'confiabilidade' => $this->confiabilidade($dados['confiabilidade']),
            'atencao' => [...$this->atencaoDaIa($dados['atencao']), ...$this->avisos],
        ];
    }

    /**
     * @param  list<mixed>  $recebidas
     * @param  array<string, int>  $posicaoDaCategoria
     * @return list<array<string, mixed>>
     */
    private function secoes(array $recebidas, array $posicaoDaCategoria): array
    {
        $secoes = [];

        foreach ($recebidas as $indice => $recebida) {
            if (count($secoes) >= SalvarEditalRequest::MAXIMO_ITENS) {
                $this->avisar(sprintf('Só as %d primeiras seções foram importadas.', SalvarEditalRequest::MAXIMO_ITENS));

                break;
            }
            if (count($this->aceitas) >= RegrasDoQuestionario::MAXIMO_PERGUNTAS) {
                $this->avisar(sprintf('Só as %d primeiras perguntas foram importadas.', RegrasDoQuestionario::MAXIMO_PERGUNTAS));

                break;
            }
            if (! is_array($recebida)) {
                $this->avisar('Uma seção veio fora do formato e foi descartada.');

                continue;
            }

            $titulo = self::texto($recebida['titulo'] ?? null, self::BYTES_TITULO);
            if ($titulo === null) {
                $titulo = 'Seção '.($indice + 1);
                $this->avisar("Uma seção veio sem título; ficou como \"{$titulo}\".");
            }

            $perguntas = $this->perguntas($recebida['perguntas'] ?? null, $titulo, $posicaoDaCategoria);
            if ($perguntas === []) {
                $this->avisar("A seção \"{$titulo}\" ficou sem perguntas e foi descartada.");

                continue;
            }

            $secoes[] = [
                'titulo' => $titulo,
                'descricao' => self::texto($recebida['descricao'] ?? null, self::BYTES_TEXTO),
                'perguntas' => $perguntas,
            ];
        }

        return $secoes;
    }

    /**
     * @param  array<string, int>  $posicaoDaCategoria
     * @return list<array<string, mixed>>
     */
    private function perguntas(mixed $recebidas, string $tituloDaSecao, array $posicaoDaCategoria): array
    {
        $perguntas = [];

        foreach (is_array($recebidas) ? array_values($recebidas) : [] as $recebida) {
            if (count($perguntas) >= SalvarEditalRequest::MAXIMO_ITENS) {
                $this->avisar(sprintf('Só as %d primeiras perguntas da seção "%s" foram importadas.', SalvarEditalRequest::MAXIMO_ITENS, $tituloDaSecao));

                break;
            }
            if (count($this->aceitas) >= RegrasDoQuestionario::MAXIMO_PERGUNTAS) {
                break;
            }

            $pergunta = is_array($recebida) ? $this->pergunta($recebida, $tituloDaSecao, $posicaoDaCategoria) : null;
            if ($pergunta !== null) {
                $perguntas[] = $pergunta;
            }
        }

        return $perguntas;
    }

    /**
     * @param  array<mixed>  $recebida
     * @param  array<string, int>  $posicaoDaCategoria
     * @return array<string, mixed>|null Nula quando a pergunta é descartada.
     */
    private function pergunta(array $recebida, string $tituloDaSecao, array $posicaoDaCategoria): ?array
    {
        $enunciado = self::texto($recebida['enunciado'] ?? null, self::BYTES_TEXTO);
        if ($enunciado === null) {
            $this->avisar("Uma pergunta da seção \"{$tituloDaSecao}\" veio sem enunciado e foi descartada.");

            return null;
        }

        $citado = self::resumo($enunciado);
        $tipo = is_string($recebida['tipo'] ?? null) ? TipoPergunta::tryFrom($recebida['tipo']) : null;
        if ($tipo === null) {
            $this->avisar("A pergunta \"{$citado}\" tem tipo desconhecido (\"".self::descrever($recebida['tipo'] ?? null).'") e foi descartada.');

            return null;
        }

        $opcoes = null;
        if ($tipo->temOpcoes()) {
            $opcoes = $this->opcoes($recebida['opcoes'] ?? null, $citado);
            if (count($opcoes) < 2) {
                $this->avisar("A pergunta \"{$citado}\" é de escolha e tem menos de duas opções; foi descartada.");

                return null;
            }
        }

        $pergunta = [
            'enunciado' => $enunciado,
            'ajuda' => self::texto($recebida['ajuda'] ?? null, self::BYTES_TEXTO),
            'tipo' => $tipo->value,
            'obrigatoria' => $tipo === TipoPergunta::Declaracao || ($recebida['obrigatoria'] ?? null) === true,
            'limite_caracteres' => $tipo->temLimite() ? $this->limite($recebida['limite_caracteres'] ?? null, $citado) : null,
            'opcoes' => $opcoes,
            'todas_categorias' => false,
            'categorias' => $this->categorias($recebida['categorias'] ?? null, $posicaoDaCategoria, $citado),
            ...$this->condicao($recebida['condicao'] ?? null, $citado),
        ];

        $this->aceitas[] = [
            'chave' => EditalDaResposta::chaveDoNome($enunciado),
            'tipo' => $tipo,
            'respostas' => $this->respostasPorTexto($tipo, $opcoes ?? []),
        ];

        return $pergunta;
    }

    /**
     * Opções com texto, até o máximo; só a primeira "Outro" fica. Chaves sorteadas.
     *
     * @return list<array{chave: string, texto: string, outro: bool}>
     */
    private function opcoes(mixed $recebidas, string $citado): array
    {
        $opcoes = [];
        $temOutro = false;
        $outrosDescartados = false;

        foreach (is_array($recebidas) ? array_values($recebidas) : [] as $recebida) {
            $texto = is_array($recebida) ? self::texto($recebida['texto'] ?? null, self::BYTES_OPCAO) : null;
            if ($texto === null) {
                $this->avisar("Uma opção da pergunta \"{$citado}\" veio sem texto e foi descartada.");

                continue;
            }

            $outro = ($recebida['outro'] ?? null) === true;
            if ($outro && $temOutro) {
                $outrosDescartados = true;

                continue;
            }
            $temOutro = $temOutro || $outro;

            if (count($opcoes) >= self::MAXIMO_OPCOES) {
                $this->avisar(sprintf('Só as %d primeiras opções da pergunta "%s" foram importadas.', self::MAXIMO_OPCOES, $citado));

                break;
            }

            $opcoes[] = ['chave' => Str::lower(Str::random(12)), 'texto' => $texto, 'outro' => $outro];
        }

        if ($outrosDescartados) {
            $this->avisar("A pergunta \"{$citado}\" tinha mais de uma opção \"Outro\"; só a primeira ficou.");
        }

        return $opcoes;
    }

    private function limite(mixed $valor, string $citado): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_numeric($valor) || floor((float) $valor) != $valor || $valor < 1 || $valor > self::LIMITE_MAXIMO) {
            $this->avisar("O limite de caracteres da pergunta \"{$citado}\" é inválido (\"".self::descrever($valor).'") e ficou vazio.');

            return null;
        }

        return (int) $valor;
    }

    /**
     * Posições das categorias citadas; algum nome sem par (ou nenhum nome) = sem vínculo.
     *
     * @param  array<string, int>  $posicaoDaCategoria
     * @return list<int>
     */
    private function categorias(mixed $nomes, array $posicaoDaCategoria, string $citado): array
    {
        $posicoes = [];
        $semPar = false;

        foreach (self::nomes($nomes) as $nome) {
            $posicao = $posicaoDaCategoria[EditalDaResposta::chaveDoNome($nome)] ?? null;
            if ($posicao === null) {
                $this->avisar("A pergunta \"{$citado}\" cita a categoria \"".self::resumo($nome).'", que não está no edital; ficou sem vínculo.');
                $semPar = true;
            } elseif (! in_array($posicao, $posicoes, true)) {
                $posicoes[] = $posicao;
            }
        }

        return $semPar ? [] : $posicoes;
    }

    /**
     * Condição pela pergunta anterior (enunciado) e pelos textos das respostas; não resolvida = sem condição.
     *
     * @return array{condicao_pergunta: ?int, condicao_opcoes: ?list<string>}
     */
    private function condicao(mixed $recebida, string $citado): array
    {
        $semCondicao = ['condicao_pergunta' => null, 'condicao_opcoes' => null];
        if (! is_array($recebida)) {
            return $semCondicao;
        }

        $enunciado = self::texto($recebida['pergunta'] ?? null, self::BYTES_TEXTO);
        $origem = $enunciado !== null ? $this->origem(EditalDaResposta::chaveDoNome($enunciado)) : null;

        $chaves = [];
        if ($origem !== null) {
            foreach (self::nomes($recebida['respostas'] ?? null) as $resposta) {
                $chave = $this->aceitas[$origem]['respostas'][EditalDaResposta::chaveDoNome($resposta)] ?? null;
                if ($chave === null) {
                    $this->avisar("A condição da pergunta \"{$citado}\" cita a resposta \"".self::resumo($resposta).'", que não está entre as opções; foi ignorada.');
                } elseif (! in_array($chave, $chaves, true)) {
                    $chaves[] = $chave;
                }
            }
        }

        if ($origem === null || $chaves === []) {
            $this->avisar("A condição da pergunta \"{$citado}\" não foi reconhecida; a pergunta ficou sem condição.");

            return $semCondicao;
        }

        return ['condicao_pergunta' => $origem, 'condicao_opcoes' => $chaves];
    }

    /** Posição global da pergunta anterior com esse enunciado (a mais próxima) que pode ser origem. */
    private function origem(string $chaveDoEnunciado): ?int
    {
        for ($posicao = count($this->aceitas) - 1; $posicao >= 0; $posicao--) {
            $aceita = $this->aceitas[$posicao];
            if ($aceita['chave'] === $chaveDoEnunciado && $aceita['tipo']->podeSerOrigem()) {
                return $posicao;
            }
        }

        return null;
    }

    /**
     * Chave de cada resposta pelo texto normalizado: Sim/Não na pergunta de sim/não, as opções nas de escolha.
     *
     * @param  list<array{chave: string, texto: string, outro: bool}>  $opcoes
     * @return array<string, string>
     */
    private function respostasPorTexto(TipoPergunta $tipo, array $opcoes): array
    {
        if ($tipo === TipoPergunta::SimNao) {
            return [
                EditalDaResposta::chaveDoNome('Sim') => TipoPergunta::CHAVE_SIM,
                EditalDaResposta::chaveDoNome('Não') => TipoPergunta::CHAVE_NAO,
            ];
        }

        $respostas = [];
        foreach ($opcoes as $opcao) {
            $respostas[EditalDaResposta::chaveDoNome($opcao['texto'])] ??= $opcao['chave'];
        }

        return $respostas;
    }

    /**
     * Posição de cada categoria do edital pelo nome (a primeira, quando há repetidos).
     *
     * @return array<string, int>
     */
    private function posicoesDasCategorias(Edital $edital): array
    {
        $posicoes = [];
        foreach ($edital->categorias->values() as $posicao => $categoria) {
            /** @var EditalCategoria $categoria */
            $posicoes[EditalDaResposta::chaveDoNome((string) $categoria->nome)] ??= $posicao;
        }

        return $posicoes;
    }

    /**
     * @param  array<mixed>  $recebida
     * @return array{geral: ?int, blocos: array{questionario?: array{nota: int, justificativa: string}}}
     */
    private function confiabilidade(array $recebida): array
    {
        if (! is_numeric($recebida['nota'] ?? null)) {
            return ['geral' => null, 'blocos' => []];
        }

        $nota = max(0, min(100, (int) round((float) $recebida['nota'])));

        return [
            'geral' => $nota,
            'blocos' => [self::BLOCO => ['nota' => $nota, 'justificativa' => self::texto($recebida['justificativa'] ?? null, 1000) ?? '']],
        ];
    }

    /**
     * Pontos de atenção da IA: sem texto saem; tipo desconhecido vira "incerta"; bloco sempre o do questionário.
     *
     * @param  list<mixed>  $atencao
     * @return list<array{bloco: string, tipo: string, texto: string, itens: list<string>}>
     */
    private function atencaoDaIa(array $atencao): array
    {
        $pontos = [];
        foreach ($atencao as $ponto) {
            $texto = is_array($ponto) && is_string($ponto['texto'] ?? null) ? trim($ponto['texto']) : '';
            if ($texto === '') {
                continue;
            }

            $tipo = is_string($ponto['tipo'] ?? null) ? $ponto['tipo'] : '';

            $pontos[] = [
                'bloco' => self::BLOCO,
                'tipo' => array_key_exists($tipo, EsquemaDaResposta::TIPOS_ATENCAO) ? $tipo : EditalDaResposta::TIPO_INCERTA,
                'texto' => mb_substr($texto, 0, 2000),
                'itens' => array_slice(array_map(fn (string $item): string => mb_substr($item, 0, 255), self::nomes($ponto['itens'] ?? null)), 0, 20),
            ];
        }

        return $pontos;
    }

    private function avisar(string $texto): void
    {
        $this->avisos[] = ['bloco' => self::BLOCO, 'tipo' => EditalDaResposta::TIPO_INCERTA, 'texto' => $texto, 'itens' => []];
    }

    /** Texto cortado em $bytes (VARCHAR2 do Oracle conta bytes); vazio = nulo. */
    private static function texto(mixed $valor, int $bytes): ?string
    {
        if (! is_string($valor) && ! is_int($valor) && ! is_float($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : rtrim(mb_strcut($texto, 0, $bytes, 'UTF-8'));
    }

    /**
     * Textos não vazios de uma lista, cortados no tamanho do enunciado; fora do formato = vazia.
     *
     * @return list<string>
     */
    private static function nomes(mixed $valores): array
    {
        $nomes = [];
        foreach (is_array($valores) ? $valores : [] as $valor) {
            $nome = self::texto($valor, self::BYTES_TEXTO);
            if ($nome !== null) {
                $nomes[] = $nome;
            }
        }

        return $nomes;
    }

    /** Texto citado nos avisos: até 100 caracteres. */
    private static function resumo(string $texto): string
    {
        return mb_strlen($texto) > 100 ? rtrim(mb_substr($texto, 0, 100)).'…' : $texto;
    }

    private static function descrever(mixed $valor): string
    {
        return is_scalar($valor) ? mb_substr((string) $valor, 0, 100) : 'valor fora do formato';
    }
}
