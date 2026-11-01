<?php

namespace App\Http\Controllers\Retaguarda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\CategoriasDoAvaliadorRequest;
use App\Http\Requests\Retaguarda\IncluirAvaliadoresRequest;
use App\Models\Avaliador;
use App\Models\Edital;
use App\Models\EditalAvaliador;
use App\Models\EditalCategoria;
use App\Models\InscricaoAvaliacao;
use App\Support\Avaliacao\Convites;
use App\Support\Cpf;
use App\Support\Edital\PeriodosDoEdital;
use App\Support\Inscricao\Travas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aba Avaliadores da avaliação de um edital: quem avalia, as categorias de cada um e o convite por e-mail. O convite
 * sai depois da transação (o processo em segundo plano precisa ver os vínculos gravados). O código do link nunca vai
 * para a tela nem para as mensagens.
 */
class AvaliadoresDaAvaliacaoController extends Controller
{
    public const string ERRO_TEM_INSCRICOES = 'Este avaliador tem inscrições do edital. Transfira-as antes de tirá-lo.';

    public const string ERRO_CATEGORIAS = 'Escolha categorias deste edital.';

    public const string ERRO_INATIVO = 'Este avaliador está desativado. Ative-o em Cadastro → Avaliadores para enviar o convite ou um link novo.';

    /** Os avaliadores do edital (com o convite e as inscrições de cada um), os que podem entrar e as pendências. */
    public function index(Edital $edital): Response
    {
        abort_unless($edital->publicado, 404);

        $edital->load('chamadas:id,edital_id,nome,validacao_inicio,validacao_fim,ordem');
        $categorias = $edital->categorias()->get(['id', 'edital_id', 'nome']);
        $cargas = $this->cargas($edital);

        $avaliadores = $edital->avaliadores()
            ->with(['avaliador:id,nome,email,ativo', 'categorias:id'])
            ->get()
            ->sortBy(fn (EditalAvaliador $vinculo): string => mb_strtolower($vinculo->avaliador->nome))
            ->map(function (EditalAvaliador $vinculo) use ($cargas): array {
                $carga = $cargas[(int) $vinculo->avaliador_id] ?? ['total' => 0, 'abertas' => 0];
                $situacao = $vinculo->situacaoDoConvite();

                return [
                    'id' => (int) $vinculo->id,
                    'avaliadorId' => (int) $vinculo->avaliador_id,
                    'nome' => $vinculo->avaliador->nome,
                    'email' => $vinculo->avaliador->email,
                    'ativo' => $vinculo->avaliador->ativo,
                    'categorias' => $vinculo->categorias->map(fn (EditalCategoria $categoria): int => (int) $categoria->id)->sort()->values()->all(),
                    'convite' => [
                        'situacao' => $situacao?->value,
                        'rotulo' => $situacao?->rotulo(),
                        'enviadoEm' => $vinculo->convite_enviado_em?->format('d/m/Y H:i'),
                        'erro' => $vinculo->erroDoConvite(),
                    ],
                    'inscricoes' => $carga['total'],
                    'naoConcluidas' => $carga['abertas'],
                ];
            })
            ->values()
            ->all();

        $disponiveis = Avaliador::query()
            ->where('ativo', true)
            ->whereDoesntHave('vinculos', fn (Builder $q) => $q->where('edital_id', $edital->id))
            ->orderBy('nome')
            ->orderBy('id')
            ->get(['id', 'nome', 'cpf'])
            ->map(fn (Avaliador $avaliador): array => [
                'id' => (int) $avaliador->id,
                'nome' => $avaliador->nome,
                'cpf' => Cpf::mascarar($avaliador->cpf),
            ])
            ->all();

        return Inertia::render('Retaguarda/Avaliacao/Avaliadores', [
            'edital' => [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'temCategorias' => $categorias->isNotEmpty(),
                'periodos' => PeriodosDoEdital::formatar($edital, 'validacao'),
            ],
            'avaliadores' => $avaliadores,
            'disponiveis' => $disponiveis,
            'categorias' => $categorias->map(fn (EditalCategoria $categoria): array => [
                'id' => (int) $categoria->id,
                'nome' => $categoria->nome,
            ])->all(),
            'pendencias' => $edital->pendenciasDaDistribuicao(),
        ]);
    }

    /**
     * Inclui os avaliadores escolhidos (ativos e ainda fora do edital, conferido de novo com o edital travado) e manda
     * o convite a cada um, depois de gravar.
     */
    public function store(IncluirAvaliadoresRequest $request, Edital $edital): RedirectResponse
    {
        abort_unless($edital->publicado, 404);

        $ids = $request->ids();

        $incluidos = DB::transaction(function () use ($edital, $ids) {
            Travas::edital($edital);

            $jaNoEdital = $edital->avaliadores()->pluck('avaliador_id')->map(fn ($id): int => (int) $id)->all();

            return Avaliador::query()
                ->where('ativo', true)
                ->whereKey(array_diff($ids, $jaNoEdital))
                ->orderBy('id')
                ->get()
                ->map(fn (Avaliador $avaliador): array => [
                    $avaliador,
                    EditalAvaliador::query()->create(['edital_id' => $edital->id, 'avaliador_id' => $avaliador->id]),
                ]);
        });

        foreach ($incluidos as [$avaliador, $vinculo]) {
            Convites::enviar($avaliador, collect([$vinculo]));
        }

        $total = $incluidos->count();
        $mensagem = $total === 1 ? '1 avaliador incluído.' : "{$total} avaliadores incluídos.";

        return back()->with('sucesso', "{$mensagem} O convite está sendo enviado.");
    }

    /** Troca as categorias que o avaliador avalia no edital (nenhuma é permitido). A tela atualiza o interruptor. */
    public function categorias(CategoriasDoAvaliadorRequest $request, Edital $edital, EditalAvaliador $vinculo): RedirectResponse
    {
        $this->doEdital($edital, $vinculo);

        DB::transaction(fn () => $vinculo->categorias()->sync($request->ids()));

        return back();
    }

    /**
     * Tira o avaliador do edital (as categorias dele saem junto, em cascata), recusado se ele tem alguma inscrição do
     * edital (conferido com o edital travado).
     */
    public function destroy(Edital $edital, EditalAvaliador $vinculo): RedirectResponse
    {
        $this->doEdital($edital, $vinculo);

        $tirado = DB::transaction(function () use ($edital, $vinculo): bool {
            Travas::edital($edital);

            $temInscricoes = InscricaoAvaliacao::query()
                ->where('avaliador_id', $vinculo->avaliador_id)
                ->whereHas('inscricao', fn (Builder $q) => $q->where('edital_id', $edital->id))
                ->exists();

            if ($temInscricoes) {
                return false;
            }

            $vinculo->delete();

            return true;
        });

        return $tirado
            ? back()->with('sucesso', 'Avaliador tirado do edital.')
            : back()->with('erro', self::ERRO_TEM_INSCRICOES);
    }

    /** Reenvia o convite deste edital ao avaliador (o mesmo link). */
    public function convite(Edital $edital, EditalAvaliador $vinculo): RedirectResponse
    {
        $this->doEdital($edital, $vinculo);
        $avaliador = $vinculo->avaliador;

        if (! $avaliador->ativo) {
            return back()->with('erro', self::ERRO_INATIVO);
        }

        Convites::enviar($avaliador, collect([$vinculo]));

        return back()->with('sucesso', "O convite está sendo reenviado para {$avaliador->email}.");
    }

    /**
     * Gera um link novo para o avaliador (o antigo e as sessões abertas com ele deixam de valer) e o envia num convite
     * com todos os editais dele.
     */
    public function novoLink(Edital $edital, EditalAvaliador $vinculo): RedirectResponse
    {
        $this->doEdital($edital, $vinculo);
        $avaliador = $vinculo->avaliador;

        if (! $avaliador->ativo) {
            return back()->with('erro', self::ERRO_INATIVO);
        }

        $avaliador->novoAcesso();
        Convites::enviar($avaliador, $avaliador->vinculos()->orderBy('id')->get());

        return back()->with('sucesso', "Link novo gerado e enviado para {$avaliador->email}. O link antigo deixou de valer.");
    }

    /** Edital não publicado ou vínculo de outro edital: 404. */
    private function doEdital(Edital $edital, EditalAvaliador $vinculo): void
    {
        abort_unless($edital->publicado && (int) $vinculo->edital_id === (int) $edital->id, 404);
    }

    /**
     * Quantas inscrições do edital estão com cada avaliador, e quantas delas ainda não foram concluídas.
     *
     * @return array<int, array{total: int, abertas: int}> pelo id do avaliador
     */
    private function cargas(Edital $edital): array
    {
        return InscricaoAvaliacao::query()
            ->join('inscricoes', 'inscricoes.id', '=', 'inscricao_avaliacoes.inscricao_id')
            ->where('inscricoes.edital_id', $edital->id)
            ->groupBy('inscricao_avaliacoes.avaliador_id')
            ->selectRaw('inscricao_avaliacoes.avaliador_id as avaliador_id')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when inscricao_avaliacoes.concluida_em is null then 1 else 0 end) as abertas')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $linha): array => [
                (int) $linha->avaliador_id => ['total' => (int) $linha->total, 'abertas' => (int) $linha->abertas],
            ])
            ->all();
    }
}
