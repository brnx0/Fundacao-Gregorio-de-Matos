<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\AlcanceCota;
use App\Enums\BaseCota;
use App\Enums\ChecagemDocumento;
use App\Enums\EtapaDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\SituacaoEdital;
use App\Enums\SituacaoImportacao;
use App\Enums\TipoEdital;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Retaguarda\Concerns\FormatoDoFormulario;
use App\Http\Requests\Retaguarda\SalvarEditalRequest;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use App\Models\EditalChamada;
use App\Models\EditalCriterio;
use App\Models\EditalDocumento;
use App\Models\ImportacaoEdital;
use App\Support\ArquivoArmazenado;
use App\Support\Edital\SincronizadorDoEdital;
use App\Support\EditalIa\EsquemaDaResposta;
use App\Support\SlugUnico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Gestão de editais na retaguarda. PDF no disco local (privado), imagem no public, ambos em editais/;
 * listas e arquivos-modelo dos documentos gravados por SincronizadorDoEdital. O questionário tem a sua própria tela
 * (QuestionarioDoEditalController), aberta pelo ícone da lista.
 */
class EditalController extends Controller
{
    use FormatoDoFormulario;

    private const string PASTA = 'editais';

    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('busca', ''));
        $situacao = SituacaoEdital::tryFrom((string) $request->query('situacao', ''));
        $publicado = in_array($request->query('publicado'), ['sim', 'nao'], true) ? $request->query('publicado') : null;

        $editais = Edital::query()
            ->paraListagem()
            ->withCount(['categorias', 'perguntas'])
            // No Oracle a comparação de texto diferencia maiúsculas; % e _ digitados valem literalmente.
            ->when($busca !== '', fn (Builder $q) => $q->whereRaw(
                "LOWER(titulo) LIKE ? ESCAPE '\\'",
                ['%'.addcslashes(mb_strtolower($busca), '\\%_').'%'],
            ))
            ->when($situacao, fn (Builder $q) => $q->naSituacao($situacao))
            ->when($publicado, fn (Builder $q) => $q->where('publicado', $publicado === 'sim'))
            ->orderByDesc('inscricoes_inicio')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Edital $edital): array {
                $situacao = $edital->situacao();

                return [
                    'slug' => $edital->slug,
                    'titulo' => $edital->titulo,
                    'categoria' => $edital->categoria,
                    // Rascunho sem datas: sem período nem situação.
                    'periodo' => $edital->temPeriodo()
                        ? $edital->inscricoes_inicio->format('d/m/Y').' a '.$edital->inscricoes_fim->format('d/m/Y')
                        : null,
                    'situacao' => $situacao?->value,
                    'situacaoRotulo' => $situacao?->rotulo(),
                    'publicado' => $edital->publicado,
                    'temPdf' => filled($edital->pdf),
                    // O ícone do questionário só aparece com categoria; a cor diz se já há perguntas.
                    'temCategorias' => (int) $edital->categorias_count > 0,
                    'perguntas' => (int) $edital->perguntas_count,
                ];
            });

        $configuracaoIa = ConfiguracaoIa::atual();

        return Inertia::render('Retaguarda/Editais/Index', [
            'editais' => $editais,
            // "Importar com IA" aparece sempre; sem provedor, a janela já diz onde configurar.
            'importacaoConfigurada' => $configuracaoIa->provedoresDaImportacao() !== [],
            'tamanhoMaximoPdfMb' => $configuracaoIa->tamanhoMaximoPdfMb(),
            'importacoes' => $this->importacoesRecentes(),
            'filtros' => [
                'busca' => $busca,
                'situacao' => $situacao?->value ?? '',
                'publicado' => $publicado ?? '',
            ],
            'situacoes' => array_map(
                fn (SituacaoEdital $s): array => ['valor' => $s->value, 'rotulo' => $s->rotulo()],
                SituacaoEdital::cases(),
            ),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Retaguarda/Editais/Form', [
            'edital' => null,
            'pendencias' => [],
            'importacao' => null,
            'somenteLeitura' => false,
            ...$this->opcoesDoFormulario(),
        ]);
    }

    /** Sem título (rascunho), o endereço vem de "edital"; depois de criado, não muda mais. */
    public function store(SalvarEditalRequest $request): RedirectResponse
    {
        $edital = new Edital($request->dadosDoEdital());
        $edital->slug = SlugUnico::para(Edital::class, $edital->titulo ?? 'edital');

        $this->salvar($request, $edital);
        [$tipo, $mensagem] = $this->mensagemDoSalvo($edital);

        return redirect()->route('retaguarda.editais.index')->with($tipo, $mensagem);
    }

    /** O mesmo formulário, só para ler (ícone Visualizar da lista): mostra tudo, sem editar nem salvar. */
    public function show(Edital $edital): Response
    {
        return $this->formulario($edital, somenteLeitura: true);
    }

    public function edit(Edital $edital): Response
    {
        return $this->formulario($edital, somenteLeitura: false);
    }

    private function formulario(Edital $edital, bool $somenteLeitura): Response
    {
        $edital->load(['chamadas', 'categorias.itens', 'documentos.categorias', 'criterios.categorias']);

        // Referências entre listas vão por posição, como o formulário as envia de volta.
        $posicaoDaChamada = $this->posicoesPorId($edital->chamadas);
        $posicaoDaCategoria = $this->posicoesPorId($edital->categorias);

        return Inertia::render('Retaguarda/Editais/Form', [
            'edital' => [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'resumo' => $edital->resumo,
                'categoria' => $edital->categoria,
                'inscricoes_inicio' => $edital->inscricoes_inicio?->toDateString(),
                'inscricoes_fim' => $edital->inscricoes_fim?->toDateString(),
                'publicado' => $edital->publicado,
                'pdfUrl' => $edital->pdf ? route('retaguarda.editais.pdf', $edital) : null,
                'pdfNome' => $edital->pdf ? $this->nomeDoPdf($edital) : null,
                'imagemUrl' => $edital->imagemUrl(),
                'numero' => $edital->numero,
                'tipo' => $edital->tipo?->value,
                'objeto' => $edital->objeto,
                'dom_data' => $edital->dom_data?->toDateString(),
                'dom_numero' => $edital->dom_numero,
                'naturezas' => $this->valoresDasNaturezas($edital->naturezas),
                'aceita_coletivos' => $edital->aceita_coletivos,
                'inscricoes_por_proponente' => $edital->inscricoes_por_proponente,
                'limite_por_chamada' => $edital->limite_por_chamada,
                'forma_inscricao_original' => $edital->forma_inscricao_original,
                'nota_maxima' => $edital->nota_maxima,
                'nota_corte' => $edital->nota_corte !== null ? (float) $edital->nota_corte : null,
                'cotas' => $edital->cotas,
                'pontuacao_extra' => $edital->pontuacao_extra,
                'informacoes' => array_map(
                    fn (string $chave): ?string => $edital->informacoes[$chave] ?? null,
                    array_combine(array_keys(Edital::SECOES_INFORMATIVAS), array_keys(Edital::SECOES_INFORMATIVAS)),
                ),
                'chamadas' => $edital->chamadas->map(fn (EditalChamada $chamada): array => [
                    'id' => (int) $chamada->id,
                    'nome' => $chamada->nome,
                    'inicio' => $chamada->inicio?->toDateString(),
                    'fim' => $chamada->fim?->toDateString(),
                    'validacao_inicio' => $chamada->validacao_inicio?->toDateString(),
                    'validacao_fim' => $chamada->validacao_fim?->toDateString(),
                    'homologacao_inicio' => $chamada->homologacao_inicio?->toDateString(),
                    'homologacao_fim' => $chamada->homologacao_fim?->toDateString(),
                ])->all(),
                'categorias' => $edital->categorias->map(fn (EditalCategoria $categoria): array => [
                    'id' => (int) $categoria->id,
                    'nome' => $categoria->nome,
                    'descricao' => $categoria->descricao,
                    'valor' => $this->reais($categoria->valor_centavos),
                    'vagas' => $categoria->vagas,
                    'naturezas' => $this->valoresDasNaturezas($categoria->naturezas),
                    'chamada' => $categoria->edital_chamada_id !== null ? ($posicaoDaChamada[(int) $categoria->edital_chamada_id] ?? null) : null,
                    'itens' => $categoria->itens->map(fn (EditalCategoriaItem $item): array => [
                        'id' => (int) $item->id,
                        'nome' => $item->nome,
                        'valor' => $this->reais($item->valor_centavos),
                        'vagas' => $item->vagas,
                        'suplentes' => $item->suplentes,
                    ])->all(),
                ])->all(),
                'documentos' => $edital->documentos->map(fn (EditalDocumento $documento): array => [
                    'id' => (int) $documento->id,
                    'nome' => $documento->nome,
                    'orientacao' => $documento->orientacao,
                    'etapa' => $documento->etapa->value,
                    'obrigatorio' => $documento->obrigatorio,
                    'naturezas' => $this->valoresDasNaturezas($documento->naturezas),
                    'todas_categorias' => $documento->todas_categorias,
                    'categorias' => $this->posicoesDe($documento->categorias, $posicaoDaCategoria),
                    'modalidade_cota' => $documento->modalidade_cota?->value,
                    'checagem' => $documento->checagem->value,
                    'instrucao_ia' => $documento->instrucao_ia,
                    'modeloUrl' => $documento->modelo ? route('retaguarda.editais.documentos.modelo', [$edital, $documento]) : null,
                    'modeloNome' => $documento->modelo ? $documento->modelo_nome : null,
                ])->all(),
                'criterios' => $edital->criterios->map(fn (EditalCriterio $criterio): array => [
                    'id' => (int) $criterio->id,
                    'nome' => $criterio->nome,
                    'descricao' => $criterio->descricao,
                    'peso' => $criterio->peso !== null ? (float) $criterio->peso : null,
                    'pontos_maximos' => $criterio->pontos_maximos !== null ? (float) $criterio->pontos_maximos : null,
                    'todas_categorias' => $criterio->todas_categorias,
                    'categorias' => $this->posicoesDe($criterio->categorias, $posicaoDaCategoria),
                ])->all(),
            ],
            'pendencias' => $edital->pendenciasParaPublicar(),
            // Conferir a importação é de quem edita: só para ler, o quadro não aparece.
            'importacao' => $somenteLeitura ? null : $this->importacaoNaoConferida($edital),
            'somenteLeitura' => $somenteLeitura,
            ...$this->opcoesDoFormulario(),
        ]);
    }

    /** O slug não muda: links já divulgados continuam valendo. */
    public function update(SalvarEditalRequest $request, Edital $edital): RedirectResponse
    {
        $edital->fill($request->dadosDoEdital());

        $this->salvar($request, $edital, $request->boolean('remover_imagem'));
        [$tipo, $mensagem] = $this->mensagemDoSalvo($edital);

        return redirect()->route('retaguarda.editais.index')->with($tipo, $mensagem);
    }

    /**
     * Rascunho salva incompleto (a importação com IA também cria assim), mas avisa o que ainda falta para publicar.
     *
     * @return array{0: string, 1: string}
     */
    private function mensagemDoSalvo(Edital $edital): array
    {
        $pendencias = $edital->publicado ? [] : $edital->pendenciasParaPublicar();

        return $pendencias === []
            ? ['sucesso', 'Edital salvo.']
            : ['aviso', 'Rascunho salvo. Para publicar, ainda falta: '.implode(' ', $pendencias)];
    }

    /** Exclusão lógica: os arquivos ficam no disco. */
    public function destroy(Edital $edital): RedirectResponse
    {
        $edital->delete();

        return redirect()->route('retaguarda.editais.index')->with('sucesso', 'Edital excluído.');
    }

    public function publicacao(Edital $edital): RedirectResponse
    {
        if (! $edital->publicado) {
            $pendencias = $edital->pendenciasParaPublicar();

            if ($pendencias !== []) {
                return back()->with('erro', implode(' ', $pendencias));
            }
        }

        $edital->update(['publicado' => ! $edital->publicado]);

        return back()->with('sucesso', $edital->publicado ? 'Edital publicado.' : 'Edital despublicado.');
    }

    /** PDF para conferência do gestor, publicado ou não (a rota do portal só entrega os visíveis). */
    public function pdf(Edital $edital): StreamedResponse
    {
        abort_unless(filled($edital->pdf) && Storage::disk('local')->exists($edital->pdf), 404);

        return Storage::disk('local')->download($edital->pdf, $this->nomeDoPdf($edital));
    }

    /** Arquivo-modelo de um documento do edital, publicado ou não. */
    public function modelo(Edital $edital, EditalDocumento $documento): StreamedResponse
    {
        $disco = Storage::disk(SincronizadorDoEdital::DISCO_MODELOS);
        abort_unless(filled($documento->modelo) && $disco->exists($documento->modelo), 404);

        return $disco->download($documento->modelo, $documento->modelo_nome ?: basename($documento->modelo));
    }

    /**
     * Grava o edital, os arquivos e (com o marcador) as listas numa transação. Arquivos novos são apagados
     * se algo falhar; os substituídos, só depois do commit. Publicado precisa do edital completo: o
     * SalvarEditalRequest já confere o envio, e aqui se confere o que ficou gravado, cada pendência no campo da aba
     * (o questionário não vem daqui, mas apagar uma categoria pode deixar pergunta dele sem vínculo).
     */
    private function salvar(SalvarEditalRequest $request, Edital $edital, bool $removerImagem = false): void
    {
        $sincronizador = new SincronizadorDoEdital;
        $novos = [];
        $antigos = [];

        try {
            DB::transaction(function () use ($request, $edital, $removerImagem, $sincronizador, &$novos, &$antigos): void {
                if ($request->hasFile('pdf')) {
                    $antigos[] = ['local', $edital->pdf];
                    $edital->pdf = ArquivoArmazenado::guardar($request->file('pdf'), 'local', self::PASTA);
                    $novos[] = ['local', $edital->pdf];
                }

                if ($request->hasFile('imagem')) {
                    $antigos[] = ['public', $edital->imagem];
                    $edital->imagem = ArquivoArmazenado::guardar($request->file('imagem'), 'public', self::PASTA);
                    $novos[] = ['public', $edital->imagem];
                } elseif ($removerImagem && $edital->imagem) {
                    $antigos[] = ['public', $edital->imagem];
                    $edital->imagem = null;
                }

                if (! $edital->save()) {
                    throw new RuntimeException('Não foi possível salvar o edital.');
                }

                if ($request->comListas()) {
                    $sincronizador->sincronizar($edital, $request->listasDoEdital());
                }

                // Pergunta sem vínculo também aparece quando uma categoria é apagada. Com o questionário travado a equipe
                // não teria como vincular as perguntas: isso não barra.
                $pendencias = $edital->publicado ? $edital->pendenciasPorCampo() : [];
                if (isset($pendencias['questionario']) && $edital->questionarioTravado()) {
                    unset($pendencias['questionario']);
                }
                if ($pendencias !== []) {
                    throw ValidationException::withMessages($pendencias);
                }
            });
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagarTodos([...$novos, ...$sincronizador->arquivosNovos()]);

            throw $erro;
        }

        ArquivoArmazenado::apagarTodos([...$antigos, ...$sincronizador->arquivosSubstituidos()]);
    }

    /**
     * Painel da lista: as importações analisando ou com erro e as concluídas nas últimas 24 h (sem as colunas CLOB).
     *
     * @return list<array{id: int, pdfNome: string, situacao: string, erro: ?string, editalUrl: ?string, enviadaEm: string}>
     */
    private function importacoesRecentes(): array
    {
        return ImportacaoEdital::query()
            ->select(['id', 'edital_id', 'situacao', 'erro', 'pdf_nome', 'iniciada_em', 'created_at'])
            ->with('edital:id,slug')
            ->where(fn (Builder $q) => $q
                ->where('situacao', '!=', SituacaoImportacao::Concluida->value)
                ->orWhere('updated_at', '>=', now()->subDay()))
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (ImportacaoEdital $importacao): array => [
                'id' => (int) $importacao->id,
                'pdfNome' => $importacao->pdf_nome,
                'situacao' => $importacao->situacaoExibida()->value,
                'erro' => $importacao->erroExibido(),
                'editalUrl' => $importacao->edital !== null ? route('retaguarda.editais.edit', $importacao->edital) : null,
                'enviadaEm' => $importacao->created_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /**
     * Quadro "Importado pela IA" do formulário, enquanto ninguém marcou a importação como conferida.
     *
     * @return array{
     *     confiabilidade: array{geral: ?int, blocos: list<array{bloco: string, rotulo: string, nota: int, justificativa: string}>},
     *     atencao: list<array{bloco: ?string, blocoRotulo: string, tipo: string, tipoRotulo: string, texto: string, itens: list<string>}>,
     *     conferida: bool, modelo: string, tokensTotal: ?int, importadaEm: string
     * }|null
     */
    private function importacaoNaoConferida(Edital $edital): ?array
    {
        $importacao = ImportacaoEdital::query()
            ->select(['id', 'confiabilidade', 'atencao', 'conferida_em', 'modelo', 'tokens_total', 'updated_at'])
            ->where('edital_id', $edital->getKey())
            ->whereNull('conferida_em')
            ->latest('id')
            ->first();

        if ($importacao === null) {
            return null;
        }

        $notas = $importacao->confiabilidade['blocos'] ?? [];

        return [
            'confiabilidade' => [
                'geral' => $importacao->confiabilidade['geral'] ?? null,
                // Na ordem dos blocos do formulário, só os que receberam nota.
                'blocos' => collect(EsquemaDaResposta::BLOCOS)
                    ->filter(fn (string $rotulo, string $bloco): bool => isset($notas[$bloco]))
                    ->map(fn (string $rotulo, string $bloco): array => [
                        'bloco' => $bloco,
                        'rotulo' => $rotulo,
                        'nota' => (int) $notas[$bloco]['nota'],
                        'justificativa' => (string) ($notas[$bloco]['justificativa'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
            'atencao' => array_map(fn (array $ponto): array => [
                'bloco' => array_key_exists($ponto['bloco'] ?? '', EsquemaDaResposta::BLOCOS) ? $ponto['bloco'] : null,
                'blocoRotulo' => EsquemaDaResposta::BLOCOS[$ponto['bloco'] ?? ''] ?? 'Geral',
                'tipo' => $ponto['tipo'],
                'tipoRotulo' => EsquemaDaResposta::TIPOS_ATENCAO[$ponto['tipo']] ?? $ponto['tipo'],
                'texto' => $ponto['texto'],
                'itens' => $ponto['itens'] ?? [],
            ], $importacao->atencao ?? []),
            'conferida' => $importacao->conferida_em !== null,
            'modelo' => $importacao->modelo,
            'tokensTotal' => $importacao->tokens_total,
            'importadaEm' => $importacao->updated_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i'),
        ];
    }

    /**
     * Opções dos campos de escolha do formulário.
     *
     * @return array<string, list<array{valor: string, rotulo: string}>>
     */
    private function opcoesDoFormulario(): array
    {
        return [
            'naturezas' => $this->opcoesDe(NaturezaProponente::class),
            'etapas' => $this->opcoesDe(EtapaDocumento::class),
            'modalidadesCota' => $this->opcoesDe(ModalidadeCota::class),
            'checagensDocumento' => $this->opcoesDe(ChecagemDocumento::class),
            'basesCota' => $this->opcoesDe(BaseCota::class),
            'alcancesCota' => $this->opcoesDe(AlcanceCota::class),
            'tiposEdital' => $this->opcoesDe(TipoEdital::class),
            'secoesInformativas' => array_map(
                fn (string $chave, string $rotulo): array => ['valor' => $chave, 'rotulo' => $rotulo],
                array_keys(Edital::SECOES_INFORMATIVAS),
                Edital::SECOES_INFORMATIVAS,
            ),
        ];
    }

    private function reais(?int $centavos): ?float
    {
        return $centavos !== null ? $centavos / 100 : null;
    }

    private function nomeDoPdf(Edital $edital): string
    {
        return "edital-{$edital->slug}.pdf";
    }
}
