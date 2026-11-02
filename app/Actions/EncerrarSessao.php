<?php

namespace App\Actions;

use App\Auth\GovBr\GovBrClient;
use App\Support\Avaliacao\AvaliadorDaSessao;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Encerra o login de uma área: o do portal (botão Sair, Voltar do navegador e página inicial) ou o da retaguarda
 * (botão Sair). São aplicações separadas, cada uma com o seu guard na mesma sessão do navegador: sair de uma não
 * mexe na outra, nem no acesso do avaliador.
 */
class EncerrarSessao
{
    public function __construct(private GovBrClient $govbr) {}

    /**
     * Devolve o endereço de saída do gov.br quando a sessão também precisa ser encerrada lá; nulo quando não.
     *
     * @param  'web'|'retaguarda'  $guard  O login que sai: 'web' (portal) ou 'retaguarda'.
     */
    public function handle(Request $request, string $guard = 'web'): ?string
    {
        // Só quem entrou nesta área pelo gov.br tem sessão lá para encerrar.
        $viaGovBr = (bool) $request->session()->pull($guard === 'retaguarda' ? 'retaguarda.via_govbr' : 'portal.via_govbr');

        Auth::guard($guard)->logout();

        // Com outra área aberta, a sessão continua (com id novo); sem nenhuma, é zerada.
        if ($this->outraAreaAberta($request, $guard)) {
            $request->session()->regenerate(true);
        } else {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Depois do invalidate: a marcação fica na sessão nova. Telas antigas com dados pessoais
        // não voltam pelo histórico do navegador.
        Inertia::clearHistory();

        return $viaGovBr && config('govbr.driver') === 'govbr' ? $this->govbr->logoutUrl() : null;
    }

    private function outraAreaAberta(Request $request, string $guard): bool
    {
        return Auth::guard($guard === 'retaguarda' ? 'web' : 'retaguarda')->check()
            || $request->session()->has(AvaliadorDaSessao::SESSAO_AVALIADOR);
    }
}
