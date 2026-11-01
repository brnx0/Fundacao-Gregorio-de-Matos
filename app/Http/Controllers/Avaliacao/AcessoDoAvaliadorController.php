<?php

namespace App\Http\Controllers\Avaliacao;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmarCpfDoAvaliadorRequest;
use App\Models\Avaliador;
use App\Support\Avaliacao\AvaliadorDaSessao;
use App\Support\Cpf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entrada do avaliador: o link pessoal do e-mail abre a página, e o CPF confirma que é a pessoa do link. O CPF
 * sozinho não dá acesso, e o código do link não vai para as props nem para a sessão.
 */
class AcessoDoAvaliadorController extends Controller
{
    public const string ERRO_CPF = 'O CPF não confere com o deste link.';

    public function create(string $codigo): Response
    {
        abort_if(Avaliador::porCodigo($codigo) === null, 404);

        // A página posta para o próprio endereço: o código não se repete nas props.
        return Inertia::render('Avaliacao/Acesso');
    }

    public function store(ConfirmarCpfDoAvaliadorRequest $request, string $codigo): RedirectResponse
    {
        $avaliador = Avaliador::porCodigo($codigo);
        abort_if($avaliador === null, 404);

        if (! hash_equals($avaliador->cpf, Cpf::normalizar((string) $request->validated('cpf')))) {
            return redirect($request->url())->withErrors(['cpf' => self::ERRO_CPF]);
        }

        AvaliadorDaSessao::entrar($request, $avaliador);

        return redirect()->route('avaliacao.inicio');
    }

    public function destroy(Request $request): RedirectResponse
    {
        AvaliadorDaSessao::sair($request);
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
