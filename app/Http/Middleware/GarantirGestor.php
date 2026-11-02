<?php

namespace App\Http\Middleware;

use App\Actions\EncerrarSessao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Só deixa passar gestor (ativo e com papel) logado na retaguarda, que tem login próprio, separado do portal.
 * Quem deixou de ser gestor com a sessão aberta (desativado ou sem papel) sai da retaguarda e volta ao login.
 */
class GarantirGestor
{
    public function __construct(private EncerrarSessao $encerrarSessao) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('retaguarda');

        if ($user?->ehGestor()) {
            return $next($request);
        }

        if ($user !== null) {
            $this->encerrarSessao->handle($request, 'retaguarda');
        }

        return redirect()->route('retaguarda.login');
    }
}
