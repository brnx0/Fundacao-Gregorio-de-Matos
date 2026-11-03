<?php

namespace App\Http\Controllers;

use App\Enums\SituacaoEdital;
use App\Enums\SituacaoInscricao;
use App\Models\Edital;
use App\Models\Inscricao;
use App\Models\User;
use App\Support\Inscricao\PropsDaInscricao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Telas do menu do portal depois do cadastro finalizado: Editais e inscrição (proponente) e Projetos (patrocinador). */
class PortalController extends Controller
{
    public const AVISO_INSCRICOES_FECHADAS = 'As inscrições deste edital não estão abertas.';

    /**
     * Só os editais em fase de inscrição, entre os publicados com PDF (os mesmos que o site mostra). Cada um traz a
     * situação da inscrição do usuário nele (`minhaInscricao`).
     */
    public function editais(Request $request): Response
    {
        $editais = Edital::visiveis()
            ->paraListagem()
            // O resumo (CLOB) é a descrição do card; são 15 por página.
            ->addSelect('resumo')
            ->naSituacao(SituacaoEdital::Aberto)
            ->orderByDesc('inscricoes_inicio')
            ->orderByDesc('id')
            ->paginate(15);

        $minhasInscricoes = $this->situacaoDasMinhasInscricoes($request->user(), $editais->pluck('id')->all());

        $editais->through(function (Edital $edital) use ($minhasInscricoes): array {
            $situacao = $edital->situacao();

            return [
                'slug' => $edital->slug,
                'titulo' => $edital->titulo,
                'resumo' => $edital->resumo,
                'categoria' => $edital->categoria,
                'periodo' => $edital->inscricoes_inicio->format('d/m/Y').' a '.$edital->inscricoes_fim->format('d/m/Y'),
                'situacao' => $situacao->value,
                'situacaoRotulo' => $situacao->rotulo(),
                'pdf' => $edital->pdfUrl(),
                'imagem' => $edital->imagemUrl(),
                'minhaInscricao' => $minhasInscricoes[(int) $edital->id] ?? null,
            ];
        });

        return Inertia::render('Portal/Editais', ['editais' => $editais]);
    }

    /** Início da inscrição num edital aberto: as inscrições do usuário nele e as escolhas para criar outra. */
    public function inscricao(Request $request, Edital $edital): Response|RedirectResponse
    {
        abort_unless($edital->estaVisivel(), 404);

        if ($edital->situacao() !== SituacaoEdital::Aberto) {
            return redirect()->route('portal.editais')->with('aviso', self::AVISO_INSCRICOES_FECHADAS);
        }

        return Inertia::render('Portal/Inscricao', [
            'edital' => PropsDaInscricao::edital($edital),
            'inscricoes' => PropsDaInscricao::minhasInscricoes($request->user(), $edital),
            'perfilIncompleto' => ! $request->user()->perfilCompleto(),
            ...PropsDaInscricao::opcoes($request->user(), $edital),
        ]);
    }

    /**
     * `finalizada` se alguma inscrição do usuário no edital estiver finalizada, senão `rascunho`; o edital em que
     * ele não tem inscrição fica de fora.
     *
     * @param  list<int|string>  $editalIds
     * @return array<int, string>
     */
    private function situacaoDasMinhasInscricoes(User $user, array $editalIds): array
    {
        return Inscricao::query()
            ->where('user_id', $user->id)
            ->whereIn('edital_id', $editalIds)
            ->get(['edital_id', 'situacao'])
            ->groupBy(fn (Inscricao $inscricao): int => (int) $inscricao->edital_id)
            ->map(fn (Collection $inscricoes): string => $inscricoes->contains(fn (Inscricao $inscricao): bool => $inscricao->situacao === SituacaoInscricao::Finalizada)
                ? SituacaoInscricao::Finalizada->value
                : SituacaoInscricao::Rascunho->value)
            ->all();
    }

    /** Ainda não há envio de projetos pelos proponentes: a tela mostra só o estado vazio. */
    public function projetos(): Response
    {
        return Inertia::render('Portal/Projetos');
    }
}
