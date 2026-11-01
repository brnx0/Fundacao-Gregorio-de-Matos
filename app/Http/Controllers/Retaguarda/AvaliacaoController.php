<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Models\Edital;
use App\Models\EditalChamada;
use App\Support\Edital\PeriodosDoEdital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avaliação das inscrições homologadas: a lista dos editais, como a da homologação. Cada edital abre nas abas
 * Avaliadores, Distribuição e Notas.
 */
class AvaliacaoController extends Controller
{
    /** Os editais publicados com inscrição homologada, com as contagens: homologadas, sem avaliador e concluídas. */
    public function index(Request $request): Response
    {
        $busca = trim((string) $request->query('busca', ''));
        $homologadas = fn (Builder $q) => $q
            ->where('situacao', SituacaoInscricao::Finalizada)
            ->where('homologacao', SituacaoHomologacao::Homologada);

        $editais = Edital::query()
            ->publicados()
            ->whereHas('inscricoes', $homologadas)
            // No Oracle a comparação de texto diferencia maiúsculas; % e _ digitados valem literalmente.
            ->when($busca !== '', fn (Builder $q) => $q->whereRaw(
                "LOWER(titulo) LIKE ? ESCAPE '\'",
                ['%'.addcslashes(mb_strtolower($busca), '\%_').'%'],
            ))
            ->withCount([
                'inscricoes as homologadas' => $homologadas,
                'inscricoes as sem_avaliador' => fn (Builder $q) => $homologadas($q)->whereDoesntHave('avaliacao'),
                'inscricoes as concluidas' => fn (Builder $q) => $homologadas($q)
                    ->whereHas('avaliacao', fn (Builder $a) => $a->whereNotNull('concluida_em')),
            ])
            ->with('chamadas:id,edital_id,nome,validacao_inicio,validacao_fim,ordem')
            ->orderBy('titulo')
            ->orderBy('id')
            ->paginate(15, ['id', 'slug', 'titulo'])
            ->withQueryString()
            ->through(fn (Edital $edital): array => [
                'id' => (int) $edital->id,
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'periodos' => PeriodosDoEdital::formatar($edital, 'validacao'),
                'emAvaliacao' => $edital->chamadas->contains(fn (EditalChamada $chamada): bool => $chamada->naAvaliacao()),
                'homologadas' => (int) $edital->homologadas,
                'semAvaliador' => (int) $edital->sem_avaliador,
                'concluidas' => (int) $edital->concluidas,
            ]);

        return Inertia::render('Retaguarda/Avaliacao/Index', [
            'editais' => $editais,
            'filtros' => ['busca' => $busca],
        ]);
    }
}
