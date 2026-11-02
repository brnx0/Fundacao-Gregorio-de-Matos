<?php

namespace App\Http\Controllers;

use App\Enums\OrigemRepresentacao;
use App\Enums\PorteOrganizacao;
use App\Enums\TipoDocumentoIdentidade;
use App\Http\Requests\SalvarOrganizacaoRequest;
use App\Models\Organizacao;
use App\Support\ArquivoArmazenado;
use App\Support\Cnpj;
use App\Support\Cpf;
use App\Support\Estados;
use App\Support\Municipios;
use App\Support\NaturezasJuridicas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrganizacaoController extends Controller
{
    /** Campos do formulário que não vão direto para a organização. */
    private const array FORA_DA_ORGANIZACAO = ['cnpj', 'logo', 'representantes'];

    /** Organizações às quais a pessoa está vinculada. */
    public function index(Request $request): Response
    {
        return Inertia::render('Organizacoes/Index', ['organizacoes' => Organizacao::resumosDaPessoa($request->user())]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();

        return $this->formulario(null, [
            'cpf' => Cpf::formatar($user->cpf),
            'nome' => $user->name,
        ], ! Organizacao::query()->daPessoa($user)->exists());
    }

    public function edit(Organizacao $organizacao): Response
    {
        Gate::authorize('update', $organizacao);

        return $this->formulario($organizacao, null);
    }

    public function store(SalvarOrganizacaoRequest $request): RedirectResponse
    {
        $user = $request->user();
        $logo = null;

        try {
            DB::transaction(function () use ($request, $user, &$logo): void {
                $organizacao = new Organizacao($this->dadosDaOrganizacao($request));
                $organizacao->cnpj = $request->validated('cnpj');
                $organizacao->save();

                $this->gravarRepresentantes($organizacao, $request->validated('representantes'));

                $organizacao->vinculos()->create([
                    'cpf' => $user->cpf,
                    'nome' => $user->name,
                    'responsavel' => true,
                ]);

                if ($request->hasFile('logo')) {
                    $logo = ArquivoArmazenado::guardar($request->file('logo'), 'public', "organizacoes/{$organizacao->id}");
                    $organizacao->logo = $logo;
                    $organizacao->save();
                }
            });
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagar('public', $logo);

            throw $erro;
        }

        // Durante o cadastro, a Pessoa Jurídica é a etapa 4: segue para a escolha do tipo.
        if (! $user->cadastroFinalizado()) {
            return redirect()->route('cadastro.tipo')->with('sucesso', 'Pessoa Jurídica salva.');
        }

        return redirect()->route('organizacoes.index')->with('sucesso', 'Pessoa Jurídica salva.');
    }

    public function update(SalvarOrganizacaoRequest $request, Organizacao $organizacao): RedirectResponse
    {
        Gate::authorize('update', $organizacao);

        $logoAntiga = $organizacao->logo;
        $logoNova = null;

        try {
            DB::transaction(function () use ($request, $organizacao, $logoAntiga, &$logoNova): void {
                $organizacao->fill($this->dadosDaOrganizacao($request));

                $this->gravarRepresentantes($organizacao, $request->validated('representantes'));

                if ($request->hasFile('logo')) {
                    $logoNova = ArquivoArmazenado::guardar($request->file('logo'), 'public', "organizacoes/{$organizacao->id}");
                    $organizacao->logo = $logoNova;
                }

                ArquivoArmazenado::salvarTrocando(
                    $organizacao,
                    [['public', $logoNova]],
                    $logoNova !== null ? [['public', $logoAntiga]] : [],
                );
            });
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagar('public', $logoNova);

            throw $erro;
        }

        return redirect()->route('organizacoes.index')->with('sucesso', 'Pessoa Jurídica salva.');
    }

    /**
     * Campos da organização a partir do que foi validado. Marcações vêm do boolean() e a finalidade
     * lucrativa fica nula quando a pessoa não escolheu.
     *
     * @return array<string, mixed>
     */
    private function dadosDaOrganizacao(SalvarOrganizacaoRequest $request): array
    {
        $semNumero = $request->boolean('sem_numero');

        return [
            ...$request->safe()->except(self::FORA_DA_ORGANIZACAO),
            'mei' => $request->boolean('mei'),
            'finalidade_lucrativa' => $request->filled('finalidade_lucrativa') ? $request->boolean('finalidade_lucrativa') : null,
            'sem_numero' => $semNumero,
            'numero' => $semNumero ? null : $request->validated('numero'),
        ];
    }

    /**
     * Substitui os representantes da organização, na ordem em que foram enviados.
     *
     * @param  list<array<string, mixed>>  $representantes
     */
    private function gravarRepresentantes(Organizacao $organizacao, array $representantes): void
    {
        $organizacao->representantes()->delete();

        foreach (array_values($representantes) as $posicao => $representante) {
            $organizacao->representantes()->create([...$representante, 'ordem' => $posicao + 1]);
        }
    }

    /**
     * @param  array{cpf: string, nome: string}|null  $representanteInicial
     */
    private function formulario(?Organizacao $organizacao, ?array $representanteInicial, bool $noCadastroInicial = false): Response
    {
        return Inertia::render('Organizacoes/Form', [
            'organizacao' => $organizacao ? $this->dadosParaEdicao($organizacao) : null,
            'naturezas' => collect(NaturezasJuridicas::TODAS)
                ->map(fn (string $descricao, string $codigo): array => ['valor' => $codigo, 'rotulo' => "{$codigo} · {$descricao}"])
                ->values(),
            'opcoes' => [
                'portes' => self::opcoes(PorteOrganizacao::cases()),
                'tiposDocumento' => self::opcoes(TipoDocumentoIdentidade::cases()),
                'origens' => self::opcoes(OrigemRepresentacao::cases()),
                'estados' => collect(Estados::TODOS)->map(fn (string $nome, string $sigla): array => ['valor' => $sigla, 'rotulo' => $nome])->values(),
            ],
            'representanteInicial' => $representanteInicial,
            'noCadastroInicial' => $noCadastroInicial,
        ]);
    }

    /** @return array<string, mixed> */
    private function dadosParaEdicao(Organizacao $organizacao): array
    {
        return [
            ...$organizacao->only([
                'id', 'razao_social', 'nome_fantasia', 'natureza_juridica', 'mei', 'finalidade_lucrativa',
                'municipio_ibge', 'inscricao_municipal', 'inscricao_estadual', 'telefone',
                'cep', 'logradouro', 'numero', 'sem_numero', 'complemento', 'bairro', 'cidade', 'uf',
            ]),
            'cnpj' => Cnpj::formatar($organizacao->cnpj),
            'porte' => $organizacao->porte?->value,
            'data_fundacao' => $organizacao->data_fundacao?->format('Y-m-d'),
            'logoUrl' => $organizacao->logoUrl(),
            'municipio' => Municipios::porCodigo($organizacao->municipio_ibge),
            'representantes' => $organizacao->representantes->map(fn ($representante): array => [
                ...$representante->only(['cpf', 'nome', 'numero_documento', 'cargo', 'telefone', 'email', 'origem_descricao']),
                'cpf' => Cpf::formatar($representante->cpf),
                'tipo_documento' => $representante->tipo_documento?->value,
                'origem' => $representante->origem?->value,
            ])->all(),
        ];
    }

    /**
     * @param  list<PorteOrganizacao|TipoDocumentoIdentidade|OrigemRepresentacao>  $casos
     * @return list<array{valor: string, rotulo: string}>
     */
    private static function opcoes(array $casos): array
    {
        return array_map(fn (PorteOrganizacao|TipoDocumentoIdentidade|OrigemRepresentacao $caso): array => ['valor' => $caso->value, 'rotulo' => $caso->rotulo()], $casos);
    }
}
