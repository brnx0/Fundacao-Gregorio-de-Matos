<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A etapa Anexos guarda os arquivos na pessoa física: ela só existe depois de Meus dados ser salvo. */
class ExigirPessoaFisica
{
    public const AVISO = 'Preencha e salve Meus dados antes de enviar os anexos.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->pessoaFisica === null) {
            return redirect()->route('cadastro')->with('aviso', self::AVISO);
        }

        return $next($request);
    }
}
