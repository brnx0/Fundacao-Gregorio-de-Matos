<?php

namespace App\Support\Edital;

use App\Enums\NaturezaProponente;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use App\Models\EditalChamada;
use App\Models\EditalCriterio;
use App\Models\EditalDocumento;
use App\Support\ArquivoArmazenado;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;

/**
 * Grava as listas do edital (chamadas, categorias com itens, documentos, critérios) e deriva dele
 * o período de inscrição (menor início e maior fim das chamadas com as duas datas; nulo sem nenhuma).
 *
 * Usado pelo cadastro da retaguarda e pela importação do PDF; deve rodar dentro da transação de quem chama.
 * Cada item com `id` de um registro da mesma lista do edital é atualizado; os demais viram novos, e os
 * registros que não vieram são apagados. A ordem é a da lista. Referências entre listas são posições
 * (índice, a partir de 0, na lista enviada): `chamada` da categoria, `categorias` do documento e do critério;
 * posição inexistente é ignorada. Valores em reais.
 *
 * Arquivos-modelo: os gravados agora ficam em arquivosNovos() (apagar se a transação falhar) e os
 * substituídos ou de documentos removidos em arquivosSubstituidos() (apagar depois do commit).
 *
 * Formato de entrada (chaves opcionais podem faltar):
 *  - chamadas: list<{id?, nome, inicio?: Y-m-d, fim?: Y-m-d, homologacao_inicio?: Y-m-d, homologacao_fim?: Y-m-d, validacao_inicio?: Y-m-d, validacao_fim?: Y-m-d}>
 *  - categorias: list<{id?, nome, descricao?, valor?, vagas?, naturezas?: list<string>, chamada?: posição, itens?: list<{id?, nome, valor?, vagas?, suplentes?}>}>
 *  - documentos: list<{id?, nome, orientacao?, etapa, obrigatorio?, naturezas?, todas_categorias?, categorias?: list<posição>, modalidade_cota?, checagem?, instrucao_ia?, modelo?: UploadedFile, remover_modelo?}>
 *  - criterios: list<{id?, nome, descricao?, peso?, pontos_maximos?, todas_categorias?, categorias?: list<posição>}>
 */
class SincronizadorDoEdital
{
    public const string DISCO_MODELOS = 'local';

    private const string PASTA_MODELOS = 'editais/modelos';

    private const array LISTAS = ['chamadas', 'categorias', 'documentos', 'criterios'];

    /** @var list<array{string, string}> */
    private array $arquivosNovos = [];

    /** @var list<array{string, string}> */
    private array $arquivosSubstituidos = [];

    /**
     * @param  array{chamadas?: list<array<string, mixed>>, categorias?: list<array<string, mixed>>, documentos?: list<array<string, mixed>>, criterios?: list<array<string, mixed>>}  $listas
     * @param  array<string, mixed>|null  $questionario  Formato de SincronizadorDoQuestionario; nulo não toca no questionário.
     */
    public function sincronizar(Edital $edital, array $listas, ?array $questionario = null): void
    {
        $listas = array_map(
            fn (string $chave): array => array_values($listas[$chave] ?? []),
            array_combine(self::LISTAS, self::LISTAS),
        );

        $chamadas = $this->sincronizarLista($edital->chamadas(), $listas['chamadas'], function (EditalChamada $chamada, array $item): void {
            $chamada->fill([
                'nome' => $item['nome'],
                'inicio' => $item['inicio'] ?? null,
                'fim' => $item['fim'] ?? null,
                'validacao_inicio' => $item['validacao_inicio'] ?? null,
                'validacao_fim' => $item['validacao_fim'] ?? null,
                'homologacao_inicio' => $item['homologacao_inicio'] ?? null,
                'homologacao_fim' => $item['homologacao_fim'] ?? null,
            ]);
        });

        $categorias = $this->sincronizarLista($edital->categorias(), $listas['categorias'], function (EditalCategoria $categoria, array $item) use ($chamadas): void {
            $categoria->fill([
                'nome' => $item['nome'],
                'descricao' => $item['descricao'] ?? null,
                'valor_centavos' => self::centavos($item['valor'] ?? null),
                'vagas' => $item['vagas'] ?? null,
                'naturezas' => self::naturezas($item['naturezas'] ?? null),
                'edital_chamada_id' => self::emPosicao($chamadas, $item['chamada'] ?? null)?->getKey(),
            ]);
        });

        foreach ($categorias as $posicao => $categoria) {
            $this->sincronizarLista($categoria->itens(), $listas['categorias'][$posicao]['itens'] ?? [], function (EditalCategoriaItem $itemDaCategoria, array $item): void {
                $itemDaCategoria->fill([
                    'nome' => $item['nome'],
                    'valor_centavos' => self::centavos($item['valor'] ?? null),
                    'vagas' => $item['vagas'] ?? null,
                    'suplentes' => $item['suplentes'] ?? null,
                ]);
            });
        }

        $documentos = $this->sincronizarLista(
            $edital->documentos(),
            $listas['documentos'],
            function (EditalDocumento $documento, array $item) use ($categorias): void {
                $documento->fill([
                    'nome' => $item['nome'],
                    'orientacao' => $item['orientacao'] ?? null,
                    'etapa' => $item['etapa'],
                    'obrigatorio' => self::booleano($item['obrigatorio'] ?? null, true),
                    'naturezas' => self::naturezas($item['naturezas'] ?? null),
                    'todas_categorias' => self::categoriasEscolhidas($item, $categorias) === null,
                    'modalidade_cota' => $item['modalidade_cota'] ?? null,
                ]);
                // Sem a chave (documentos da importação por IA), vale o padrão da coluna.
                if (isset($item['checagem'])) {
                    $documento->fill(['checagem' => $item['checagem'], 'instrucao_ia' => $item['instrucao_ia'] ?? null]);
                }
                $this->trocarModelo($documento, $item['modelo'] ?? null, self::booleano($item['remover_modelo'] ?? null, false));
            },
            function (EditalDocumento $removido): void {
                if (filled($removido->modelo)) {
                    $this->arquivosSubstituidos[] = [self::DISCO_MODELOS, $removido->modelo];
                }
            },
        );

        foreach ($documentos as $posicao => $documento) {
            $item = $listas['documentos'][$posicao];
            $documento->categorias()->sync(self::categoriasEscolhidas($item, $categorias) ?? []);
        }

        $criterios = $this->sincronizarLista($edital->criterios(), $listas['criterios'], function (EditalCriterio $criterio, array $item) use ($categorias): void {
            $criterio->fill([
                'nome' => $item['nome'],
                'descricao' => $item['descricao'] ?? null,
                'peso' => $item['peso'] ?? null,
                'pontos_maximos' => $item['pontos_maximos'] ?? null,
                'todas_categorias' => self::categoriasEscolhidas($item, $categorias) === null,
            ]);
        });

        foreach ($criterios as $posicao => $criterio) {
            $item = $listas['criterios'][$posicao];
            $criterio->categorias()->sync(self::categoriasEscolhidas($item, $categorias) ?? []);
        }

        if ($questionario !== null) {
            (new SincronizadorDoQuestionario)->sincronizar($edital, $questionario, $categorias);
        }

        $this->derivarPeriodo($edital);
    }

    /**
     * Arquivos-modelo gravados nesta sincronização: apagar se a transação falhar.
     *
     * @return list<array{string, string}>
     */
    public function arquivosNovos(): array
    {
        return $this->arquivosNovos;
    }

    /**
     * Arquivos-modelo trocados, removidos ou de documentos apagados: apagar depois do commit.
     *
     * @return list<array{string, string}>
     */
    public function arquivosSubstituidos(): array
    {
        return $this->arquivosSubstituidos;
    }

    /**
     * Naturezas válidas, sem repetição; nenhuma = nulo.
     *
     * @param  array<mixed>|null  $valores
     * @return list<NaturezaProponente>|null
     */
    public static function naturezas(?array $valores): ?array
    {
        $naturezas = [];
        foreach ($valores ?? [] as $valor) {
            $natureza = $valor instanceof NaturezaProponente ? $valor : NaturezaProponente::tryFrom((string) $valor);
            if ($natureza !== null && ! in_array($natureza, $naturezas, true)) {
                $naturezas[] = $natureza;
            }
        }

        return $naturezas === [] ? null : $naturezas;
    }

    /**
     * Atualiza os registros da relação pela lista (por id), cria os novos e apaga os que não vieram.
     *
     * @template TModelo of Model
     *
     * @param  HasMany<TModelo, Model>  $relacao
     * @param  list<array<string, mixed>>  $itens
     * @param  callable(TModelo, array<string, mixed>): void  $preencher
     * @param  (callable(TModelo): void)|null  $aoRemover
     * @return list<TModelo> Os registros gravados, na posição em que vieram.
     */
    private function sincronizarLista(HasMany $relacao, array $itens, callable $preencher, ?callable $aoRemover = null): array
    {
        // Oracle devolve os ids como string.
        $existentes = $relacao->get()->keyBy(fn (Model $registro): int => (int) $registro->getKey());

        $gravados = [];
        foreach (array_values($itens) as $posicao => $item) {
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            $registro = ($id !== null ? $existentes->pull($id) : null) ?? $relacao->make();

            $preencher($registro, $item);
            $registro->setAttribute('ordem', $posicao + 1);
            $registro->save();

            $gravados[] = $registro;
        }

        foreach ($existentes as $removido) {
            if ($aoRemover !== null) {
                $aoRemover($removido);
            }
            $removido->delete();
        }

        return $gravados;
    }

    private function trocarModelo(EditalDocumento $documento, mixed $arquivo, bool $remover): void
    {
        if ($arquivo instanceof UploadedFile) {
            if (filled($documento->modelo)) {
                $this->arquivosSubstituidos[] = [self::DISCO_MODELOS, $documento->modelo];
            }
            $documento->modelo = ArquivoArmazenado::guardar($arquivo, self::DISCO_MODELOS, self::PASTA_MODELOS);
            $documento->modelo_nome = mb_strcut($arquivo->getClientOriginalName(), 0, 255, 'UTF-8');
            $this->arquivosNovos[] = [self::DISCO_MODELOS, $documento->modelo];

            return;
        }

        if ($remover && filled($documento->modelo)) {
            $this->arquivosSubstituidos[] = [self::DISCO_MODELOS, $documento->modelo];
            $documento->modelo = null;
            $documento->modelo_nome = null;
        }
    }

    private function derivarPeriodo(Edital $edital): void
    {
        $chamadas = $edital->chamadas()->whereNotNull('inicio')->whereNotNull('fim')->get();

        $edital->inscricoes_inicio = $chamadas->map(fn (EditalChamada $c): string => $c->inicio->toDateString())->min();
        $edital->inscricoes_fim = $chamadas->map(fn (EditalChamada $c): string => $c->fim->toDateString())->max();
        $edital->save();
    }

    /**
     * Ids das categorias escolhidas no documento ou critério; nulo = vale para todas (sem a marcação,
     * ou marcado como "algumas" sem nenhuma categoria existente nas posições).
     *
     * @param  array<string, mixed>  $item
     * @param  list<EditalCategoria>  $categorias
     * @return list<int>|null
     */
    private static function categoriasEscolhidas(array $item, array $categorias): ?array
    {
        if (self::booleano($item['todas_categorias'] ?? null, true)) {
            return null;
        }

        $ids = self::idsEmPosicoes($categorias, $item['categorias'] ?? []);

        return $ids === [] ? null : $ids;
    }

    /**
     * @template TModelo of Model
     *
     * @param  list<TModelo>  $registros
     * @return TModelo|null
     */
    private static function emPosicao(array $registros, mixed $posicao): ?Model
    {
        return is_numeric($posicao) ? ($registros[(int) $posicao] ?? null) : null;
    }

    /**
     * @param  list<Model>  $registros
     * @param  array<mixed>  $posicoes
     * @return list<int>
     */
    private static function idsEmPosicoes(array $registros, array $posicoes): array
    {
        $ids = [];
        foreach ($posicoes as $posicao) {
            $registro = self::emPosicao($registros, $posicao);
            if ($registro !== null) {
                $ids[] = (int) $registro->getKey();
            }
        }

        return array_values(array_unique($ids));
    }

    private static function centavos(mixed $reais): ?int
    {
        return is_numeric($reais) ? (int) round((float) $reais * 100) : null;
    }

    private static function booleano(mixed $valor, bool $padrao): bool
    {
        return $valor === null ? $padrao : filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $padrao;
    }
}
