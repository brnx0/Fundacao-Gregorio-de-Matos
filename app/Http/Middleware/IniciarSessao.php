<?php

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Sessão do Laravel sem guardar o endereço da página de acesso do avaliador como "página anterior": o endereço
 * traz o código do link pessoal, que não pode ficar na sessão (só o hash dele).
 */
class IniciarSessao extends StartSession
{
    /**
     * @param  Session  $session
     */
    protected function storeCurrentUrl(Request $request, $session): void
    {
        if ($request->routeIs('avaliacao.acesso')) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
