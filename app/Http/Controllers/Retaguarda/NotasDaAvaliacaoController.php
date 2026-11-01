<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\SituacaoAvaliacao;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacaoNota;
use App\Support\Edital\PeriodosDoEdital;
use App\Support\Inscricao\PropsDaInscricao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aba Notas da avaliação de um edital: a equipe só vê nota, observação e notas por critério de avaliação concluída;
 * o que está em rascunho (ou sem avaliador) nunca vai para a tela.
 */
class NotasDaAvaliacaoController extends Controller
{
    /** Valor do filtro das inscrições ainda sem avaliador. */
    public const string SEM_AVALIADOR = 'sem_avaliador';

    /** As inscrições homologadas com o andamento da avaliação; a nota final só das concluídas. */
    public function index(Request $request, Edital $edital): Response
    {
        abort_unless($edital->publicado, 404);

        $situacao = (string) $request->query('situacao', '');
        if ($situacao !== self::SEM_AVALIADOR && SituacaoAvaliacao::tryFrom($situacao) === null) {
            $situacao = '';
        }

        $inscricoes = $this->homologadas($edital)
            ->select(['id', 'edital_id', 'user_id', 'organizacao_id', 'edital_categoria_id', 'numero'])
            ->with([
                'user:id,name',
                'organizacao:id,razao_social,nome_fantasia',
                'categoria:id,nome',
                'avaliacao:id,inscricao_id,avaliador_id,observacao,concluida_em,nota_final',
                'avaliacao.avaliador:id,nome',
                'avaliacao.notas:id,inscricao_avaliacao_id',
            ])
            ->when($situacao !== '', fn (Builder $q) => $this->filtrarPorSituacao($q, $situacao))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(function (Inscricao $inscricao): array {
                $avaliacao = $inscricao->avaliacao;

                return [
                    'id' => (int) $inscricao->id,
                    'numero' => $inscricao->numero,
                    'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                    'categoria' => $inscricao->categoria?->nome,
                    'avaliador' => $avaliacao?->avaliador->nome,
                    'situacao' => $avaliacao?->situacao()->value,
                    'situacaoRotulo' => $avaliacao?->situacao()->rotulo() ?? DistribuicaoController::SEM_AVALIADOR,
                    'notaFinal' => $avaliacao?->concluida_em === null ? null : $this->numero($avaliacao->nota_final),
                ];
            });

        $edital->load('chamadas:id,edital_id,nome,validacao_inicio,validacao_fim,ordem');

        return Inertia::render('Retaguarda/Avaliacao/Notas', [
            'edital' => [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'periodos' => PeriodosDoEdital::formatar($edital, 'validacao'),
            ],
            'inscricoes' => $inscricoes,
            'filtros' => ['situacao' => $situacao],
            'situacoes' => [
                ['valor' => self::SEM_AVALIADOR, 'rotulo' => DistribuicaoController::SEM_AVALIADOR],
                ...array_map(fn (SituacaoAvaliacao $s): array => ['valor' => $s->value, 'rotulo' => $s->rotulo()], SituacaoAvaliacao::cases()),
            ],
        ]);
    }

    /** As notas por critério (a cópia gravada), a nota final e a observação de uma avaliação concluída; senão, 404. */
    public function show(Edital $edital, int $inscricao): Response
    {
        abort_unless($edital->publicado, 404);

        $registro = $this->homologadas($edital)
            ->select(['id', 'edital_id', 'user_id', 'organizacao_id', 'edital_categoria_id', 'numero'])
            ->with(['user:id,name', 'organizacao:id,razao_social,nome_fantasia', 'categoria:id,nome', 'avaliacao.avaliador:id,nome'])
            ->whereKey($inscricao)
            ->firstOrFail();

        $avaliacao = $registro->avaliacao;
        abort_unless($avaliacao !== null && $avaliacao->concluida_em !== null, 404);

        $notas = $avaliacao->notas()
            ->with('criterio:id,nome,ordem')
            ->get()
            ->sortBy(fn (InscricaoAvaliacaoNota $nota): array => [(int) $nota->criterio->ordem, (int) $nota->criterio->id])
            ->values();

        return Inertia::render('Retaguarda/Avaliacao/NotasDaInscricao', [
            'edital' => ['slug' => $edital->slug, 'titulo' => $edital->titulo],
            'inscricao' => [
                'id' => (int) $registro->id,
                'numero' => $registro->numero,
                'proponente' => PropsDaInscricao::nomeDoCadastro($registro, $registro->user),
                'categoria' => $registro->categoria?->nome,
            ],
            'avaliacao' => [
                'avaliador' => $avaliacao->avaliador->nome,
                'concluidaEm' => $avaliacao->concluida_em->format('d/m/Y H:i'),
                'notaFinal' => $this->numero($avaliacao->nota_final),
                'observacao' => $avaliacao->observacao,
                'criterios' => $notas->map(fn (InscricaoAvaliacaoNota $nota): array => [
                    'id' => (int) $nota->edital_criterio_id,
                    'nome' => $nota->criterio->nome,
                    'nota' => $this->numero($nota->nota),
                    'peso' => $this->numero($nota->peso),
                    'pontosMaximos' => $this->numero($nota->pontos_maximos),
                ])->all(),
            ],
        ]);
    }

    /** @return Builder<Inscricao> */
    private function homologadas(Edital $edital): Builder
    {
        return Inscricao::query()
            ->where('inscricoes.edital_id', $edital->id)
            ->where('inscricoes.situacao', SituacaoInscricao::Finalizada)
            ->where('inscricoes.homologacao', SituacaoHomologacao::Homologada);
    }

    /**
     * @param  Builder<Inscricao>  $consulta
     * @return Builder<Inscricao>
     */
    private function filtrarPorSituacao(Builder $consulta, string $situacao): Builder
    {
        if ($situacao === self::SEM_AVALIADOR) {
            return $consulta->whereDoesntHave('avaliacao');
        }

        return match (SituacaoAvaliacao::from($situacao)) {
            SituacaoAvaliacao::Concluida => $consulta->whereHas('avaliacao', fn (Builder $q) => $q->whereNotNull('concluida_em')),
            SituacaoAvaliacao::EmAndamento => $consulta->whereHas('avaliacao', fn (Builder $q) => $q
                ->whereNull('concluida_em')
                ->where(fn (Builder $aberta) => $aberta->whereNotNull('observacao')->orWhereHas('notas'))),
            SituacaoAvaliacao::NaoIniciada => $consulta->whereHas('avaliacao', fn (Builder $q) => $q
                ->whereNull('concluida_em')
                ->whereNull('observacao')
                ->whereDoesntHave('notas')),
        };
    }

    /** Número com duas casas e vírgula decimal, como as telas do avaliador; nulo continua nulo. */
    private function numero(mixed $valor): ?string
    {
        return $valor === null ? null : number_format((float) $valor, 2, ',', '');
    }
}
