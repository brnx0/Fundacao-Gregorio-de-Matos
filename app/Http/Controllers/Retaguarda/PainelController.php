<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\TipoCadastro;
use App\Http\Controllers\Controller;
use App\Models\Edital;
use App\Support\Painel\AvaliadoresEEventos;
use App\Support\Painel\ConsultasDoPainel;
use App\Support\Painel\Editais;
use App\Support\Painel\Evolucao;
use App\Support\Painel\FiltrosDoPainel;
use App\Support\Painel\Inscricoes;
use App\Support\Painel\Organizacoes;
use App\Support\Painel\Pessoas;
use App\Support\Painel\Territorio;
use App\Support\Painel\VisaoGeral;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel, a tela inicial da retaguarda: números agregados de pessoas, organizações, território, editais e inscrições,
 * para orientar políticas e investimentos. Só mostra totais e percentuais, nunca uma pessoa.
 *
 * A página chega na hora só com os filtros; cada grupo de seções vem depois (deferred) e fica guardado em cache por
 * MINUTOS_NO_CACHE minutos para a mesma combinação de filtros.
 */
class PainelController extends Controller
{
    public const int MINUTOS_NO_CACHE = 10;

    public function __invoke(Request $request): Response
    {
        $editais = Edital::query()
            ->orderByDesc('inscricoes_inicio')
            ->orderByDesc('id')
            ->get(['id', 'titulo'])
            ->map(fn (Edital $edital): array => ['valor' => (string) $edital->id, 'rotulo' => $edital->titulo ?? 'Sem título'])
            ->all();
        $territorios = ConsultasDoPainel::territorios();

        $filtros = FiltrosDoPainel::daRequisicao(
            $request,
            array_map(fn (array $edital): int => (int) $edital['valor'], $editais),
            array_map('strval', array_keys($territorios)),
        );
        $consultas = new ConsultasDoPainel($filtros);
        $adiado = fn (string $secao, string $grupo, Closure $calcular) => Inertia::defer(
            fn (): array => $this->guardado($secao, $filtros, fn (): array => $calcular($consultas)),
            $grupo,
        );

        return Inertia::render('Retaguarda/Painel', [
            'filtros' => $filtros->paraTela(),
            'opcoes' => [
                'publicos' => array_map(fn (TipoCadastro $tipo): array => [
                    'valor' => $tipo->value,
                    'rotulo' => match ($tipo) {
                        TipoCadastro::Proponente => 'Proponentes',
                        TipoCadastro::Patrocinador => 'Patrocinadores',
                    },
                ], TipoCadastro::cases()),
                'territorios' => array_map(
                    fn (string $codigo, string $rotulo): array => ['valor' => $codigo, 'rotulo' => $rotulo],
                    array_map('strval', array_keys($territorios)),
                    array_values($territorios),
                ),
                'editais' => $editais,
            ],
            'minutosNoCache' => self::MINUTOS_NO_CACHE,
            'baseMinima' => ConsultasDoPainel::BASE_MINIMA,
            'visao' => $adiado('visao', 'visao', VisaoGeral::dados(...)),
            'territorio' => $adiado('territorio', 'territorio', Territorio::dados(...)),
            'pessoas' => $adiado('pessoas', 'pessoas', Pessoas::dados(...)),
            'organizacoes' => $adiado('organizacoes', 'pessoas', Organizacoes::dados(...)),
            'editais' => $adiado('editais', 'editais', Editais::dados(...)),
            'capacidade' => $adiado('capacidade', 'editais', AvaliadoresEEventos::dados(...)),
            'inscricoes' => $adiado('inscricoes', 'inscricoes', Inscricoes::dados(...)),
            'evolucao' => $adiado('evolucao', 'inscricoes', Evolucao::dados(...)),
        ]);
    }

    /**
     * Seção do cache (só arrays, que o cache aceita sem desserializar classes), com a hora do cálculo.
     *
     * @param  Closure(): array<string, mixed>  $calcular
     * @return array<string, mixed>
     */
    private function guardado(string $secao, FiltrosDoPainel $filtros, Closure $calcular): array
    {
        return Cache::remember(
            "painel:{$secao}:{$filtros->chave()}",
            now()->addMinutes(self::MINUTOS_NO_CACHE),
            fn (): array => [...$calcular(), 'atualizadoEm' => now()->format('H:i')],
        );
    }
}
