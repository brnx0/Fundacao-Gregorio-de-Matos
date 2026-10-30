<?php

namespace App\Support\Edital;

use App\Enums\TipoPergunta;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;

/**
 * Grava o questionário do edital: seções e perguntas por `id` (do mesmo edital; a pergunta pode mudar de seção
 * mantendo o id), as ausentes são apagadas e `ordem` é a posição. Numa segunda passada liga as condições pela
 * posição global (índice desde 0 na ordem do questionário; origem inexistente, posterior ou de tipo que não pode
 * ser origem desfaz a condição) e sincroniza as categorias (posições na lista de categorias recebida).
 * Deve rodar dentro da transação de quem chama.
 *
 * Formato: {secoes: list<{id?, titulo, descricao?, perguntas?: list<{id?, enunciado, ajuda?, tipo, obrigatoria?,
 * limite_caracteres?, opcoes?: list<{chave, texto, outro?}>, todas_categorias?, categorias?: list<posição>,
 * condicao_pergunta?: posição global, condicao_opcoes?: list<chave>}>}>}.
 */
class SincronizadorDoQuestionario
{
    /**
     * @param  array<string, mixed>  $questionario
     * @param  list<EditalCategoria>  $categorias
     */
    public function sincronizar(Edital $edital, array $questionario, array $categorias): void
    {
        // Oracle devolve os ids como string.
        $secoesExistentes = $edital->secoesDoQuestionario()->get()->keyBy(fn (EditalQuestionarioSecao $secao): int => (int) $secao->getKey());
        $perguntasExistentes = $edital->perguntas()->get()->keyBy(fn (EditalPergunta $pergunta): int => (int) $pergunta->getKey());

        /** @var list<array{EditalPergunta, array<string, mixed>}> $gravadas */
        $gravadas = [];

        foreach (array_values((array) ($questionario['secoes'] ?? [])) as $posicaoDaSecao => $itemDaSecao) {
            $idDaSecao = $this->idDe($itemDaSecao);
            $secao = ($idDaSecao !== null ? $secoesExistentes->pull($idDaSecao) : null)
                ?? $edital->secoesDoQuestionario()->make();
            $secao->fill([
                'titulo' => $itemDaSecao['titulo'],
                'descricao' => $this->textoOuNulo($itemDaSecao['descricao'] ?? null),
                'ordem' => $posicaoDaSecao + 1,
            ]);
            $secao->save();

            foreach (array_values((array) ($itemDaSecao['perguntas'] ?? [])) as $posicaoDaPergunta => $item) {
                $idDaPergunta = $this->idDe($item);
                $pergunta = ($idDaPergunta !== null ? $perguntasExistentes->pull($idDaPergunta) : null) ?? new EditalPergunta;
                $pergunta->fill($this->atributosDaPergunta($item));
                if (! $pergunta->exists) {
                    $pergunta->forceFill(['condicao_pergunta_id' => null, 'condicao_opcoes' => null]);
                }
                $pergunta->forceFill([
                    'edital_id' => $edital->getKey(),
                    'edital_questionario_secao_id' => $secao->getKey(),
                    'ordem' => $posicaoDaPergunta + 1,
                ]);
                $pergunta->save();

                $gravadas[] = [$pergunta, $item];
            }
        }

        $perguntasExistentes->each->delete();
        $secoesExistentes->each->delete();

        $this->ligarCondicoesECategorias($gravadas, $categorias);
    }

    /**
     * Apaga o questionário do edital e grava o recebido como novo; as categorias são as do edital.
     *
     * @param  array<string, mixed>  $questionario
     */
    public function substituir(Edital $edital, array $questionario): void
    {
        $edital->perguntas()->delete();
        $edital->secoesDoQuestionario()->delete();

        $this->sincronizar($edital, $questionario, $edital->categorias()->get()->all());
    }

    /**
     * @param  list<array{EditalPergunta, array<string, mixed>}>  $gravadas
     * @param  list<EditalCategoria>  $categorias
     */
    private function ligarCondicoesECategorias(array $gravadas, array $categorias): void
    {
        foreach ($gravadas as $posicao => [$pergunta, $item]) {
            $origem = null;
            $respostas = [];

            $posicaoDaOrigem = $item['condicao_pergunta'] ?? null;
            if (is_numeric($posicaoDaOrigem) && (int) $posicaoDaOrigem >= 0 && (int) $posicaoDaOrigem < $posicao) {
                $candidata = $gravadas[(int) $posicaoDaOrigem][0];
                $chaves = $candidata->chavesDasOpcoes();
                $respostas = array_values(array_unique(array_filter(
                    array_map(strval(...), array_filter((array) ($item['condicao_opcoes'] ?? []), is_scalar(...))),
                    fn (string $chave): bool => in_array($chave, $chaves, true),
                )));

                if ($candidata->tipo->podeSerOrigem() && $respostas !== []) {
                    $origem = $candidata;
                }
            }

            $pergunta->forceFill([
                'condicao_pergunta_id' => $origem?->getKey(),
                'condicao_opcoes' => $origem !== null ? $respostas : null,
            ]);
            if ($pergunta->isDirty()) {
                $pergunta->save();
            }

            $pergunta->categorias()->sync($this->idsDasCategorias($item, $pergunta, $categorias));
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<EditalCategoria>  $categorias
     * @return list<int|string>
     */
    private function idsDasCategorias(array $item, EditalPergunta $pergunta, array $categorias): array
    {
        if ($pergunta->todas_categorias) {
            return [];
        }

        $ids = [];
        foreach ((array) ($item['categorias'] ?? []) as $posicao) {
            if (is_numeric($posicao) && isset($categorias[(int) $posicao])) {
                $ids[] = $categorias[(int) $posicao]->getKey();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function atributosDaPergunta(array $item): array
    {
        $tipo = TipoPergunta::from($item['tipo']);

        return [
            'enunciado' => $item['enunciado'],
            'ajuda' => $this->textoOuNulo($item['ajuda'] ?? null),
            'tipo' => $tipo,
            'obrigatoria' => $tipo === TipoPergunta::Declaracao ? true : filter_var($item['obrigatoria'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'limite_caracteres' => $tipo->temLimite() && is_numeric($item['limite_caracteres'] ?? null) ? (int) $item['limite_caracteres'] : null,
            'opcoes' => $tipo->temOpcoes() ? $this->opcoes($item['opcoes'] ?? []) : null,
            'todas_categorias' => filter_var($item['todas_categorias'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @return list<array{chave: string, texto: string, outro: bool}>
     */
    private function opcoes(mixed $opcoes): array
    {
        return array_values(array_map(
            fn (array $opcao): array => [
                'chave' => (string) $opcao['chave'],
                'texto' => (string) $opcao['texto'],
                'outro' => filter_var($opcao['outro'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            array_filter((array) $opcoes, is_array(...)),
        ));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function idDe(array $item): ?int
    {
        return isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
    }

    private function textoOuNulo(mixed $texto): ?string
    {
        return is_string($texto) && trim($texto) !== '' ? $texto : null;
    }
}
