<?php

namespace App\Http\Middleware;

use App\Actions\EncerrarSessao;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ao voltar para a página inicial, o proponente sai do login do portal: a home nunca mostra o cadastro em
 * andamento. O login da retaguarda é outro e continua.
 */
class EncerrarSessaoDoProponente
{
    public function __construct(private EncerrarSessao $encerrarSessao) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Gestor não entra pelo portal; se um ficou no login do portal (de antes de a retaguarda ter o seu), a home
        // o mostra como visitante (HandleInertiaRequests).
        if ($user === null || $user->ehGestor()) {
            return $next($request);
        }

        $saidaGovBr = $this->encerrarSessao->handle($request);

        if ($saidaGovBr !== null) {
            // Visita do Inertia recebe 409 e troca de página; carregamento normal recebe o redirecionamento.
            return Inertia::location($saidaGovBr);
        }

        return $next($request);
    }
}
