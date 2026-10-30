<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\SituacaoImportacao;
use App\Enums\TipoPergunta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Retaguarda\Concerns\FormatoDoFormulario;
use App\Http\Requests\Retaguarda\SalvarQuestionarioDoEditalRequest;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalPergunta;
use App\Models\EditalQuestionarioSecao;
use App\Models\ImportacaoQuestionario;
use App\Support\Edital\SincronizadorDoQuestionario;
use App\Support\EditalIa\EsquemaDaResposta;
use App\Support\EditalIa\QuestionarioDaResposta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Questionário do edital na sua própria tela, aberta pelo ícone da lista de editais. Só existe com o edital salvo e
 * com categoria: as perguntas são vinculadas às categorias, pela posição na ordem gravada.
 */
class QuestionarioDoEditalController extends Controller
{
    use FormatoDoFormulario;

    public const string AVISO_SEM_CATEGORIA = 'Cadastre ao menos uma categoria antes do questionário: as perguntas são vinculadas às categorias.';

    private const string ROTULO_DO_QUESTIONARIO = 'Questionário';

    /** Sem categoria, volta ao formulário do edital, já na aba Categorias. */
    public function edit(Edital $edital): Response|RedirectResponse
    {
        if (! $edital->categorias()->exists()) {
            return to_route('retaguarda.editais.edit', ['edital' => $edital, 'aba' => 'categorias'])->with('aviso', self::AVISO_SEM_CATEGORIA);
        }

        $edital->load(['categorias', 'secoesDoQuestionario.perguntas.categorias']);
        $configuracaoIa = ConfiguracaoIa::atual();

        return Inertia::render('Retaguarda/Editais/Questionario', [
            'edital' => [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'publicado' => $edital->publicado,
                'categorias' => $edital->categorias->map(fn (EditalCategoria $categoria): array => [
                    'id' => (int) $categoria->id,
                    'nome' => $categoria->nome,
                ])->all(),
                'questionario' => $this->questionarioParaOFormulario($edital, $this->posicoesPorId($edital->categorias)),
            ],
            'questionarioTravado' => $edital->questionarioTravado(),
            'avisoQuestionarioTravado' => Edital::AVISO_QUESTIONARIO_TRAVADO,
            'questionarioVersao' => ImportacaoQuestionario::versaoDoQuestionario($edital),
            'importacaoQuestionario' => $this->ultimaImportacaoDoQuestionario($edital),
            'quadroQuestionario' => $this->quadroDoQuestionario($edital),
            // Importação com provedor configurado (modelo e chave) e o tamanho máximo do arquivo.
            'importacaoQuestionarioConfigurada' => $configuracaoIa->provedoresDaImportacao() !== [],
            'tamanhoMaximoArquivoMb' => $configuracaoIa->tamanhoMaximoPdfMb(),
            'tiposPergunta' => $this->opcoesDe(TipoPergunta::class),
        ]);
    }

    public function update(SalvarQuestionarioDoEditalRequest $request, Edital $edital): RedirectResponse
    {
        DB::transaction(function () use ($request, $edital): void {
            // Uma inscrição pode ter sido finalizada depois da validação do envio: confere de novo, com o edital travado.
            Edital::query()->whereKey($edital->getKey())->lockForUpdate()->first();
            if ($edital->questionarioTravado()) {
                throw ValidationException::withMessages(['questionario' => Edital::AVISO_QUESTIONARIO_TRAVADO]);
            }

            (new SincronizadorDoQuestionario)->sincronizar($edital, $request->questionario(), $edital->categorias()->get()->all());
        });

        return to_route('retaguarda.editais.index')->with('sucesso', 'Questionário salvo.');
    }

    /**
     * Seções e perguntas como a tela as envia de volta: categorias por posição e a origem da condição por posição
     * global (índice da pergunta na ordem do questionário).
     *
     * @param  array<int, int>  $posicaoDaCategoria
     * @return array{secoes: list<array<string, mixed>>}
     */
    private function questionarioParaOFormulario(Edital $edital, array $posicaoDaCategoria): array
    {
        $posicaoGlobal = [];
        foreach ($edital->secoesDoQuestionario->flatMap->perguntas->values() as $posicao => $pergunta) {
            $posicaoGlobal[(int) $pergunta->getKey()] = $posicao;
        }

        return [
            'secoes' => $edital->secoesDoQuestionario->map(fn (EditalQuestionarioSecao $secao): array => [
                'id' => (int) $secao->id,
                'titulo' => $secao->titulo,
                'descricao' => $secao->descricao,
                'perguntas' => $secao->perguntas->map(fn (EditalPergunta $pergunta): array => [
                    'id' => (int) $pergunta->id,
                    'enunciado' => $pergunta->enunciado,
                    'ajuda' => $pergunta->ajuda,
                    'tipo' => $pergunta->tipo->value,
                    'obrigatoria' => $pergunta->obrigatoria,
                    'limite_caracteres' => $pergunta->limite_caracteres,
                    'opcoes' => $pergunta->opcoes ?? [],
                    'todas_categorias' => $pergunta->todas_categorias,
                    'categorias' => $this->posicoesDe($pergunta->categorias, $posicaoDaCategoria),
                    'condicao_pergunta' => $pergunta->condicao_pergunta_id !== null ? ($posicaoGlobal[(int) $pergunta->condicao_pergunta_id] ?? null) : null,
                    'condicao_opcoes' => $pergunta->condicao_opcoes ?? [],
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * A importação do questionário mais recente do edital, para a linha de situação da tela (sem as colunas CLOB).
     *
     * @return array{id: int, situacao: string, erro: ?string, arquivoNome: string, enviadaEm: string}|null
     */
    private function ultimaImportacaoDoQuestionario(Edital $edital): ?array
    {
        $importacao = ImportacaoQuestionario::query()
            ->select(['id', 'situacao', 'erro', 'iniciada_em', 'arquivo_nome', 'created_at'])
            ->where('edital_id', $edital->getKey())
            ->latest('id')
            ->first();

        if ($importacao === null) {
            return null;
        }

        return [
            'id' => (int) $importacao->id,
            'situacao' => $importacao->situacaoExibida()->value,
            'erro' => $importacao->erroExibido(),
            'arquivoNome' => $importacao->arquivo_nome,
            'enviadaEm' => $importacao->created_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
        ];
    }

    /**
     * Quadro "Importado pela IA" da tela, enquanto a última importação concluída não foi conferida.
     * Só essa importação tem as colunas CLOB lidas.
     *
     * @return array{
     *     confiabilidade: array{geral: ?int, blocos: list<array{bloco: string, rotulo: string, nota: int, justificativa: string}>},
     *     atencao: list<array{bloco: ?string, blocoRotulo: string, tipo: string, tipoRotulo: string, texto: string, itens: list<string>}>,
     *     conferida: bool, modelo: string, tokensTotal: ?int, importadaEm: string
     * }|null
     */
    private function quadroDoQuestionario(Edital $edital): ?array
    {
        $ultima = ImportacaoQuestionario::query()
            ->select(['id', 'conferida_em'])
            ->where('edital_id', $edital->getKey())
            ->where('situacao', SituacaoImportacao::Concluida->value)
            ->latest('id')
            ->first();

        if ($ultima === null || $ultima->conferida_em !== null) {
            return null;
        }

        $importacao = ImportacaoQuestionario::query()
            ->select(['id', 'confiabilidade', 'atencao', 'conferida_em', 'modelo', 'tokens_total', 'updated_at'])
            ->whereNull('conferida_em')
            ->find($ultima->getKey());

        if ($importacao === null) {
            return null;
        }

        $bloco = QuestionarioDaResposta::BLOCO;
        $nota = $importacao->confiabilidade['blocos'][$bloco] ?? null;

        return [
            'confiabilidade' => [
                'geral' => $importacao->confiabilidade['geral'] ?? null,
                'blocos' => $nota === null ? [] : [[
                    'bloco' => $bloco,
                    'rotulo' => self::ROTULO_DO_QUESTIONARIO,
                    'nota' => (int) $nota['nota'],
                    'justificativa' => (string) ($nota['justificativa'] ?? ''),
                ]],
            ],
            'atencao' => array_map(fn (array $ponto): array => [
                'bloco' => ($ponto['bloco'] ?? null) === $bloco ? $bloco : null,
                'blocoRotulo' => ($ponto['bloco'] ?? null) === $bloco ? self::ROTULO_DO_QUESTIONARIO : 'Geral',
                'tipo' => $ponto['tipo'],
                'tipoRotulo' => EsquemaDaResposta::TIPOS_ATENCAO[$ponto['tipo']] ?? $ponto['tipo'],
                'texto' => $ponto['texto'],
                'itens' => $ponto['itens'] ?? [],
            ], $importacao->atencao ?? []),
            'conferida' => false,
            'modelo' => $importacao->modelo,
            'tokensTotal' => $importacao->tokens_total,
            'importadaEm' => $importacao->updated_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
        ];
    }
}
