<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\TipoPergunta;
use App\Rules\TamanhoEmBytes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validação do questionário do edital enviado pelo formulário (campo `questionario`, já decodificado).
 * Formato: {secoes: list<{id?, titulo, descricao?, perguntas?: list<{id?, enunciado, ajuda?, tipo, obrigatoria,
 * limite_caracteres?, opcoes?: list<{chave, texto, outro?}>, todas_categorias, categorias?: list<posição da categoria>,
 * condicao_pergunta?: posição global, condicao_opcoes?: list<chave>}>}>}.
 * Posição global = índice (desde 0) da pergunta na ordem do questionário.
 */
final class RegrasDoQuestionario
{
    public const int MAXIMO_PERGUNTAS = 200;

    private const int MAXIMO_OPCOES = 50;

    private const string RAIZ = 'questionario.secoes';

    /**
     * @param  list<int>  $posicoesDeCategorias
     * @return array<string, mixed>
     */
    public static function regras(array $posicoesDeCategorias): array
    {
        $pergunta = self::RAIZ.'.*.perguntas.*';

        return [
            'questionario' => ['nullable', 'array'],
            'questionario.secoes' => ['nullable', 'array', 'list', 'max:'.SalvarEditalRequest::MAXIMO_ITENS],
            self::RAIZ.'.*.id' => ['nullable', 'integer'],
            self::RAIZ.'.*.titulo' => ['required', 'string', 'max:255', new TamanhoEmBytes(255)],
            self::RAIZ.'.*.descricao' => ['nullable', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            self::RAIZ.'.*.perguntas' => ['nullable', 'array', 'list', 'max:'.SalvarEditalRequest::MAXIMO_ITENS],
            "{$pergunta}.id" => ['nullable', 'integer'],
            "{$pergunta}.enunciado" => ['required', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            "{$pergunta}.ajuda" => ['nullable', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            "{$pergunta}.tipo" => ['required', Rule::enum(TipoPergunta::class)],
            "{$pergunta}.obrigatoria" => ['boolean'],
            "{$pergunta}.limite_caracteres" => ['nullable', 'integer', 'min:1', 'max:20000'],
            "{$pergunta}.opcoes" => ['nullable', 'array', 'list', 'max:'.self::MAXIMO_OPCOES],
            "{$pergunta}.opcoes.*.chave" => ['required', 'string', 'regex:/^[a-z0-9]{1,20}$/'],
            "{$pergunta}.opcoes.*.texto" => ['required', 'string', 'max:500', new TamanhoEmBytes(500)],
            "{$pergunta}.opcoes.*.outro" => ['nullable', 'boolean'],
            "{$pergunta}.todas_categorias" => ['boolean'],
            // Sem distinct: com dois * ele compara as listas de todas as perguntas juntas (duas perguntas não poderiam
            // ter a mesma categoria). Repetida na mesma pergunta, o SincronizadorDoQuestionario grava uma vez só.
            "{$pergunta}.categorias" => ['nullable', 'array'],
            "{$pergunta}.categorias.*" => ['integer', Rule::in($posicoesDeCategorias)],
            "{$pergunta}.condicao_pergunta" => ['nullable', 'integer'],
            "{$pergunta}.condicao_opcoes" => ['nullable', 'array'],
            "{$pergunta}.condicao_opcoes.*" => ['string'],
        ];
    }

    /**
     * Regras que dependem de mais de um campo. Tolera dados malformados (os erros deles vêm das regras acima).
     */
    public static function conferir(Validator $validator, mixed $questionario): void
    {
        $perguntas = self::perguntasEmOrdem($questionario);

        if (count($perguntas) > self::MAXIMO_PERGUNTAS) {
            $validator->errors()->add('questionario', 'Cadastre no máximo '.self::MAXIMO_PERGUNTAS.' perguntas.');
        }

        foreach ($perguntas as $posicao => $pergunta) {
            $base = self::RAIZ.".{$pergunta['secao']}.perguntas.{$pergunta['indice']}";
            $dados = $pergunta['dados'];

            self::conferirOpcoes($validator, $base, $dados);
            self::conferirCondicao($validator, $base, $posicao, $dados, $perguntas);
        }
    }

    /** Perguntas enviadas que não aparecem a ninguém: nem são de todas as categorias, nem de alguma. */
    public static function perguntasSemVinculo(mixed $questionario): int
    {
        $semVinculo = array_filter(
            self::perguntasEmOrdem($questionario),
            fn (array $pergunta): bool => ! filter_var($pergunta['dados']['todas_categorias'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && blank($pergunta['dados']['categorias'] ?? null),
        );

        return count($semVinculo);
    }

    /**
     * @return array<string, string>
     */
    public static function mensagens(): array
    {
        return [
            self::RAIZ.'.*.perguntas.*.opcoes.*.chave.regex' => 'Use só letras minúsculas e números, até 20.',
        ];
    }

    /**
     * @return list<array{secao: int, indice: int, dados: array<string, mixed>}>
     */
    private static function perguntasEmOrdem(mixed $questionario): array
    {
        $resultado = [];
        $secoes = is_array($questionario) && is_array($questionario['secoes'] ?? null) ? array_values($questionario['secoes']) : [];

        foreach ($secoes as $indiceDaSecao => $secao) {
            $itens = is_array($secao) && is_array($secao['perguntas'] ?? null) ? array_values($secao['perguntas']) : [];

            foreach ($itens as $indice => $dados) {
                $resultado[] = ['secao' => $indiceDaSecao, 'indice' => $indice, 'dados' => is_array($dados) ? $dados : []];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private static function conferirOpcoes(Validator $validator, string $base, array $dados): void
    {
        $tipo = self::tipo($dados);
        if ($tipo === null || ! $tipo->temOpcoes()) {
            return;
        }

        $opcoes = is_array($dados['opcoes'] ?? null) ? array_values($dados['opcoes']) : [];

        if (count($opcoes) < 2) {
            $validator->errors()->add("{$base}.opcoes", 'Cadastre ao menos duas opções.');
        }

        $outros = 0;
        $vistas = [];
        foreach ($opcoes as $indice => $opcao) {
            if (! is_array($opcao)) {
                continue;
            }

            if (filter_var($opcao['outro'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $outros++;
            }

            $chave = $opcao['chave'] ?? null;
            if (is_string($chave)) {
                if (in_array($chave, $vistas, true)) {
                    $validator->errors()->add("{$base}.opcoes.{$indice}.chave", 'Opção repetida.');
                }
                $vistas[] = $chave;
            }
        }

        if ($outros > 1) {
            $validator->errors()->add("{$base}.opcoes", 'Só uma opção pode ser "Outro".');
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  list<array{secao: int, indice: int, dados: array<string, mixed>}>  $perguntas
     */
    private static function conferirCondicao(Validator $validator, string $base, int $posicao, array $dados, array $perguntas): void
    {
        $origem = $dados['condicao_pergunta'] ?? null;
        if ($origem === null || $origem === '' || ! is_numeric($origem)) {
            return;
        }

        $origem = (int) $origem;
        if ($origem < 0 || $origem >= $posicao || ! isset($perguntas[$origem])) {
            $validator->errors()->add("{$base}.condicao_pergunta", 'A pergunta da condição precisa vir antes desta.');

            return;
        }

        $dadosDaOrigem = $perguntas[$origem]['dados'];
        $tipoDaOrigem = self::tipo($dadosDaOrigem);
        if ($tipoDaOrigem === null || ! $tipoDaOrigem->podeSerOrigem()) {
            $validator->errors()->add("{$base}.condicao_pergunta", 'A condição só pode depender de pergunta de escolha ou de sim/não.');

            return;
        }

        $respostas = is_array($dados['condicao_opcoes'] ?? null) ? array_values($dados['condicao_opcoes']) : [];
        if ($respostas === []) {
            $validator->errors()->add("{$base}.condicao_opcoes", 'Escolha ao menos uma resposta da condição.');

            return;
        }

        $chaves = $tipoDaOrigem === TipoPergunta::SimNao
            ? [TipoPergunta::CHAVE_SIM, TipoPergunta::CHAVE_NAO]
            : self::chavesDasOpcoes($dadosDaOrigem);

        foreach ($respostas as $resposta) {
            if (! is_string($resposta) || ! in_array($resposta, $chaves, true)) {
                $validator->errors()->add("{$base}.condicao_opcoes", 'Escolha respostas da pergunta da condição.');

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return list<string>
     */
    private static function chavesDasOpcoes(array $dados): array
    {
        $opcoes = is_array($dados['opcoes'] ?? null) ? $dados['opcoes'] : [];

        return array_values(array_filter(
            array_map(fn (mixed $opcao): mixed => is_array($opcao) ? ($opcao['chave'] ?? null) : null, $opcoes),
            is_string(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private static function tipo(array $dados): ?TipoPergunta
    {
        return is_string($dados['tipo'] ?? null) ? TipoPergunta::tryFrom($dados['tipo']) : null;
    }
}
