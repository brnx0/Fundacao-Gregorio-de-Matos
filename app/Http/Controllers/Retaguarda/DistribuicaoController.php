<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\ModoDistribuicao;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\DistribuirRequest;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\Inscricao;
use App\Models\InscricaoAvaliacao;
use App\Support\Avaliacao\DistribuidorDeInscricoes;
use App\Support\Avaliacao\PlanoDeDistribuicao;
use App\Support\Edital\PeriodosDoEdital;
use App\Support\Inscricao\PropsDaInscricao;
use App\Support\Inscricao\Travas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aba Distribuição da avaliação de um edital: as inscrições homologadas com o avaliador de cada uma, a estimativa da
 * distribuição (igual ou por categoria) e a distribuição de fato, refeita com o edital travado. Só as inscrições ainda
 * sem avaliador entram; as que já têm ficam como estão.
 */
class DistribuicaoController extends Controller
{
    public const string AVISO_NADA_A_DISTRIBUIR = 'Não há inscrições homologadas sem avaliador.';

    public const string SEM_AVALIADOR = 'Sem avaliador';

    /** As inscrições homologadas, os destinos, os desativados com trabalho, as pendências e, com `?modo=`, a estimativa da distribuição. */
    public function index(Request $request, Edital $edital, DistribuidorDeInscricoes $distribuidor): Response
    {
        abort_unless($edital->publicado, 404);

        $edital->load('chamadas:id,edital_id,nome,validacao_inicio,validacao_fim,ordem');
        $modos = DistribuidorDeInscricoes::modos($edital);
        $modo = ModoDistribuicao::tryFrom((string) $request->query('modo', ''));
        $previa = null;

        if ($modo !== null && in_array($modo, $modos, true)) {
            $plano = $distribuidor->plano($edital, $modo);
            $previa = [
                'modo' => $modo->value,
                'porAvaliador' => $this->porAvaliador($plano),
                'semAvaliador' => collect($plano->semAvaliador)
                    ->map(fn (int $quantidade, string $categoria): array => ['categoria' => $categoria, 'quantidade' => $quantidade])
                    ->values()
                    ->all(),
            ];
        }

        $inscricoes = Inscricao::query()
            ->where('inscricoes.edital_id', $edital->id)
            ->where('inscricoes.situacao', SituacaoInscricao::Finalizada)
            ->where('inscricoes.homologacao', SituacaoHomologacao::Homologada)
            ->select(['id', 'edital_id', 'user_id', 'organizacao_id', 'edital_categoria_id', 'numero'])
            ->with([
                'user:id,name',
                'organizacao:id,razao_social,nome_fantasia',
                'categoria:id,nome',
                'avaliacao:id,inscricao_id,avaliador_id,observacao,concluida_em',
                'avaliacao.avaliador:id,nome',
                'avaliacao.notas:id,inscricao_avaliacao_id',
            ])
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Inscricao $inscricao): array => [
                'id' => (int) $inscricao->id,
                'numero' => $inscricao->numero,
                'proponente' => PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user),
                'categoria' => $inscricao->categoria?->nome,
                'avaliadorId' => $inscricao->avaliacao === null ? null : (int) $inscricao->avaliacao->avaliador_id,
                'avaliador' => $inscricao->avaliacao?->avaliador->nome,
                'situacao' => $inscricao->avaliacao?->situacao()->value,
                'situacaoRotulo' => $inscricao->avaliacao?->situacao()->rotulo() ?? self::SEM_AVALIADOR,
            ]);

        $abertas = $this->naoConcluidas($edital);

        return Inertia::render('Retaguarda/Avaliacao/Distribuicao', [
            'edital' => [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'periodos' => PeriodosDoEdital::formatar($edital, 'validacao'),
            ],
            'pendencias' => $edital->pendenciasDaDistribuicao(),
            'modos' => array_map(fn (ModoDistribuicao $opcao): array => ['valor' => $opcao->value, 'rotulo' => $opcao->rotulo()], $modos),
            'previa' => $previa,
            'inscricoes' => $inscricoes,
            'destinos' => $this->destinos($edital),
            'desativados' => $this->desativados($edital, $abertas),
            'origens' => $this->origens($edital, $abertas),
        ]);
    }

    /**
     * Distribui as homologadas sem avaliador. Com o edital travado, confere as pendências e refaz o plano (pode ter
     * entrado inscrição nova ou ter sido atribuída alguma desde a estimativa): só grava inscrição ainda sem avaliador.
     */
    public function distribuir(DistribuirRequest $request, Edital $edital, DistribuidorDeInscricoes $distribuidor): RedirectResponse
    {
        abort_unless($edital->publicado, 404);

        $modo = $request->modo();
        $gestor = (int) $request->user()->id;

        $resultado = DB::transaction(function () use ($edital, $modo, $gestor, $distribuidor): string|PlanoDeDistribuicao {
            Travas::edital($edital);
            abort_unless((bool) Edital::query()->whereKey($edital->id)->value('publicado'), 404);

            $pendencias = $edital->pendenciasDaDistribuicao();
            if ($pendencias !== []) {
                return $pendencias[0];
            }

            $plano = $distribuidor->plano($edital, $modo);
            $agora = now();

            foreach ($plano->atribuicoes as $inscricao => $avaliador) {
                InscricaoAvaliacao::query()->create([
                    'inscricao_id' => $inscricao,
                    'avaliador_id' => $avaliador,
                    'atribuida_por' => $gestor,
                    'atribuida_em' => $agora,
                ]);
            }

            return $plano;
        });

        if (is_string($resultado)) {
            return back()->with('erro', $resultado);
        }

        if ($resultado->vazio() && $resultado->semAvaliador === []) {
            return back()->with('aviso', self::AVISO_NADA_A_DISTRIBUIR);
        }

        $resposta = back();

        if (! $resultado->vazio()) {
            $total = count($resultado->atribuicoes);
            $nomes = implode(', ', array_map(fn (array $linha): string => "{$linha['nome']} {$linha['quantidade']}", $this->porAvaliador($resultado)));
            $resposta->with('sucesso', $total === 1 ? "Distribuída 1 inscrição: {$nomes}." : "Distribuídas {$total} inscrições: {$nomes}.");
        }

        if ($resultado->semAvaliador !== []) {
            $sobras = collect($resultado->semAvaliador)
                ->map(fn (int $quantidade, string $categoria): string => "{$quantidade} de {$categoria}")
                ->implode(', ');
            $resposta->with('aviso', "Ficaram sem avaliador: {$sobras}. Inclua um avaliador para essas categorias.");
        }

        return $resposta;
    }

    /**
     * Quantas inscrições o plano dá a cada avaliador, da maior quantidade para a menor e, no empate, pelo nome.
     *
     * @return list<array{nome: string, quantidade: int}>
     */
    private function porAvaliador(PlanoDeDistribuicao $plano): array
    {
        $nomes = Avaliador::query()->whereKey(array_keys($plano->porAvaliador))->pluck('nome', 'id')
            ->mapWithKeys(fn (string $nome, $id): array => [(int) $id => $nome]);

        return collect($plano->porAvaliador)
            ->map(fn (int $quantidade, int $avaliador): array => ['nome' => (string) $nomes->get($avaliador), 'quantidade' => $quantidade])
            ->sort(fn (array $a, array $b): int => [$b['quantidade'], mb_strtolower($a['nome'])] <=> [$a['quantidade'], mb_strtolower($b['nome'])])
            ->values()
            ->all();
    }

    /**
     * Para quem as inscrições podem ir: os avaliadores ativos do edital, pelo nome.
     *
     * @return list<array{avaliadorId: int, nome: string}>
     */
    private function destinos(Edital $edital): array
    {
        return $edital->avaliadores()
            ->whereHas('avaliador', fn (Builder $q) => $q->where('ativo', true))
            ->with('avaliador:id,nome')
            ->get()
            ->map(fn (EditalAvaliador $vinculo): array => ['avaliadorId' => (int) $vinculo->avaliador_id, 'nome' => $vinculo->avaliador->nome])
            ->sortBy(fn (array $destino): string => mb_strtolower($destino['nome']))
            ->values()
            ->all();
    }

    /**
     * Os avaliadores desativados do edital que ainda têm inscrições não concluídas dele, pelo nome: a tela pede que
     * sejam transferidas.
     *
     * @param  array<int, int>  $abertas  Inscrições não concluídas pelo id do avaliador.
     * @return list<array{avaliadorId: int, nome: string, naoConcluidas: int}>
     */
    private function desativados(Edital $edital, array $abertas): array
    {
        return $this->avaliadoresDoEdital($edital, $abertas, fn (bool $ativo, int $naoConcluidas): bool => ! $ativo && $naoConcluidas > 0);
    }

    /**
     * De quem o "Transferir todas" pode tirar inscrições: os avaliadores ativos do edital e os desativados com
     * inscrições não concluídas, pelo nome, com quantas não concluídas cada um tem (a confirmação mostra).
     *
     * @param  array<int, int>  $abertas  Inscrições não concluídas pelo id do avaliador.
     * @return list<array{avaliadorId: int, nome: string, naoConcluidas: int}>
     */
    private function origens(Edital $edital, array $abertas): array
    {
        return $this->avaliadoresDoEdital($edital, $abertas, fn (bool $ativo, int $naoConcluidas): bool => $ativo || $naoConcluidas > 0);
    }

    /**
     * Os avaliadores do edital que passam no filtro (pela situação e pelas não concluídas), pelo nome.
     *
     * @param  array<int, int>  $abertas  Inscrições não concluídas pelo id do avaliador.
     * @param  callable(bool, int): bool  $filtro
     * @return list<array{avaliadorId: int, nome: string, naoConcluidas: int}>
     */
    private function avaliadoresDoEdital(Edital $edital, array $abertas, callable $filtro): array
    {
        return $edital->avaliadores()
            ->with('avaliador:id,nome,ativo')
            ->get()
            ->filter(fn (EditalAvaliador $vinculo): bool => $filtro((bool) $vinculo->avaliador->ativo, $abertas[(int) $vinculo->avaliador_id] ?? 0))
            ->map(fn (EditalAvaliador $vinculo): array => [
                'avaliadorId' => (int) $vinculo->avaliador_id,
                'nome' => $vinculo->avaliador->nome,
                'naoConcluidas' => $abertas[(int) $vinculo->avaliador_id] ?? 0,
            ])
            ->sortBy(fn (array $avaliador): string => mb_strtolower($avaliador['nome']))
            ->values()
            ->all();
    }

    /**
     * Quantas inscrições do edital ainda não concluídas cada avaliador tem.
     *
     * @return array<int, int> pelo id do avaliador
     */
    private function naoConcluidas(Edital $edital): array
    {
        return InscricaoAvaliacao::query()
            ->join('inscricoes', 'inscricoes.id', '=', 'inscricao_avaliacoes.inscricao_id')
            ->where('inscricoes.edital_id', $edital->id)
            ->whereNull('inscricao_avaliacoes.concluida_em')
            ->groupBy('inscricao_avaliacoes.avaliador_id')
            ->selectRaw('inscricao_avaliacoes.avaliador_id as avaliador_id')
            ->selectRaw('count(*) as total')
            ->toBase()
            ->pluck('total', 'avaliador_id')
            ->mapWithKeys(fn ($total, $avaliador): array => [(int) $avaliador => (int) $total])
            ->all();
    }
}
