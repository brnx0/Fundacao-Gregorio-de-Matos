<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\TipoCadastro;
use App\Http\Controllers\Controller;
use App\Models\AreaAtuacao;
use App\Models\Organizacao;
use App\Models\PessoaFisica;
use App\Models\PrefeituraBairro;
use App\Models\RepresentanteLegal;
use App\Models\User;
use App\Support\Cnpj;
use App\Support\Cpf;
use App\Support\Estados;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cadastros feitos no portal (quem enviou a etapa "Seus dados" e não é usuário da retaguarda), só para consulta.
 * O CPF aparece sempre mascarado, e os anexos saem só pela rota de anexo, nunca pelo caminho no disco.
 */
class ProponenteController extends Controller
{
    /** Rótulos dos anexos, na ordem e com os nomes do formulário do portal (Cadastro/Completar.vue). */
    private const array ROTULOS_DOS_ANEXOS = [
        'foto_perfil' => 'Foto de perfil',
        'documento_cpf' => 'Cópia do CPF',
        'documento_rg' => 'Cópia do RG',
        'documento_passaporte' => 'Passaporte',
        'documento_crnm' => 'Registro Nacional Migratório (CRNM)',
        'documento_outros' => 'Outros documentos',
        'comprovante_residencia' => 'Comprovante de residência',
    ];

    /** Extensões aceitas no envio que o navegador mostra como imagem. */
    private const array EXTENSOES_DE_IMAGEM = ['jpg', 'jpeg', 'png', 'gif'];

    private const array SITUACOES = ['finalizado' => 'Finalizado', 'andamento' => 'Em andamento'];

    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('busca', ''));
        $situacao = array_key_exists((string) $request->query('situacao'), self::SITUACOES) ? (string) $request->query('situacao') : '';
        $tipo = TipoCadastro::tryFrom((string) $request->query('tipo', ''));

        $proponentes = $this->doPortal()
            ->select(['id', 'name', 'cpf', 'email', 'tipos_cadastro', 'profile_completed_at'])
            ->with('pessoaFisica:id,user_id,nome_social,cidade,estado')
            ->when($situacao === 'finalizado', fn (Builder $q) => $q->whereNotNull('tipos_cadastro'))
            ->when($situacao === 'andamento', fn (Builder $q) => $q->whereNull('tipos_cadastro'))
            // tipos_cadastro guarda o JSON dos valores, ex.: ["proponente","patrocinador"].
            ->when($tipo !== null, fn (Builder $q) => $q->where('tipos_cadastro', 'like', '%"'.$tipo->value.'"%'))
            ->when($busca !== '', fn (Builder $q) => $this->buscar($q, $busca))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'nome' => $user->name,
                'nomeSocial' => $user->pessoaFisica?->nome_social,
                'cpf' => Cpf::mascarar($user->cpf),
                'email' => $user->email,
                'cidade' => $this->cidadeUf($user->pessoaFisica?->cidade, $user->pessoaFisica?->estado),
                'tipos' => $this->tipos($user),
                'finalizado' => $user->cadastroFinalizado(),
                'cadastradoEm' => $user->profile_completed_at?->format('d/m/Y'),
            ]);

        return Inertia::render('Retaguarda/Proponentes/Index', [
            'proponentes' => $proponentes,
            'filtros' => ['busca' => $busca, 'situacao' => $situacao, 'tipo' => $tipo?->value ?? ''],
            'situacoes' => collect(self::SITUACOES)->map(fn (string $rotulo, string $valor): array => ['valor' => $valor, 'rotulo' => $rotulo])->values(),
            'tiposCadastro' => array_map(fn (TipoCadastro $t): array => ['valor' => $t->value, 'rotulo' => $t->rotulo()], TipoCadastro::cases()),
        ]);
    }

    public function show(User $user): Response
    {
        $pessoa = $this->pessoaDoPortal($user);

        return Inertia::render('Retaguarda/Proponentes/Show', [
            'proponente' => [
                'id' => $user->id,
                'nome' => $user->name,
                'tipos' => $this->tipos($user),
                'finalizado' => $user->cadastroFinalizado(),
                'cadastradoEm' => $user->profile_completed_at?->format('d/m/Y H:i'),
                'ultimoAcesso' => $user->last_login_at?->format('d/m/Y H:i'),
            ],
            'secoes' => $this->secoes($user, $pessoa),
            'anexos' => $this->anexos($user, $pessoa),
            'organizacoes' => $this->organizacoes($user),
        ]);
    }

    /** Abre o arquivo na aba (a tela oferece também "Baixar", com o nome sugerido em show). */
    public function anexo(User $user, string $campo): StreamedResponse
    {
        $caminho = $this->pessoaDoPortal($user)->{$campo};

        abort_unless(filled($caminho) && Storage::disk('local')->exists($caminho), 404);

        return Storage::disk('local')->response($caminho, $this->nomeDoAnexo($user, $campo, $caminho));
    }

    /** Quem tem os dados da etapa "Seus dados" e não é usuário da retaguarda (com papel). */
    private function doPortal(): Builder
    {
        return User::query()->doPortal();
    }

    /** 404 para usuário da retaguarda e para quem ainda não enviou os dados. */
    private function pessoaDoPortal(User $user): PessoaFisica
    {
        abort_if($user->papeis()->exists(), 404);

        return $user->pessoaFisica ?? abort(404);
    }

    /** CPF completo digitado procura exato (a lista continua mostrando só o mascarado); o resto, nome e e-mail. */
    private function buscar(Builder $query, string $busca): void
    {
        $cpf = preg_replace('/\D/', '', $busca);
        if (strlen($cpf) === 11 && preg_match('/^[\d.\-\s]+$/', $busca) === 1) {
            $query->where('cpf', $cpf);

            return;
        }

        // No Oracle a comparação de texto diferencia maiúsculas; % e _ digitados valem literalmente.
        $termo = '%'.addcslashes(mb_strtolower($busca), '\\%_').'%';
        $query->where(fn (Builder $q) => $q
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$termo])
            ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '\\'", [$termo]));
    }

    /** @return list<string> */
    private function tipos(User $user): array
    {
        return ($user->tipos_cadastro ?? collect())->map(fn (TipoCadastro $tipo): string => $tipo->rotulo())->values()->all();
    }

    /**
     * Os blocos do formulário do portal (Cadastro/Completar.vue), com os valores já prontos para ler.
     *
     * @return list<array{id: string, titulo: string, campos: list<array{rotulo: string, valor: ?string}>}>
     */
    private function secoes(User $user, PessoaFisica $pessoa): array
    {
        $simNao = fn (bool $valor): string => $valor ? 'Sim' : 'Não';
        $secao = fn (string $id, string $titulo, array $campos): array => [
            'id' => $id,
            'titulo' => $titulo,
            'campos' => collect($campos)->map(fn (?string $valor, string $rotulo): array => ['rotulo' => $rotulo, 'valor' => filled($valor) ? $valor : null])->values()->all(),
        ];

        return [
            $secao('dados-pessoais', 'Dados pessoais', [
                'Nome completo' => $user->name,
                'Nome social' => $pessoa->nome_social,
                'Nome artístico' => $pessoa->nome_artistico,
                'Data de nascimento' => $pessoa->data_nascimento?->format('d/m/Y'),
            ]),
            $secao('documentos', 'Documentos', [
                'CPF' => Cpf::mascarar($user->cpf),
                'País de origem' => $pessoa->pais_origem,
                'RG' => $pessoa->rg,
            ]),
            $secao('profissional', 'Informações profissionais', [
                'Registro profissional (DRT)' => $pessoa->drt,
                'Áreas de atuação' => AreaAtuacao::emOrdem($pessoa->areasAtuacao)->pluck('nome')->implode(', '),
                'A atividade cultural é a principal fonte de renda' => $simNao($pessoa->cultura_principal_renda),
                'Já atuou como avaliador(a) em editais culturais' => $simNao($pessoa->ja_foi_avaliador),
                'Tem interesse em ser avaliador(a)' => $simNao($pessoa->interesse_avaliador),
                'Representa grupo ou coletivo cultural' => $simNao($pessoa->representa_coletivo),
            ]),
            $secao('contato', 'Informações de contato', [
                'Telefone' => $this->telefone($pessoa->telefone),
                'Telefone adicional' => $this->telefone($pessoa->telefone_adicional),
                'E-mail' => $pessoa->email,
            ]),
            $secao('identidade', 'Identidade e perfil', [
                'Identidade de gênero' => $pessoa->identidade_genero?->rotulo(),
                'Raça/cor' => $pessoa->raca_cor?->rotulo(),
                'Pessoa com deficiência (PcD)' => $simNao($pessoa->pcd),
                'Tipo de deficiência' => $pessoa->rotuloDoTipoDeDeficiencia(),
                'Escolaridade' => $pessoa->escolaridade?->rotulo(),
                'Renda mensal' => $pessoa->renda?->rotulo(),
                'Povo ou comunidade tradicional' => $pessoa->povo_tradicional?->rotulo(),
                'Anos de atuação na área cultural' => $pessoa->anos_atuacao === null ? null : (string) $pessoa->anos_atuacao,
            ]),
            $secao('endereco', 'Endereço', [
                'CEP' => $pessoa->cep !== null && strlen($pessoa->cep) === 8 ? substr($pessoa->cep, 0, 5).'-'.substr($pessoa->cep, 5) : $pessoa->cep,
                'Logradouro' => $pessoa->logradouro,
                'Número' => $pessoa->numero,
                'Bairro' => $pessoa->bairro,
                'Complemento' => $pessoa->complemento,
                'Cidade' => $pessoa->cidade,
                'Estado' => Estados::TODOS[$pessoa->estado] ?? $pessoa->estado,
                'Território' => PrefeituraBairro::rotuloDoCodigo($pessoa->territorio),
            ]),
        ];
    }

    /**
     * Os sete anexos, enviados ou não. Sai a URL da rota de anexo, nunca o caminho no disco.
     *
     * @return list<array{campo: string, rotulo: string, enviado: bool, imagem: bool, url: ?string, nome: ?string}>
     */
    private function anexos(User $user, PessoaFisica $pessoa): array
    {
        return array_map(function (string $campo) use ($user, $pessoa): array {
            $caminho = $pessoa->{$campo};
            $enviado = filled($caminho);

            return [
                'campo' => $campo,
                'rotulo' => self::ROTULOS_DOS_ANEXOS[$campo],
                'enviado' => $enviado,
                'imagem' => $enviado && in_array(mb_strtolower(pathinfo($caminho, PATHINFO_EXTENSION)), self::EXTENSOES_DE_IMAGEM, true),
                'url' => $enviado ? route('retaguarda.proponentes.anexo', [$user, $campo]) : null,
                'nome' => $enviado ? $this->nomeDoAnexo($user, $campo, $caminho) : null,
            ];
        }, PessoaFisica::ARQUIVOS);
    }

    /**
     * Pessoas Jurídicas a que o CPF está vinculado, com os representantes legais (CPF mascarado).
     *
     * @return list<array<string, mixed>>
     */
    private function organizacoes(User $user): array
    {
        return Organizacao::daPessoa($user)
            ->with(['representantes', 'vinculos' => fn ($q) => $q->where('cpf', $user->cpf)])
            ->orderBy('razao_social')
            ->get()
            ->map(fn (Organizacao $organizacao): array => [
                'id' => $organizacao->id,
                'razaoSocial' => $organizacao->razao_social,
                'nomeFantasia' => $organizacao->nome_fantasia,
                'cnpj' => Cnpj::formatar($organizacao->cnpj),
                'porte' => $organizacao->porte?->rotulo(),
                'cidade' => $this->cidadeUf($organizacao->cidade, $organizacao->uf),
                'responsavel' => (bool) $organizacao->vinculos->first()?->responsavel,
                'representantes' => $organizacao->representantes->map(fn (RepresentanteLegal $representante): array => [
                    'nome' => $representante->nome,
                    'cargo' => $representante->cargo,
                    'cpf' => Cpf::mascarar($representante->cpf),
                ])->all(),
            ])
            ->all();
    }

    /** Os nomes originais não são guardados no envio: o nome sai do tipo de anexo e da pessoa. */
    private function nomeDoAnexo(User $user, string $campo, string $caminho): string
    {
        return Str::slug(self::ROTULOS_DOS_ANEXOS[$campo].' '.$user->name).'.'.mb_strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    }

    private function cidadeUf(?string $cidade, ?string $uf): ?string
    {
        return filled($cidade) ? ($uf ? "{$cidade}/{$uf}" : $cidade) : null;
    }

    /** Telefone guardado só com dígitos: (71) 99999-9999 ou (71) 3333-3333. */
    private function telefone(?string $digitos): ?string
    {
        return match (strlen((string) $digitos)) {
            11 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7)),
            10 => sprintf('(%s) %s-%s', substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6)),
            default => $digitos,
        };
    }
}
