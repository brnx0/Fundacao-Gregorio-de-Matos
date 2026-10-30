<?php

namespace App\Support\EditalIa;

use App\Enums\AlcanceCota;
use App\Enums\BaseCota;
use App\Enums\EtapaDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Http\Requests\Retaguarda\SalvarEditalRequest;
use App\Models\Edital;
use App\Models\ImportacaoEdital;
use App\Support\ArquivoArmazenado;
use App\Support\Edital\SincronizadorDoEdital;
use App\Support\SlugUnico;
use App\Support\TextoNormalizado;
use BackedEnum;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Transforma a resposta da IA (no formato de EsquemaDaResposta) num edital novo, não publicado, com o PDF da importação.
 *
 * - Bloco da raiz ausente ou com tipo errado: RespostaInvalida, antes de gravar qualquer coisa.
 * - Item de lista inválido (data impossível, fim antes do início, opção desconhecida, número negativo, fora do
 *   limite ou que não é número, nome vazio): descartado, e vira ponto de atenção "incerta" dizendo o que saiu.
 *   Campo avulso inválido fica vazio, com ponto de atenção.
 * - Referências por nome (categoria do documento e do critério, chamada da categoria): nome
 *   sem par sai, com ponto de atenção; documento ou critério sem nenhuma categoria encontrada vale para todas.
 * - Textos cortados nos limites das colunas, em bytes (VARCHAR2 do Oracle).
 *
 * Preenche a confiabilidade (geral = média arredondada das notas dos blocos) e os pontos de atenção (os da IA e os
 * daqui) na importação, sem salvá-la. Roda dentro da transação de quem chama; se falhar, apaga a cópia do PDF.
 */
class EditalDaResposta
{
    public const string TIPO_INCERTA = 'incerta';

    private const array BLOCOS_OBJETO = ['identificacao', 'inscricao', 'participacao', 'cotas', 'avaliacao', 'informacoes', 'confiabilidade'];

    private const array BLOCOS_LISTA = ['categorias', 'documentos', 'atencao'];

    private const float VALOR_MAXIMO = 10_000_000_000;

    private const int QUANTIDADE_MAXIMA = 1_000_000;

    private const float PONTOS_MAXIMOS = 9999.99;

    private const int TAMANHO_INFORMACAO = 20000;

    /** Tamanho (bytes) das colunas de nome; as referências por nome são cortadas no mesmo tamanho. */
    private const int BYTES_NOME_CHAMADA = 100;

    private const int BYTES_NOME_CATEGORIA = 255;

    private const int BYTES_NOME_DOCUMENTO = 255;

    /** @var list<array{bloco: string, tipo: string, texto: string, itens: list<string>}> */
    private array $avisos = [];

    /**
     * @param  array<mixed>  $dados  A resposta da IA, já decodificada.
     *
     * @throws RespostaInvalida
     */
    public function criar(array $dados, ImportacaoEdital $importacao): Edital
    {
        $this->conferirRaiz($dados);
        $this->avisos = [];

        $edital = new Edital($this->dadosDoEdital($dados));
        $listas = $this->listas($dados);

        $importacao->confiabilidade = $this->confiabilidade($dados['confiabilidade']);
        $importacao->atencao = [...$this->atencaoDaIa($dados['atencao']), ...$this->avisos];

        $edital->titulo ??= 'Edital importado em '.now()->setTimezone(config('app.timezone'))->format('d/m/Y');
        $edital->slug = SlugUnico::para(Edital::class, $edital->titulo);
        $edital->publicado = false;
        $edital->pdf = $this->copiarPdf($importacao);

        try {
            if (! $edital->save()) {
                throw new RuntimeException('Não foi possível salvar o edital importado.');
            }

            (new SincronizadorDoEdital)->sincronizar($edital, $listas);
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagar(ImportacaoEdital::DISCO, $edital->pdf);

            throw $erro;
        }

        return $edital;
    }

    /**
     * Confere os blocos da raiz (presentes e com o tipo certo). Usada também como conferência da AnaliseComRedundancia
     * (AnalisarEditalCommand), para a resposta fora do formato passar ao provedor seguinte.
     *
     * @param  array<mixed>  $dados
     *
     * @throws RespostaInvalida
     */
    public static function conferirRaiz(array $dados): void
    {
        foreach (self::BLOCOS_OBJETO as $bloco) {
            if (! isset($dados[$bloco]) || ! is_array($dados[$bloco])) {
                throw new RespostaInvalida;
            }
        }

        foreach (self::BLOCOS_LISTA as $bloco) {
            if (! isset($dados[$bloco]) || ! is_array($dados[$bloco]) || ! array_is_list($dados[$bloco])) {
                throw new RespostaInvalida;
            }
        }
    }

    private function copiarPdf(ImportacaoEdital $importacao): string
    {
        $destino = 'editais/'.Str::random(40).'.pdf';

        if (! Storage::disk(ImportacaoEdital::DISCO)->copy($importacao->pdf, $destino)) {
            throw new RuntimeException('Não foi possível copiar o PDF da importação para o edital.');
        }

        return $destino;
    }

    /**
     * @param  array<mixed>  $dados
     * @return array<string, mixed>
     */
    private function dadosDoEdital(array $dados): array
    {
        $identificacao = $dados['identificacao'];
        $participacao = $dados['participacao'];
        $avaliacao = $dados['avaliacao'];

        return [
            'titulo' => self::texto($identificacao['titulo'] ?? null, 255),
            'numero' => self::texto($identificacao['numero'] ?? null, 30),
            'tipo' => $this->opcaoAvulsa(TipoEdital::class, $identificacao['tipo'] ?? null, 'identificacao', 'tipo do edital'),
            'objeto' => self::texto($identificacao['objeto'] ?? null, 1000),
            'resumo' => self::textoLongo($identificacao['resumo'] ?? null, 4000),
            'categoria' => self::texto($identificacao['categoria'] ?? null, 255),
            'dom_data' => $this->dataAvulsa($identificacao['dom_data'] ?? null, 'identificacao', 'data de publicação no DOM'),
            'dom_numero' => self::texto($identificacao['dom_numero'] ?? null, 20),
            'forma_inscricao_original' => self::texto($dados['inscricao']['forma_inscricao_original'] ?? null, 1000),
            'naturezas' => $this->naturezasDoEdital($participacao['naturezas'] ?? null),
            'aceita_coletivos' => self::booleano($participacao['aceita_coletivos'] ?? null),
            'inscricoes_por_proponente' => $this->numeroAvulso($participacao['inscricoes_por_proponente'] ?? null, 1, 1000, true, 'participacao', 'inscrições por proponente'),
            'limite_por_chamada' => self::booleano($participacao['limite_por_chamada'] ?? null),
            'cotas' => $this->cotas($dados['cotas']),
            'nota_maxima' => $this->numeroAvulso($avaliacao['nota_maxima'] ?? null, 0, self::QUANTIDADE_MAXIMA, true, 'avaliacao', 'nota máxima'),
            'nota_corte' => $this->numeroAvulso($avaliacao['nota_corte'] ?? null, 0, 999.99, false, 'avaliacao', 'nota de corte'),
            'pontuacao_extra' => $this->pontuacaoExtra($avaliacao['pontuacao_extra'] ?? null),
            'informacoes' => $this->informacoes($dados['informacoes']),
        ];
    }

    /**
     * Listas no formato de SincronizadorDoEdital, com as referências por nome trocadas por posições.
     *
     * @param  array<mixed>  $dados
     * @return array{chamadas: list<array<string, mixed>>, categorias: list<array<string, mixed>>, documentos: list<array<string, mixed>>, criterios: list<array<string, mixed>>}
     */
    private function listas(array $dados): array
    {
        $chamadas = $this->filtrar($dados['inscricao']['chamadas'] ?? [], 'inscricao', 'de "Chamadas"', $this->chamada(...));
        $posicaoDaChamada = self::posicoesPorNome($chamadas, 'nome');

        $categorias = $this->filtrar($dados['categorias'], 'categorias', 'de "Categorias"', $this->categoria(...));
        foreach ($categorias as &$categoria) {
            $nomeDaChamada = $categoria['chamada'];
            $categoria['chamada'] = $nomeDaChamada === null ? null : $posicaoDaChamada[self::chaveDoNome($nomeDaChamada)] ?? null;
            if ($nomeDaChamada !== null && $categoria['chamada'] === null) {
                $this->avisar('categorias', "A categoria \"{$categoria['nome']}\" cita a chamada \"{$nomeDaChamada}\", que não está na lista de chamadas; ficou sem chamada.");
            }
        }
        unset($categoria);
        $posicaoDaCategoria = self::posicoesPorNome($categorias, 'nome');

        $documentos = $this->filtrar($dados['documentos'], 'documentos', 'de "Documentos"', $this->documento(...));
        foreach ($documentos as &$documento) {
            $documento = $this->ligarCategorias($documento, $posicaoDaCategoria, 'documentos', "O documento \"{$documento['nome']}\"");
        }
        unset($documento);
        $posicaoDoDocumento = self::posicoesPorNome($documentos, 'nome');

        $criterios = $this->filtrar($dados['avaliacao']['criterios'] ?? [], 'avaliacao', 'de "Critérios"', $this->criterio(...));
        foreach ($criterios as &$criterio) {
            $criterio = $this->ligarCategorias($criterio, $posicaoDaCategoria, 'avaliacao', "O critério \"{$criterio['nome']}\"");
        }
        unset($criterio);

        return [
            'chamadas' => $chamadas,
            'categorias' => $categorias,
            'documentos' => $documentos,
            'criterios' => $criterios,
        ];
    }

    /**
     * Normaliza cada item; os inválidos saem e viram ponto de atenção. Acima do máximo do formulário, o resto sai.
     *
     * @param  string  $origem  De onde vêm os itens, na frase dos avisos: 'de "Chamadas"', 'da categoria "Teatro"'.
     * @param  callable(array<mixed>, int): (array<string, mixed>|string)  $normalizar  Devolve o item ou o motivo do descarte.
     * @return list<array<string, mixed>>
     */
    private function filtrar(mixed $itens, string $bloco, string $origem, callable $normalizar): array
    {
        if (! is_array($itens)) {
            $this->avisar($bloco, "Os itens {$origem} vieram fora do formato e foram descartados.");

            return [];
        }

        $validos = [];
        foreach (array_values($itens) as $posicao => $item) {
            $resultado = is_array($item) ? $normalizar($item, $posicao) : 'item fora do formato';

            if (is_string($resultado)) {
                $nome = is_array($item) ? self::texto($item['nome'] ?? $item['descricao'] ?? $item['grupo'] ?? null, 200) : null;
                $this->avisar($bloco, sprintf('Item%s %s descartado: %s.', $nome !== null ? " \"{$nome}\"" : '', $origem, $resultado));

                continue;
            }

            if (count($validos) === SalvarEditalRequest::MAXIMO_ITENS) {
                $this->avisar($bloco, sprintf('Só os %d primeiros itens %s foram importados.', SalvarEditalRequest::MAXIMO_ITENS, $origem));

                break;
            }

            $validos[] = $resultado;
        }

        return $validos;
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function chamada(array $item, int $posicao): array|string
    {
        $inicio = self::data($item['inicio'] ?? null);
        $fim = self::data($item['fim'] ?? null);

        if ($inicio === false) {
            return 'data de início impossível';
        }
        if ($fim === false) {
            return 'data de fim impossível';
        }
        if ($inicio !== null && $fim !== null && $fim < $inicio) {
            return 'fim antes do início';
        }

        $nome = self::texto($item['nome'] ?? null, self::BYTES_NOME_CHAMADA) ?? 'Chamada '.($posicao + 1);
        $homologacao = $this->fase($item, 'homologacao', 'homologação', $nome);
        $validacao = $this->fase($item, 'validacao', 'avaliação', $nome);

        return [
            'nome' => $nome,
            'inicio' => $inicio,
            'fim' => $fim,
            'homologacao_inicio' => $homologacao[0],
            'homologacao_fim' => $homologacao[1],
            'validacao_inicio' => $validacao[0],
            'validacao_fim' => $validacao[1],
        ];
    }

    /**
     * Início e fim de uma fase da chamada. Fase inválida (data impossível, só uma das datas, fim antes do início)
     * fica vazia, com ponto de atenção, sem descartar a chamada.
     *
     * @param  array<mixed>  $item
     * @param  string  $prefixo  Prefixo das chaves no item: 'homologacao' ou 'validacao' (avaliação).
     * @param  string  $rotulo  Nome da fase na frase do aviso.
     * @return array{0: string|null, 1: string|null}
     */
    private function fase(array $item, string $prefixo, string $rotulo, string $chamada): array
    {
        $inicio = self::data($item["{$prefixo}_inicio"] ?? null);
        $fim = self::data($item["{$prefixo}_fim"] ?? null);
        $inicial = "A chamada \"{$chamada}\" trouxe";

        $motivo = match (true) {
            $inicio === false || $fim === false => "{$inicial} uma data impossível na {$rotulo}",
            $inicio !== null && $fim !== null && $fim < $inicio => "{$inicial} o fim da {$rotulo} antes do início",
            ($inicio === null) !== ($fim === null) => sprintf(
                '%s só uma das datas da %s (%s)',
                $inicial,
                $rotulo,
                date('d/m/Y', strtotime((string) ($inicio ?? $fim))),
            ),
            default => null,
        };

        if ($motivo === null) {
            return [$inicio, $fim];
        }

        $this->avisar('inscricao', "{$motivo}; a fase ficou vazia.");

        return [null, null];
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function categoria(array $item): array|string
    {
        $nome = self::texto($item['nome'] ?? null, self::BYTES_NOME_CATEGORIA);
        $valor = self::valor($item['valor'] ?? null);
        $vagas = self::quantidade($item['vagas'] ?? null, false);
        $naturezas = self::naturezasDaLista($item['naturezas'] ?? null);

        if ($nome === null) {
            return 'sem nome';
        }
        foreach (['valor' => $valor, 'vagas' => $vagas, 'naturezas' => $naturezas] as $motivo) {
            if (is_string($motivo)) {
                return $motivo;
            }
        }

        $itens = $this->filtrar($item['itens'] ?? [], 'categorias', "da categoria \"{$nome}\"", $this->itemDaCategoria(...));

        return [
            'nome' => $nome,
            'descricao' => self::texto($item['descricao'] ?? null, 2000),
            'valor' => $valor,
            'vagas' => $vagas,
            'naturezas' => $naturezas,
            'chamada' => self::texto($item['chamada'] ?? null, self::BYTES_NOME_CHAMADA),
            'itens' => $itens,
        ];
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function itemDaCategoria(array $item): array|string
    {
        $nome = self::texto($item['nome'] ?? null, 255);
        $valor = self::valor($item['valor'] ?? null);
        $vagas = self::quantidade($item['vagas'] ?? null, false);
        $suplentes = self::quantidade($item['suplentes'] ?? null, true);

        if ($nome === null) {
            return 'sem nome';
        }
        foreach ([$valor, $vagas, $suplentes] as $motivo) {
            if (is_string($motivo)) {
                return $motivo;
            }
        }

        return ['nome' => $nome, 'valor' => $valor, 'vagas' => $vagas, 'suplentes' => $suplentes];
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function documento(array $item): array|string
    {
        $nome = self::texto($item['nome'] ?? null, self::BYTES_NOME_DOCUMENTO);
        $etapa = EtapaDocumento::tryFrom((string) ($item['etapa'] ?? ''));
        $naturezas = self::naturezasDaLista($item['naturezas'] ?? null);
        $modalidade = self::opcao(ModalidadeCota::class, $item['modalidade_cota'] ?? null);

        return match (true) {
            $nome === null => 'sem nome',
            $etapa === null => 'etapa desconhecida',
            is_string($naturezas) => $naturezas,
            $modalidade === false => 'modalidade de cota desconhecida',
            default => [
                'nome' => $nome,
                'orientacao' => self::texto($item['orientacao'] ?? null, 1000),
                'etapa' => $etapa->value,
                'obrigatorio' => self::booleano($item['obrigatorio'] ?? null),
                'naturezas' => $naturezas,
                'categorias' => self::nomes($item['categorias'] ?? null, self::BYTES_NOME_CATEGORIA),
                'modalidade_cota' => $modalidade?->value,
            ],
        };
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function criterio(array $item): array|string
    {
        $nome = self::texto($item['nome'] ?? null, 500);
        $peso = self::pontos($item['peso'] ?? null);
        $pontosMaximos = self::pontos($item['pontos_maximos'] ?? null);

        return match (true) {
            $nome === null => 'sem nome',
            is_string($peso) => $peso,
            is_string($pontosMaximos) => $pontosMaximos,
            default => [
                'nome' => $nome,
                'descricao' => self::texto($item['descricao'] ?? null, 2000),
                'peso' => $peso,
                'pontos_maximos' => $pontosMaximos,
                'categorias' => self::nomes($item['categorias'] ?? null, self::BYTES_NOME_CATEGORIA),
            ],
        };
    }

    /**
     * @param  array<mixed>  $item
     * @return array<string, mixed>|string
     */
    private function grupoDePontuacao(array $item): array|string
    {
        $grupo = self::texto($item['grupo'] ?? null, 255);
        $pontosPorPessoa = self::pontos($item['pontos_por_pessoa'] ?? null);
        $maximo = self::pontos($item['maximo'] ?? null);

        return match (true) {
            $grupo === null => 'sem nome',
            is_string($pontosPorPessoa) => $pontosPorPessoa,
            is_string($maximo) => $maximo,
            default => ['grupo' => $grupo, 'pontos_por_pessoa' => $pontosPorPessoa, 'maximo' => $maximo],
        };
    }

    /**
     * Troca os nomes de categorias do documento ou critério por posições; sem nenhuma encontrada, vale para todas.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $posicaoDaCategoria
     * @return array<string, mixed>
     */
    private function ligarCategorias(array $item, array $posicaoDaCategoria, string $bloco, string $quem): array
    {
        $posicoes = [];
        foreach ($item['categorias'] as $nome) {
            $posicao = $posicaoDaCategoria[self::chaveDoNome($nome)] ?? null;
            if ($posicao === null) {
                $this->avisar($bloco, "{$quem} cita a categoria \"{$nome}\", que não está na lista de categorias.");
            } else {
                $posicoes[] = $posicao;
            }
        }

        if ($posicoes === [] && $item['categorias'] !== []) {
            $this->avisar($bloco, "{$quem} ficou valendo para todas as categorias.");
        }

        $item['todas_categorias'] = $posicoes === [];
        $item['categorias'] = $posicoes;

        return $item;
    }

    /**
     * @param  array<mixed>  $cotas
     * @return array<string, mixed>
     */
    private function cotas(array $cotas): array
    {
        $resultado = [];
        foreach (ModalidadeCota::cases() as $modalidade) {
            $cota = is_array($cotas[$modalidade->value] ?? null) ? $cotas[$modalidade->value] : [];
            $rotulo = mb_strtolower($modalidade->rotulo());
            $resultado[$modalidade->value] = [
                'ativa' => self::booleano($cota['ativa'] ?? null) ?? false,
                'percentual' => $this->numeroAvulso($cota['percentual'] ?? null, 0, 100, false, 'cotas', "percentual da cota de {$rotulo}"),
                'quantidade' => $this->numeroAvulso($cota['quantidade'] ?? null, 0, self::VALOR_MAXIMO, false, 'cotas', "quantidade da cota de {$rotulo}"),
            ];
        }

        return [
            ...$resultado,
            'base' => $this->opcaoAvulsa(BaseCota::class, $cotas['base'] ?? null, 'cotas', 'base da reserva')?->value,
            'alcance' => $this->opcaoAvulsa(AlcanceCota::class, $cotas['alcance'] ?? null, 'cotas', 'alcance da reserva')?->value,
            'veda_negro_indigena' => self::booleano($cotas['veda_negro_indigena'] ?? null),
            'heteroidentificacao' => self::booleano($cotas['heteroidentificacao'] ?? null),
        ];
    }

    /**
     * @return array{grupos: list<array<string, mixed>>, teto: int|float|null, conta_para_corte: ?bool}|null
     */
    private function pontuacaoExtra(mixed $pontuacao): ?array
    {
        if (! is_array($pontuacao)) {
            return null;
        }

        $resultado = [
            'grupos' => $this->filtrar($pontuacao['grupos'] ?? [], 'avaliacao', 'de "Grupos da pontuação extra"', $this->grupoDePontuacao(...)),
            'teto' => $this->numeroAvulso($pontuacao['teto'] ?? null, 0, self::PONTOS_MAXIMOS, false, 'avaliacao', 'teto da pontuação extra'),
            'conta_para_corte' => self::booleano($pontuacao['conta_para_corte'] ?? null),
        ];

        return $resultado['grupos'] === [] && $resultado['teto'] === null && $resultado['conta_para_corte'] === null
            ? null
            : $resultado;
    }

    /**
     * Só as seções conhecidas e preenchidas; nenhuma = nulo.
     *
     * @param  array<mixed>  $informacoes
     * @return array<string, string>|null
     */
    private function informacoes(array $informacoes): ?array
    {
        $preenchidas = [];
        foreach (array_keys(Edital::SECOES_INFORMATIVAS) as $chave) {
            $texto = self::textoLongo($informacoes[$chave] ?? null, self::TAMANHO_INFORMACAO);
            if ($texto !== null) {
                $preenchidas[$chave] = $texto;
            }
        }

        return $preenchidas === [] ? null : $preenchidas;
    }

    /**
     * @return list<NaturezaProponente>|null
     */
    private function naturezasDoEdital(mixed $valores): ?array
    {
        $naturezas = [];
        foreach (is_array($valores) ? $valores : [] as $valor) {
            $natureza = NaturezaProponente::tryFrom((string) (is_scalar($valor) ? $valor : ''));
            if ($natureza === null) {
                $this->avisar('participacao', 'Natureza desconhecida descartada: "'.self::descrever($valor).'".');
            } elseif (! in_array($natureza, $naturezas, true)) {
                $naturezas[] = $natureza;
            }
        }

        return $naturezas === [] ? null : $naturezas;
    }

    /**
     * Confiabilidade por bloco (nota de 0 a 100) e geral = média arredondada das notas recebidas (nula sem nenhuma).
     *
     * @param  array<mixed>  $recebida
     * @return array{geral: ?int, blocos: array<string, array{nota: int, justificativa: string}>}
     */
    private function confiabilidade(array $recebida): array
    {
        $blocos = [];
        foreach (array_keys(EsquemaDaResposta::BLOCOS) as $bloco) {
            $avaliacao = $recebida[$bloco] ?? null;
            if (! is_array($avaliacao) || ! is_numeric($avaliacao['nota'] ?? null)) {
                continue;
            }

            $blocos[$bloco] = [
                'nota' => max(0, min(100, (int) round((float) $avaliacao['nota']))),
                'justificativa' => self::texto($avaliacao['justificativa'] ?? null, 1000) ?? '',
            ];
        }

        $notas = array_column($blocos, 'nota');

        return [
            'geral' => $notas === [] ? null : (int) round(array_sum($notas) / count($notas)),
            'blocos' => $blocos,
        ];
    }

    /**
     * Pontos de atenção da IA: sem texto saem; tipo desconhecido vira "incerta"; bloco desconhecido fica nulo.
     *
     * @param  list<mixed>  $atencao
     * @return list<array{bloco: ?string, tipo: string, texto: string, itens: list<string>}>
     */
    private function atencaoDaIa(array $atencao): array
    {
        $pontos = [];
        foreach ($atencao as $ponto) {
            $texto = is_array($ponto) ? self::textoLongo($ponto['texto'] ?? null, 2000) : null;
            if ($texto === null) {
                continue;
            }

            $bloco = is_string($ponto['bloco'] ?? null) ? $ponto['bloco'] : '';
            $tipo = is_string($ponto['tipo'] ?? null) ? $ponto['tipo'] : '';

            $pontos[] = [
                'bloco' => array_key_exists($bloco, EsquemaDaResposta::BLOCOS) ? $bloco : null,
                'tipo' => array_key_exists($tipo, EsquemaDaResposta::TIPOS_ATENCAO) ? $tipo : self::TIPO_INCERTA,
                'texto' => $texto,
                'itens' => array_slice(array_map(fn (string $item): string => mb_substr($item, 0, 255), self::nomes($ponto['itens'] ?? null, 500)), 0, 20),
            ];
        }

        return $pontos;
    }

    private function avisar(string $bloco, string $texto): void
    {
        $this->avisos[] = ['bloco' => $bloco, 'tipo' => self::TIPO_INCERTA, 'texto' => $texto, 'itens' => []];
    }

    /**
     * Opção de um campo avulso; desconhecida = nula, com ponto de atenção.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    private function opcaoAvulsa(string $enum, mixed $valor, string $bloco, string $rotulo): ?BackedEnum
    {
        $opcao = self::opcao($enum, $valor);
        if ($opcao === false) {
            $this->avisar($bloco, "Valor desconhecido em \"{$rotulo}\" descartado: \"".self::descrever($valor).'".');

            return null;
        }

        return $opcao;
    }

    private function dataAvulsa(mixed $valor, string $bloco, string $rotulo): ?string
    {
        $data = self::data($valor);
        if ($data === false) {
            $this->avisar($bloco, "Data impossível em \"{$rotulo}\" descartada: \"".self::descrever($valor).'".');

            return null;
        }

        return $data;
    }

    /** Número de um campo avulso entre $minimo e $maximo; fora disso (ou não numérico) fica nulo, com ponto de atenção. */
    private function numeroAvulso(mixed $valor, int|float $minimo, int|float $maximo, bool $inteiro, string $bloco, string $rotulo): int|float|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $numero = is_numeric($valor) ? $valor + 0 : null;
        if ($numero === null || $numero < $minimo || $numero > $maximo || ($inteiro && floor($numero) != $numero)) {
            $this->avisar($bloco, "Valor inválido em \"{$rotulo}\" descartado: \"".self::descrever($valor).'".');

            return null;
        }

        return $inteiro ? (int) $numero : $numero;
    }

    /**
     * Valor em reais: negativo, acima do limite ou não numérico = motivo do descarte; zero = sem valor.
     */
    private static function valor(mixed $valor): int|float|string|null
    {
        return match (true) {
            $valor === null || $valor === '' => null,
            ! is_numeric($valor) => 'valor que não é número',
            $valor < 0 => 'valor negativo',
            $valor > self::VALOR_MAXIMO => 'valor acima do limite',
            $valor == 0 => null,
            default => $valor + 0,
        };
    }

    /**
     * Vagas ou suplentes: inteiro não negativo até o limite; vagas zero = sem vagas definidas.
     */
    private static function quantidade(mixed $valor, bool $aceitaZero): int|string|null
    {
        return match (true) {
            $valor === null || $valor === '' => null,
            ! is_numeric($valor) || floor((float) $valor) != $valor => 'quantidade que não é número inteiro',
            $valor < 0 => 'quantidade negativa',
            $valor > self::QUANTIDADE_MAXIMA => 'quantidade acima do limite',
            $valor == 0 && ! $aceitaZero => null,
            default => (int) $valor,
        };
    }

    /** Peso ou pontos (coluna decimal 6,2). */
    private static function pontos(mixed $valor): int|float|string|null
    {
        return match (true) {
            $valor === null || $valor === '' => null,
            ! is_numeric($valor) => 'pontuação que não é número',
            $valor < 0 => 'pontuação negativa',
            $valor > self::PONTOS_MAXIMOS => 'pontuação acima do limite',
            default => $valor + 0,
        };
    }

    /**
     * Naturezas de um item da lista: alguma desconhecida = motivo do descarte; nenhuma = nulo (as do edital).
     *
     * @return list<string>|string|null
     */
    private static function naturezasDaLista(mixed $valores): array|string|null
    {
        if ($valores === null) {
            return null;
        }
        if (! is_array($valores)) {
            return 'naturezas fora do formato';
        }

        $naturezas = [];
        foreach ($valores as $valor) {
            $natureza = NaturezaProponente::tryFrom((string) (is_scalar($valor) ? $valor : ''));
            if ($natureza === null) {
                return 'natureza desconhecida "'.self::descrever($valor).'"';
            }
            $naturezas[] = $natureza->value;
        }

        return $naturezas === [] ? null : array_values(array_unique($naturezas));
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|false|null Nulo quando vazio; falso quando desconhecido.
     */
    private static function opcao(string $enum, mixed $valor): BackedEnum|false|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return (is_string($valor) ? $enum::tryFrom($valor) : null) ?? false;
    }

    /** Data AAAA-MM-DD; nulo quando vazia, falso quando impossível ou em outro formato. */
    private static function data(mixed $valor): string|false|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($valor), $partes) !== 1) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? trim($valor) : false;
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

    /** Texto de coluna CLOB, cortado em $caracteres; vazio = nulo. */
    private static function textoLongo(mixed $valor, int $caracteres): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : mb_substr($texto, 0, $caracteres);
    }

    private static function booleano(mixed $valor): ?bool
    {
        return is_bool($valor) ? $valor : null;
    }

    /**
     * Lista de nomes (textos não vazios); fora do formato = vazia. Referências a outra lista são cortadas no mesmo
     * tamanho do nome citado, para nomes longos continuarem se encontrando.
     *
     * @return list<string>
     */
    private static function nomes(mixed $valores, int $bytes): array
    {
        $nomes = [];
        foreach (is_array($valores) ? $valores : [] as $valor) {
            $nome = self::texto($valor, $bytes);
            if ($nome !== null) {
                $nomes[] = $nome;
            }
        }

        return $nomes;
    }

    /**
     * Posição de cada item pelo nome (o primeiro, quando há repetidos).
     *
     * @param  list<array<string, mixed>>  $itens
     * @return array<string, int>
     */
    private static function posicoesPorNome(array $itens, string $campo): array
    {
        $posicoes = [];
        foreach ($itens as $posicao => $item) {
            $posicoes[self::chaveDoNome($item[$campo])] ??= $posicao;
        }

        return $posicoes;
    }

    /** Nome para comparação: sem acento, sem diferença de caixa e de espaços. */
    public static function chaveDoNome(string $nome): string
    {
        return (string) preg_replace('/\s+/u', ' ', TextoNormalizado::semAcento($nome));
    }

    private static function descrever(mixed $valor): string
    {
        return is_scalar($valor) ? mb_substr((string) $valor, 0, 100) : 'valor fora do formato';
    }
}
