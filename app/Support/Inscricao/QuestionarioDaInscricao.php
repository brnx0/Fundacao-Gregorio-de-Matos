<?php

namespace App\Support\Inscricao;

use App\Enums\TipoPergunta;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\Inscricao;
use App\Models\InscricaoResposta;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * O questionário do edital visto por uma inscrição: as perguntas que valem para a categoria dela, cuja natureza já é
 * uma que a categoria aceita (na ordem global: seções por ordem e id, perguntas por ordem e id), as que estão visíveis com as respostas dadas
 * (condições em cadeia), o que falta responder e a limpeza das respostas que deixaram de valer.
 *
 * Valor de uma resposta: array{texto: ?string, opcoes: list<string>, outro: ?string}.
 *
 * @phpstan-type ValorDaResposta array{texto: ?string, opcoes: list<string>, outro: ?string}
 */
class QuestionarioDaInscricao
{
    public const string ERRO_RESPONDA = 'Responda esta pergunta.';

    public const string ERRO_DECLARACAO = 'Marque a declaração para continuar.';

    public const string ERRO_OUTRO = 'Diga qual.';

    /** @var Collection<int, EditalQuestionarioSecao>|null */
    private ?Collection $secoes = null;

    /** @var Collection<int, EditalPergunta>|null */
    private ?Collection $perguntas = null;

    /** @var array<int, ValorDaResposta>|null */
    private ?array $respostasSalvas = null;

    /** @var list<int> ids das perguntas com linha gravada, da última leitura */
    private array $idsGravados = [];

    public function __construct(private Inscricao $inscricao) {}

    /**
     * As perguntas que valem para a categoria da inscrição, na ordem global.
     *
     * @return Collection<int, EditalPergunta>
     */
    public function perguntasQueValem(): Collection
    {
        if ($this->perguntas !== null) {
            return $this->perguntas;
        }

        $categoriaId = $this->inscricao->edital_categoria_id === null ? null : (int) $this->inscricao->edital_categoria_id;

        return $this->perguntas = $this->secoes()
            ->flatMap(fn (EditalQuestionarioSecao $secao): Collection => $secao->perguntas)
            ->filter(fn (EditalPergunta $pergunta): bool => $pergunta->valePara($categoriaId))
            ->values();
    }

    /**
     * As seções com ao menos uma pergunta que vale, na ordem, cada uma com só as perguntas que valem (visíveis ou não).
     *
     * @return Collection<int, EditalQuestionarioSecao>
     */
    public function secoesQueValem(): Collection
    {
        $porSecao = $this->perguntasQueValem()->groupBy(fn (EditalPergunta $pergunta): int => (int) $pergunta->edital_questionario_secao_id);

        return $this->secoes()
            ->filter(fn (EditalQuestionarioSecao $secao): bool => $porSecao->has((int) $secao->id))
            ->map(fn (EditalQuestionarioSecao $secao): EditalQuestionarioSecao => (clone $secao)->setRelation('perguntas', new EloquentCollection($porSecao->get((int) $secao->id)->all())))
            ->values();
    }

    /**
     * As respostas gravadas, pelo id da pergunta, relidas com a pergunta como está hoje (normalizar): se o gestor
     * trocou o tipo ou tirou a opção escolhida, o que não serve mais não conta como resposta. As de perguntas que não
     * valem para a inscrição vão como gravadas (ficam ocultas e o descarte as apaga). Lidas uma vez por instância.
     *
     * @return array<int, ValorDaResposta>
     */
    public function respostasSalvas(): array
    {
        if ($this->respostasSalvas !== null) {
            return $this->respostasSalvas;
        }

        $perguntas = $this->perguntasQueValem()->keyBy(fn (EditalPergunta $pergunta): int => (int) $pergunta->id);
        $this->idsGravados = [];
        $respostas = [];

        foreach ($this->inscricao->respostas()->get() as $resposta) {
            /** @var InscricaoResposta $resposta */
            $id = (int) $resposta->edital_pergunta_id;
            $this->idsGravados[] = $id;
            $pergunta = $perguntas->get($id);
            $valor = $pergunta === null ? $resposta->valor() : self::normalizar($pergunta, $resposta->valor());

            if ($valor !== null) {
                $respostas[$id] = $valor;
            }
        }

        return $this->respostasSalvas = $respostas;
    }

    /**
     * As perguntas que valem e estão visíveis com estas respostas, na ordem. A condicionada só aparece se a origem
     * está visível e a resposta dela tem alguma das opções da condição.
     *
     * @param  array<int, ValorDaResposta>  $respostas
     * @return Collection<int, EditalPergunta>
     */
    public function visiveis(array $respostas): Collection
    {
        $perguntas = $this->perguntasQueValem()->keyBy(fn (EditalPergunta $pergunta): int => (int) $pergunta->id);
        /** @var array<int, bool> $visibilidade */
        $visibilidade = [];

        return $perguntas
            ->filter(fn (EditalPergunta $pergunta): bool => $this->estaVisivel($pergunta, $perguntas, $respostas, $visibilidade, []))
            ->values();
    }

    /**
     * As seções com ao menos uma pergunta visível, na ordem.
     *
     * @param  array<int, ValorDaResposta>  $respostas
     * @return Collection<int, EditalQuestionarioSecao>
     */
    public function secoesVisiveis(array $respostas): Collection
    {
        $comVisivel = $this->visiveis($respostas)
            ->map(fn (EditalPergunta $pergunta): int => (int) $pergunta->edital_questionario_secao_id)
            ->unique()
            ->all();

        return $this->secoes()
            ->filter(fn (EditalQuestionarioSecao $secao): bool => in_array((int) $secao->id, $comVisivel, true))
            ->values();
    }

    /**
     * O que falta nas perguntas obrigatórias visíveis (de uma seção, se dada): a resposta, a declaração marcada e o
     * "Qual?" da opção "Outro".
     *
     * @param  array<int, ValorDaResposta>  $respostas
     * @return array<string, string>
     */
    public function faltando(array $respostas, ?int $secaoId = null): array
    {
        $faltando = [];

        foreach ($this->visiveis($respostas) as $pergunta) {
            if (! $pergunta->obrigatoria || ($secaoId !== null && (int) $pergunta->edital_questionario_secao_id !== $secaoId)) {
                continue;
            }

            $id = (int) $pergunta->id;
            $valor = $respostas[$id] ?? null;

            if ($pergunta->tipo === TipoPergunta::Declaracao) {
                if (! in_array(TipoPergunta::CHAVE_DECLARACAO, $valor['opcoes'] ?? [], true)) {
                    $faltando["respostas.{$id}"] = self::ERRO_DECLARACAO;
                }

                continue;
            }

            if ($valor === null || ($valor['texto'] === null && $valor['opcoes'] === [])) {
                $faltando["respostas.{$id}"] = self::ERRO_RESPONDA;
            } elseif (self::escolheuOutro($pergunta, $valor['opcoes']) && $valor['outro'] === null) {
                $faltando["respostas.{$id}.outro"] = self::ERRO_OUTRO;
            }
        }

        return $faltando;
    }

    /**
     * Apaga as respostas de perguntas que não valem mais para a inscrição, ficaram ocultas ou que, relidas com a
     * pergunta de hoje, não servem mais, recalculando até estabilizar (apagar a resposta de uma origem pode esconder
     * a dependente).
     */
    public function descartarRespostasQueNaoValem(): void
    {
        $this->respostasSalvas = null;

        do {
            $respostas = $this->respostasSalvas();
            $visiveis = $this->visiveis($respostas)->map(fn (EditalPergunta $pergunta): int => (int) $pergunta->id)->all();
            $descartar = array_values(array_diff($this->idsGravados, array_intersect(array_keys($respostas), $visiveis)));

            if ($descartar !== []) {
                $this->inscricao->respostas()->whereIn('edital_pergunta_id', $descartar)->delete();
                $this->respostasSalvas = null;
            }
        } while ($descartar !== []);
    }

    /**
     * A resposta enviada no formato gravado; nula se não há resposta. Quebras de linha viram "\n" e os textos são
     * aparados; opções que a pergunta não tem saem; o "Qual?" só fica com a opção "Outro" escolhida.
     *
     * @return ValorDaResposta|null
     */
    public static function normalizar(EditalPergunta $pergunta, mixed $entrada): ?array
    {
        if (! is_array($entrada)) {
            return null;
        }

        $temOpcoes = $pergunta->tipo->temOpcoes()
            || $pergunta->tipo === TipoPergunta::SimNao
            || $pergunta->tipo === TipoPergunta::Declaracao;

        $opcoes = [];
        if ($temOpcoes && is_array($entrada['opcoes'] ?? null)) {
            $chaves = $pergunta->chavesDasOpcoes();
            $opcoes = array_values(array_unique(array_filter(
                $entrada['opcoes'],
                fn (mixed $opcao): bool => is_string($opcao) && in_array($opcao, $chaves, true),
            )));

            if ($pergunta->tipo !== TipoPergunta::MultiplaEscolha) {
                $opcoes = array_slice($opcoes, 0, 1);
            }
        }

        $texto = $temOpcoes ? null : self::textoLimpo($entrada['texto'] ?? null);
        $outro = self::escolheuOutro($pergunta, $opcoes) ? self::textoLimpo($entrada['outro'] ?? null) : null;

        if ($texto === null && $opcoes === []) {
            return null;
        }

        return ['texto' => $texto, 'opcoes' => $opcoes, 'outro' => $outro];
    }

    /**
     * @param  list<string>  $opcoes
     */
    private static function escolheuOutro(EditalPergunta $pergunta, array $opcoes): bool
    {
        if (! $pergunta->tipo->temOpcoes()) {
            return false;
        }

        foreach ($pergunta->opcoes ?? [] as $opcao) {
            if (($opcao['outro'] ?? false) && in_array((string) $opcao['chave'], $opcoes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function textoLimpo(mixed $texto): ?string
    {
        if (! is_string($texto) && ! is_int($texto) && ! is_float($texto)) {
            return null;
        }

        $limpo = trim(str_replace("\r\n", "\n", (string) $texto));

        return $limpo === '' ? null : $limpo;
    }

    /**
     * @param  Collection<int, EditalPergunta>  $perguntas  as que valem, pelo id
     * @param  array<int, ValorDaResposta>  $respostas
     * @param  array<int, bool>  $visibilidade  já calculadas
     * @param  array<int, true>  $caminho  origens em cálculo (guarda contra ciclo)
     */
    private function estaVisivel(EditalPergunta $pergunta, Collection $perguntas, array $respostas, array &$visibilidade, array $caminho): bool
    {
        $id = (int) $pergunta->id;

        if (isset($visibilidade[$id])) {
            return $visibilidade[$id];
        }

        if ($pergunta->condicao_pergunta_id === null) {
            return $visibilidade[$id] = true;
        }

        $origemId = (int) $pergunta->condicao_pergunta_id;
        $origem = $perguntas->get($origemId);

        if ($origem === null || isset($caminho[$id])) {
            return $visibilidade[$id] = false;
        }

        $caminho[$id] = true;
        $escolhidas = $respostas[$origemId]['opcoes'] ?? [];

        return $visibilidade[$id] = $this->estaVisivel($origem, $perguntas, $respostas, $visibilidade, $caminho)
            && array_intersect($escolhidas, $pergunta->condicao_opcoes ?? []) !== [];
    }

    /**
     * Seções com as perguntas e os ids das categorias delas, numa leva só: valePara usa as categorias carregadas.
     *
     * @return Collection<int, EditalQuestionarioSecao>
     */
    private function secoes(): Collection
    {
        return $this->secoes ??= $this->inscricao->edital->secoesDoQuestionario()->with('perguntas.categorias:edital_categorias.id')->get()->toBase();
    }
}
