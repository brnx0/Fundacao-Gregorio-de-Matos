<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organização, tipo de cadastro e portal só abrem para quem concluiu Meus dados e Anexos (profile_completed_at).
 * Quem salvou Meus dados e não concluiu Anexos volta para Anexos; quem nem salvou Meus dados, para Meus dados.
 */
class ExigirCadastroCompleto
{
    public const AVISO = 'Complete seus dados de Pessoa Física antes de cadastrar uma Pessoa Jurídica.';

    public const AVISO_ANEXOS = 'Envie seus documentos na etapa Anexos antes de seguir.';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->profile_completed_at === null) {
            if ($user?->pessoaFisica !== null) {
                return redirect()->route('cadastro.anexos')->with('aviso', self::AVISO_ANEXOS);
            }

            return redirect()->route('cadastro')->with('aviso', self::AVISO);
        }

        return $next($request);
    }
}
