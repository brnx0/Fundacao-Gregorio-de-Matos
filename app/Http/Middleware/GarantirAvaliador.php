<?php

namespace App\Http\Middleware;

use App\Support\Avaliacao\AvaliadorDaSessao;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Só deixa passar o avaliador que entrou pelo link pessoal e continua ativo com o mesmo link. Link novo ou
 * avaliador desativado derrubam a sessão de avaliador já aberta, sem mexer no resto da sessão.
 */
class GarantirAvaliador
{
    public const string ERRO_SEM_ACESSO = 'Seu acesso de avaliador terminou. Use o link mais recente enviado por e-mail.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (AvaliadorDaSessao::conferir($request) !== null) {
            return $next($request);
        }

        AvaliadorDaSessao::sair($request);

        return redirect('/')->with('erro', self::ERRO_SEM_ACESSO);
    }
}
