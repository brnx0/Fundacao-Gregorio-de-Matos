<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\AlcanceCota;
use App\Enums\BaseCota;
use App\Enums\ChecagemDocumento;
use App\Enums\EtapaDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Models\Edital;
use App\Models\ImportacaoQuestionario;
use App\Rules\TamanhoEmBytes;
use App\Support\Edital\SincronizadorDoEdital;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Criação e edição de edital na retaguarda. Tudo é opcional no rascunho; publicado exige título, resumo e categoria
 * (e, ao passar a publicado, o edital completo — ver EditalController).
 * Sem novo upload, o PDF, a imagem e os arquivos-modelo atuais ficam.
 * As listas (chamadas, categorias, documentos, critérios) só são tocadas com o marcador `com_listas`;
 * com ele, lista ausente = apagar todas. Referências entre listas vão por posição (índice na lista enviada).
 * O questionário só é tocado com o marcador `com_questionario`; vai num campo só (`questionario`, texto JSON) porque
 * o formulário envia multipart e o questionário passa de mil campos (ver RegrasDoQuestionario).
 */
class SalvarEditalRequest extends FormRequest
{
    public const int MAXIMO_ITENS = 50;

    public function authorize(): bool
    {
        return true;
    }

    /** JSON inválido fica como texto e cai na regra `array` do campo. */
    protected function prepareForValidation(): void
    {
        $questionario = $this->input('questionario');

        if ($this->boolean('com_questionario') && is_string($questionario)) {
            $decodificado = json_decode($questionario, true);

            if (is_array($decodificado)) {
                $this->merge(['questionario' => $decodificado]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $lista = ['nullable', 'array', 'list', 'max:'.self::MAXIMO_ITENS];
        $natureza = Rule::enum(NaturezaProponente::class);
        $posicaoDeChamada = ['nullable', 'integer', Rule::in($this->posicoes('chamadas'))];
        $posicaoDeCategoria = ['integer', Rule::in($this->posicoes('categorias'))];

        return [
            'com_listas' => ['boolean'],
            'com_questionario' => ['boolean'],
            'questionario_versao' => ['nullable', 'integer'],

            'titulo' => ['required_if_accepted:publicado', 'nullable', 'string', 'max:255', new TamanhoEmBytes(255)],
            'resumo' => ['required_if_accepted:publicado', 'nullable', 'string'],
            'categoria' => ['required_if_accepted:publicado', 'nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:30', new TamanhoEmBytes(30)],
            'tipo' => ['nullable', Rule::enum(TipoEdital::class)],
            'objeto' => ['nullable', 'string', 'max:1000', new TamanhoEmBytes(1000)],
            'dom_data' => ['nullable', 'date'],
            'dom_numero' => ['nullable', 'string', 'max:20', new TamanhoEmBytes(20)],
            'naturezas' => ['nullable', 'array'],
            'naturezas.*' => ['distinct', $natureza],
            'aceita_coletivos' => ['nullable', 'boolean'],
            'inscricoes_por_proponente' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limite_por_chamada' => ['nullable', 'boolean'],
            'forma_inscricao_original' => ['nullable', 'string', 'max:1000', new TamanhoEmBytes(1000)],
            'nota_maxima' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'nota_corte' => ['nullable', 'numeric', 'min:0', 'max:999.99'],

            'imagem' => ['nullable', 'image', 'mimes:jpg,png,webp', 'max:5120'],
            // mimetypes confere o conteúdo do arquivo, não só a extensão.
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:20480'],
            'publicado' => ['boolean'],
            'remover_imagem' => ['boolean'],

            'chamadas' => $lista,
            'chamadas.*.id' => ['nullable', 'integer'],
            'chamadas.*.nome' => ['required', 'string', 'max:100', new TamanhoEmBytes(100)],
            'chamadas.*.inicio' => ['required', 'date'],
            'chamadas.*.fim' => ['required', 'date', 'after_or_equal:chamadas.*.inicio'],
            'chamadas.*.validacao_inicio' => ['nullable', 'date', 'required_with:chamadas.*.validacao_fim'],
            'chamadas.*.validacao_fim' => ['nullable', 'date', 'required_with:chamadas.*.validacao_inicio', 'after_or_equal:chamadas.*.validacao_inicio'],
            'chamadas.*.homologacao_inicio' => ['nullable', 'date', 'required_with:chamadas.*.homologacao_fim'],
            'chamadas.*.homologacao_fim' => ['nullable', 'date', 'required_with:chamadas.*.homologacao_inicio', 'after_or_equal:chamadas.*.homologacao_inicio'],

            'categorias' => $lista,
            'categorias.*.id' => ['nullable', 'integer'],
            'categorias.*.nome' => ['required', 'string', 'max:255', new TamanhoEmBytes(255)],
            'categorias.*.descricao' => ['nullable', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            'categorias.*.valor' => ['nullable', 'numeric', 'min:0.01', 'max:10000000000'],
            'categorias.*.vagas' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'categorias.*.naturezas' => ['nullable', 'array'],
            'categorias.*.naturezas.*' => [$natureza],
            'categorias.*.chamada' => $posicaoDeChamada,
            'categorias.*.itens' => $lista,
            'categorias.*.itens.*.id' => ['nullable', 'integer'],
            'categorias.*.itens.*.nome' => ['required', 'string', 'max:255', new TamanhoEmBytes(255)],
            'categorias.*.itens.*.valor' => ['nullable', 'numeric', 'min:0.01', 'max:10000000000'],
            'categorias.*.itens.*.vagas' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'categorias.*.itens.*.suplentes' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'documentos' => $lista,
            'documentos.*.id' => ['nullable', 'integer'],
            'documentos.*.nome' => ['required', 'string', 'max:255', new TamanhoEmBytes(255)],
            'documentos.*.orientacao' => ['nullable', 'string', 'max:1000', new TamanhoEmBytes(1000)],
            'documentos.*.etapa' => ['required', Rule::enum(EtapaDocumento::class)],
            'documentos.*.obrigatorio' => ['boolean'],
            'documentos.*.naturezas' => ['nullable', 'array'],
            'documentos.*.naturezas.*' => [$natureza],
            'documentos.*.todas_categorias' => ['boolean'],
            'documentos.*.categorias' => ['required_if_declined:documentos.*.todas_categorias', 'nullable', 'array'],
            'documentos.*.categorias.*' => $posicaoDeCategoria,
            'documentos.*.modalidade_cota' => ['nullable', Rule::enum(ModalidadeCota::class)],
            'documentos.*.checagem' => ['required', Rule::enum(ChecagemDocumento::class)],
            'documentos.*.instrucao_ia' => ['nullable', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            'documentos.*.modelo' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,odt,ods', 'max:10240'],
            'documentos.*.remover_modelo' => ['boolean'],

            'criterios' => $lista,
            'criterios.*.id' => ['nullable', 'integer'],
            'criterios.*.nome' => ['required', 'string', 'max:500', new TamanhoEmBytes(500)],
            'criterios.*.descricao' => ['nullable', 'string', 'max:2000', new TamanhoEmBytes(2000)],
            'criterios.*.peso' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'criterios.*.pontos_maximos' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'criterios.*.todas_categorias' => ['boolean'],
            'criterios.*.categorias' => ['required_if_declined:criterios.*.todas_categorias', 'nullable', 'array'],
            'criterios.*.categorias.*' => $posicaoDeCategoria,

            ...$this->regrasDasCotas(),
            ...($this->comQuestionario() ? RegrasDoQuestionario::regras($this->posicoesDeCategorias()) : []),

            'pontuacao_extra' => ['nullable', 'array'],
            'pontuacao_extra.grupos' => $lista,
            'pontuacao_extra.grupos.*.grupo' => ['required', 'string', 'max:255'],
            'pontuacao_extra.grupos.*.pontos_por_pessoa' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'pontuacao_extra.grupos.*.maximo' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'pontuacao_extra.teto' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'pontuacao_extra.conta_para_corte' => ['nullable', 'boolean'],

            'informacoes' => ['nullable', 'array:'.implode(',', array_keys(Edital::SECOES_INFORMATIVAS))],
            'informacoes.*' => ['nullable', 'string', 'max:20000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function regrasDasCotas(): array
    {
        $regras = [
            'cotas' => ['nullable', 'array'],
            'cotas.base' => ['nullable', Rule::enum(BaseCota::class)],
            'cotas.alcance' => ['nullable', Rule::enum(AlcanceCota::class)],
            'cotas.veda_negro_indigena' => ['nullable', 'boolean'],
            'cotas.heteroidentificacao' => ['nullable', 'boolean'],
        ];

        foreach (ModalidadeCota::cases() as $modalidade) {
            $regras["cotas.{$modalidade->value}"] = ['nullable', 'array'];
            $regras["cotas.{$modalidade->value}.ativa"] = ['nullable', 'boolean'];
            $regras["cotas.{$modalidade->value}.percentual"] = ['nullable', 'numeric', 'min:0', 'max:100'];
            $regras["cotas.{$modalidade->value}.quantidade"] = ['nullable', 'numeric', 'min:0', 'max:10000000000'];
        }

        return $regras;
    }

    /**
     * Posições das categorias a que o questionário pode se referir: as enviadas ou, sem as listas, as do edital.
     *
     * @return list<int>
     */
    private function posicoesDeCategorias(): array
    {
        if ($this->comListas()) {
            return $this->posicoes('categorias');
        }

        $edital = $this->route('edital');

        return $edital instanceof Edital ? array_keys($edital->categorias()->get()->all()) : [];
    }

    /**
     * Posições válidas de uma lista enviada (para as referências entre listas).
     *
     * @return list<int>
     */
    private function posicoes(string $lista): array
    {
        $itens = $this->input($lista);

        return is_array($itens) ? array_keys(array_values($itens)) : [];
    }

    /**
     * O app tem poucas traduções do Laravel; as mensagens aparecem embaixo de cada campo, sem o nome dele.
     *
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'required_if_accepted' => 'Este campo é obrigatório.',
            'string' => 'Digite um texto.',
            'max' => [
                'string' => 'Use no máximo :max caracteres.',
                'numeric' => 'Informe um valor de no máximo :max.',
                'array' => 'Cadastre no máximo :max itens.',
                'file' => 'O arquivo deve ter no máximo :max KB.',
            ],
            'min' => [
                'numeric' => 'Informe um valor de no mínimo :min.',
            ],
            'integer' => 'Informe um número inteiro.',
            'numeric' => 'Informe um número.',
            'in' => 'Selecione uma opção da lista.',
            Enum::class => 'Selecione uma opção da lista.',
            'distinct' => 'Esta opção foi escolhida mais de uma vez.',
            'boolean' => 'Valor inválido.',
            'array' => 'Valor inválido.',
            'list' => 'Valor inválido.',
            'date' => 'Digite uma data válida.',
            'pdf.file' => 'O arquivo precisa ser um PDF.',
            'pdf.mimes' => 'O arquivo precisa ser um PDF.',
            'pdf.mimetypes' => 'O arquivo precisa ser um PDF.',
            'pdf.max' => 'O PDF deve ter no máximo 20 MB.',
            'imagem.image' => 'Envie uma imagem JPG, PNG ou WEBP.',
            'imagem.mimes' => 'Envie uma imagem JPG, PNG ou WEBP.',
            'imagem.max' => 'A imagem deve ter no máximo 5 MB.',
            'chamadas.*.fim.after_or_equal' => 'O fim deve ser igual ou posterior ao início.',
            'chamadas.*.homologacao_inicio.required_with' => 'Preencha também o início da homologação.',
            'chamadas.*.homologacao_fim.required_with' => 'Preencha também o fim da homologação.',
            'chamadas.*.homologacao_fim.after_or_equal' => 'O fim deve ser igual ou posterior ao início.',
            'chamadas.*.validacao_inicio.required_with' => 'Preencha também o início da avaliação.',
            'chamadas.*.validacao_fim.required_with' => 'Preencha também o fim da avaliação.',
            'chamadas.*.validacao_fim.after_or_equal' => 'O fim deve ser igual ou posterior ao início.',
            'categorias.*.valor.min' => 'Informe um valor maior que zero.',
            'categorias.*.itens.*.valor.min' => 'Informe um valor maior que zero.',
            'documentos.*.categorias.required_if_declined' => 'Escolha ao menos uma categoria.',
            'criterios.*.categorias.required_if_declined' => 'Escolha ao menos uma categoria.',
            'documentos.*.modelo.file' => 'Envie um arquivo PDF, DOC, DOCX, XLS, XLSX, ODT ou ODS.',
            'documentos.*.modelo.mimes' => 'Envie um arquivo PDF, DOC, DOCX, XLS, XLSX, ODT ou ODS.',
            'documentos.*.modelo.max' => 'O arquivo-modelo deve ter no máximo 10 MB.',
            'informacoes.array' => 'Valor inválido.',
            ...RegrasDoQuestionario::mensagens(),
        ];
    }

    /**
     * Nomes legíveis para as mensagens padrão do Laravel que ainda usem :attribute.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'chamadas' => 'chamadas',
            'chamadas.*.nome' => 'nome da chamada',
            'chamadas.*.inicio' => 'início da chamada',
            'chamadas.*.fim' => 'fim da chamada',
            'chamadas.*.homologacao_inicio' => 'início da homologação',
            'chamadas.*.homologacao_fim' => 'fim da homologação',
            'chamadas.*.validacao_inicio' => 'início da avaliação',
            'chamadas.*.validacao_fim' => 'fim da avaliação',
            'categorias' => 'categorias',
            'categorias.*.nome' => 'nome da categoria',
            'categorias.*.valor' => 'valor da categoria',
            'categorias.*.vagas' => 'vagas da categoria',
            'categorias.*.chamada' => 'chamada da categoria',
            'categorias.*.itens.*.nome' => 'nome do item',
            'documentos' => 'documentos',
            'documentos.*.nome' => 'nome do documento',
            'documentos.*.etapa' => 'etapa do documento',
            'documentos.*.checagem' => 'checagem pela IA',
            'documentos.*.instrucao_ia' => 'instrução para a IA',
            'documentos.*.modelo' => 'arquivo-modelo',
            'criterios' => 'critérios',
            'criterios.*.nome' => 'nome do critério',
            'naturezas.*' => 'natureza',
            'dom_data' => 'data de publicação no DOM',
            'dom_numero' => 'número da edição do DOM',
            'nota_maxima' => 'nota máxima',
            'nota_corte' => 'nota de corte',
            'inscricoes_por_proponente' => 'inscrições por proponente',
            'forma_inscricao_original' => 'forma de inscrição no edital original',
        ];
    }

    /**
     * Edital publicado precisa de PDF: o enviado agora ou o já salvo.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $edital = $this->route('edital');
                $temPdf = $this->hasFile('pdf') || ($edital instanceof Edital && filled($edital->pdf));

                if ($this->boolean('publicado') && ! $temPdf) {
                    $validator->errors()->add('pdf', 'Envie o PDF do edital para publicar.');
                }
            },
            function (Validator $validator): void {
                $edital = $this->route('edital');

                if ($edital instanceof Edital && $edital->documentosTravados() && $this->removeDocumentoSalvo($edital)) {
                    $validator->errors()->add('documentos', Edital::AVISO_DOCUMENTOS_TRAVADOS);
                }
            },
            function (Validator $validator): void {
                if (! $this->comQuestionario()) {
                    return;
                }

                $edital = $this->route('edital');
                if ($edital instanceof Edital && $edital->questionarioTravado()) {
                    $validator->errors()->add('questionario', Edital::AVISO_QUESTIONARIO_TRAVADO);

                    return;
                }

                $versao = $this->input('questionario_versao');
                if ($edital instanceof Edital && ImportacaoQuestionario::versaoDoQuestionario($edital) !== ($versao === null || $versao === '' ? null : (int) $versao)) {
                    $validator->errors()->add('questionario', ImportacaoQuestionario::ERRO_VERSAO);

                    return;
                }

                RegrasDoQuestionario::conferir($validator, $this->input('questionario'));
            },
        ];
    }

    /** As listas vieram no envio (o formulário completo manda o marcador). */
    public function comListas(): bool
    {
        return $this->boolean('com_listas');
    }

    /** Com as listas no envio, algum documento já salvo do edital ficou de fora de `documentos.*.id` (seria apagado). */
    public function removeDocumentoSalvo(Edital $edital): bool
    {
        if (! $this->comListas()) {
            return false;
        }

        $enviados = array_map(intval(...), array_filter(
            (array) $this->input('documentos.*.id', []),
            fn (mixed $id): bool => is_numeric($id),
        ));

        foreach ($edital->documentos()->pluck('id') as $id) {
            if (! in_array((int) $id, $enviados, true)) {
                return true;
            }
        }

        return false;
    }

    /** O questionário veio no envio (o formulário completo manda o marcador). */
    public function comQuestionario(): bool
    {
        return $this->boolean('com_questionario');
    }

    /**
     * Questionário validado, no formato de SincronizadorDoQuestionario; nulo sem o marcador.
     *
     * @return array{secoes: list<array<string, mixed>>}|null
     */
    public function questionarioDoEdital(): ?array
    {
        if (! $this->comQuestionario()) {
            return null;
        }

        return ['secoes' => array_values($this->validated('questionario.secoes') ?? [])];
    }

    /**
     * Campos do edital, sem os arquivos e sem as listas. Campo ausente fica como está; com o marcador,
     * naturezas, cotas, pontuação extra e informações ausentes ficam vazias (o formulário não envia lista vazia).
     *
     * @return array<string, mixed>
     */
    public function dadosDoEdital(): array
    {
        $dados = [
            ...$this->safe()->only([
                'titulo', 'resumo', 'categoria', 'numero', 'tipo', 'objeto', 'dom_data', 'dom_numero',
                'aceita_coletivos', 'inscricoes_por_proponente', 'limite_por_chamada', 'forma_inscricao_original',
                'nota_maxima', 'nota_corte',
            ]),
            'publicado' => $this->boolean('publicado'),
        ];

        $estruturados = [
            'naturezas' => fn (): ?array => SincronizadorDoEdital::naturezas($this->validated('naturezas')),
            'cotas' => fn (): ?array => $this->cotas(),
            'pontuacao_extra' => fn (): ?array => $this->pontuacaoExtra(),
            'informacoes' => fn (): ?array => $this->informacoes(),
        ];

        foreach ($estruturados as $campo => $normalizar) {
            if ($this->comListas() || $this->has($campo)) {
                $dados[$campo] = $normalizar();
            }
        }

        return $dados;
    }

    /**
     * Listas no formato que SincronizadorDoEdital recebe.
     *
     * @return array{chamadas: list<array<string, mixed>>, categorias: list<array<string, mixed>>, documentos: list<array<string, mixed>>, criterios: list<array<string, mixed>>}
     */
    public function listasDoEdital(): array
    {
        return [
            'chamadas' => array_values($this->validated('chamadas') ?? []),
            'categorias' => array_values($this->validated('categorias') ?? []),
            'documentos' => array_values($this->validated('documentos') ?? []),
            'criterios' => array_values($this->validated('criterios') ?? []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cotas(): ?array
    {
        $cotas = $this->validated('cotas');
        if (! is_array($cotas)) {
            return null;
        }

        $resultado = [];
        foreach (ModalidadeCota::cases() as $modalidade) {
            $cota = $cotas[$modalidade->value] ?? [];
            $resultado[$modalidade->value] = [
                'ativa' => (bool) ($cota['ativa'] ?? false),
                'percentual' => self::numero($cota['percentual'] ?? null),
                'quantidade' => self::numero($cota['quantidade'] ?? null),
            ];
        }

        return [
            ...$resultado,
            'base' => $cotas['base'] ?? null,
            'alcance' => $cotas['alcance'] ?? null,
            'veda_negro_indigena' => self::booleanoOuNulo($cotas['veda_negro_indigena'] ?? null),
            'heteroidentificacao' => self::booleanoOuNulo($cotas['heteroidentificacao'] ?? null),
        ];
    }

    /**
     * @return array{grupos: list<array{grupo: string, pontos_por_pessoa: int|float|null, maximo: int|float|null}>, teto: int|float|null, conta_para_corte: ?bool}|null
     */
    private function pontuacaoExtra(): ?array
    {
        $pontuacao = $this->validated('pontuacao_extra');
        if (! is_array($pontuacao)) {
            return null;
        }

        $resultado = [
            'grupos' => array_map(fn (array $grupo): array => [
                'grupo' => $grupo['grupo'],
                'pontos_por_pessoa' => self::numero($grupo['pontos_por_pessoa'] ?? null),
                'maximo' => self::numero($grupo['maximo'] ?? null),
            ], array_values($pontuacao['grupos'] ?? [])),
            'teto' => self::numero($pontuacao['teto'] ?? null),
            'conta_para_corte' => self::booleanoOuNulo($pontuacao['conta_para_corte'] ?? null),
        ];

        return $resultado['grupos'] === [] && $resultado['teto'] === null && $resultado['conta_para_corte'] === null
            ? null
            : $resultado;
    }

    /**
     * Só as seções preenchidas; nenhuma = nulo.
     *
     * @return array<string, string>|null
     */
    private function informacoes(): ?array
    {
        $preenchidas = array_filter(
            (array) ($this->validated('informacoes') ?? []),
            fn (mixed $texto): bool => filled($texto),
        );

        return $preenchidas === [] ? null : $preenchidas;
    }

    private static function numero(mixed $valor): int|float|null
    {
        return is_numeric($valor) ? $valor + 0 : null;
    }

    private static function booleanoOuNulo(mixed $valor): ?bool
    {
        return $valor === null ? null : (bool) $valor;
    }
}
