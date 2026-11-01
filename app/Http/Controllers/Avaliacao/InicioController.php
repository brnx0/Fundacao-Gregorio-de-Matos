<?php

namespace App\Http\Controllers\Avaliacao;

use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Models\Edital;
use App\Models\InscricaoAvaliacao;
use App\Support\Avaliacao\AvaliadorDaSessao;
use App\Support\Edital\PeriodosDoEdital;
use App\Support\Inscricao\PropsDaInscricao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Início da área do avaliador: as inscrições homologadas que ele avalia, por edital (pelo título), com o período de
 * avaliação e a nota final das concluídas. O nome vem das props compartilhadas (HandleInertiaRequests).
 */
class InicioController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $avaliador = AvaliadorDaSessao::atual($request);

        $avaliacoes = InscricaoAvaliacao::query()
            ->where('avaliador_id', $avaliador->id)
            // Edital apagado fica fora (o whereHas respeita a exclusão lógica).
            ->whereHas('inscricao', fn (Builder $q) => $q
                ->where('situacao', SituacaoInscricao::Finalizada)
                ->where('homologacao', SituacaoHomologacao::Homologada)
                ->whereHas('edital'))
            ->with([
                'notas:id,inscricao_avaliacao_id',
                'inscricao:id,edital_id,user_id,organizacao_id,edital_categoria_id,numero',
                'inscricao.user:id,name',
                'inscricao.organizacao:id,razao_social,nome_fantasia',
                'inscricao.categoria:id,nome',
                'inscricao.edital:id,slug,titulo',
                'inscricao.edital.chamadas:id,edital_id,nome,validacao_inicio,validacao_fim,ordem',
            ])
            ->get();

        $editais = $avaliacoes
            ->groupBy(fn (InscricaoAvaliacao $avaliacao): int => (int) $avaliacao->inscricao->edital_id)
            ->map(function (Collection $doEdital): array {
                /** @var Edital $edital */
                $edital = $doEdital->first()->inscricao->edital;

                return [
                    'id' => (int) $edital->id,
                    'titulo' => $edital->titulo,
                    'periodos' => PeriodosDoEdital::formatar($edital, 'validacao'),
                    'inscricoes' => $doEdital
                        ->sortBy([
                            fn (InscricaoAvaliacao $a, InscricaoAvaliacao $b): int => strcmp((string) $a->inscricao->numero, (string) $b->inscricao->numero),
                            fn (InscricaoAvaliacao $a, InscricaoAvaliacao $b): int => (int) $a->inscricao_id <=> (int) $b->inscricao_id,
                        ])
                        ->map(function (InscricaoAvaliacao $avaliacao): array {
                            $inscricao = $avaliacao->inscricao;
                            $situacao = $avaliacao->situacao();

                            return [
                                'id' => (int) $inscricao->id,
                                'numero' => $inscricao->numero,
                                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                                'categoria' => $inscricao->categoria?->nome,
                                'situacao' => $situacao->value,
                                'situacaoRotulo' => $situacao->rotulo(),
                                'notaFinal' => $avaliacao->concluida_em !== null && $avaliacao->nota_final !== null
                                    ? InscricoesDoAvaliadorController::numero($avaliacao->nota_final)
                                    : null,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => strcmp((string) $a['titulo'], (string) $b['titulo']),
                fn (array $a, array $b): int => $a['id'] <=> $b['id'],
            ])
            ->values()
            ->all();

        return Inertia::render('Avaliacao/Inicio', ['editais' => $editais]);
    }
}
