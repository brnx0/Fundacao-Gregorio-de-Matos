<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Support\Cnpj;
use App\Support\Cpf;
use App\Support\Inscricao\PropsDaInscricao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Homologação das inscrições finalizadas: a lista do gestor, com o que pede atenção primeiro. */
class HomologacaoController extends Controller
{
    /** Valor do filtro das inscrições ainda sem decisão. */
    private const string SEM_DECISAO = 'sem_decisao';

    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('busca', ''));
        $edital = ctype_digit((string) $request->query('edital', '')) ? (string) $request->query('edital') : '';
        $situacao = (string) $request->query('situacao', '');
        $situacaoDecidida = SituacaoHomologacao::tryFrom($situacao);
        if ($situacaoDecidida === null && $situacao !== self::SEM_DECISAO) {
            $situacao = '';
        }

        $inscricoes = $this->finalizadasDosEditaisPublicados()
            ->select([
                'inscricoes.id', 'inscricoes.edital_id', 'inscricoes.user_id', 'inscricoes.organizacao_id',
                'inscricoes.edital_categoria_id', 'inscricoes.documento_cadastro', 'inscricoes.numero',
                'inscricoes.finalizada_em', 'inscricoes.homologacao',
            ])
            ->with(['edital:id,titulo', 'user:id,name', 'organizacao:id,razao_social,nome_fantasia', 'categoria:id,nome'])
            ->when($edital !== '', fn (Builder $q) => $q->where('inscricoes.edital_id', (int) $edital))
            ->when($situacaoDecidida !== null, fn (Builder $q) => $q->where('inscricoes.homologacao', $situacaoDecidida->value))
            ->when($situacao === self::SEM_DECISAO, fn (Builder $q) => $q->whereNull('inscricoes.homologacao'))
            ->when($busca !== '', fn (Builder $q) => $this->buscar($q, $busca))
            ->orderByRaw("CASE WHEN inscricoes.homologacao = 'em_recurso' THEN 0 WHEN inscricoes.homologacao IS NULL THEN 1 ELSE 2 END")
            ->orderBy('inscricoes.finalizada_em')
            ->orderBy('inscricoes.id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Inscricao $inscricao): array => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                'documento' => $this->documento($inscricao->documento_cadastro),
                'edital' => $inscricao->edital->titulo,
                'categoria' => $inscricao->categoria?->nome,
                'finalizadaEm' => $inscricao->finalizada_em->format('d/m/Y H:i'),
                'homologacao' => $inscricao->homologacao?->value,
                'situacaoRotulo' => $inscricao->homologacao?->rotulo() ?? 'Sem decisão',
            ]);

        $editais = Edital::query()
            ->publicados()
            ->whereHas('inscricoes', fn (Builder $q) => $q->where('situacao', SituacaoInscricao::Finalizada))
            ->orderBy('titulo')
            ->orderBy('id')
            ->get(['id', 'titulo'])
            ->map(fn (Edital $e): array => ['valor' => (string) $e->id, 'rotulo' => $e->titulo])
            ->values();

        return Inertia::render('Retaguarda/Homologacao/Index', [
            'inscricoes' => $inscricoes,
            'filtros' => ['edital' => $edital, 'situacao' => $situacao, 'busca' => $busca],
            'editais' => $editais,
            'situacoes' => [
                ['valor' => self::SEM_DECISAO, 'rotulo' => 'Sem decisão'],
                ...array_map(fn (SituacaoHomologacao $s): array => ['valor' => $s->value, 'rotulo' => $s->rotulo()], SituacaoHomologacao::cases()),
            ],
        ]);
    }

    /** @return Builder<Inscricao> */
    private function finalizadasDosEditaisPublicados(): Builder
    {
        return Inscricao::query()
            ->where('inscricoes.situacao', SituacaoInscricao::Finalizada)
            ->whereHas('edital', fn (Builder $q) => $q->publicados());
    }

    /** O número da inscrição ou parte do nome do proponente (o usuário ou a organização). */
    private function buscar(Builder $query, string $busca): void
    {
        // No Oracle a comparação de texto diferencia maiúsculas; % e _ digitados valem literalmente.
        $termo = '%'.addcslashes(mb_strtolower($busca), '\%_').'%';
        $termoDoNumero = '%'.addcslashes($busca, '\%_').'%';

        $query->where(fn (Builder $q) => $q
            ->whereRaw("inscricoes.numero LIKE ? ESCAPE '\'", [$termoDoNumero])
            ->orWhereHas('user', fn (Builder $u) => $u->whereRaw("LOWER(users.name) LIKE ? ESCAPE '\'", [$termo]))
            ->orWhereHas('organizacao', fn (Builder $o) => $o
                ->whereRaw("LOWER(organizacoes.nome_fantasia) LIKE ? ESCAPE '\'", [$termo])
                ->orWhereRaw("LOWER(organizacoes.razao_social) LIKE ? ESCAPE '\'", [$termo])));
    }

    /** CPF mascarado (11 dígitos) ou CNPJ formatado. */
    private function documento(string $documento): string
    {
        return strlen($documento) === 11 ? Cpf::mascarar($documento) : Cnpj::formatar($documento);
    }
}
