<?php

namespace App\Http\Controllers\Retaguarda;

use App\Actions\EncerrarSessao;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Entrada da retaguarda por e-mail e senha, e saída. O login é o da retaguarda, separado do login do portal. */
class LoginController extends Controller
{
    private const ERRO = 'E-mail ou senha inválidos.';

    /** Hash qualquer, conferido quando não há senha a comparar, para a resposta levar o mesmo tempo. */
    private const HASH_FICTICIO = '$2y$12$vsQYLPXw3K8oukvcpPx5iOXj3uZACj58Q8Q4PRfVkLfwk8O2UAeMO';

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user('retaguarda')?->ehGestor()) {
            // Mantém avisos (ex.: limite de tentativas) até o início.
            $request->session()->reflash();

            return redirect()->route('retaguarda.inicio');
        }

        return Inertia::render('Retaguarda/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
            'senha' => ['required', 'string'],
        ]);

        $user = User::where('email', mb_strtolower(trim($dados['email'])))->first();

        // Mesma mensagem (e o mesmo custo de Hash::check) para e-mail desconhecido, senha errada,
        // sem senha, inativo ou sem papel.
        $senhaConfere = Hash::check($dados['senha'], $user?->password ?? self::HASH_FICTICIO)
            && $user?->password !== null;

        if (! $senhaConfere || ! $user->ehGestor()) {
            throw ValidationException::withMessages(['email' => self::ERRO]);
        }

        Auth::guard('retaguarda')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('retaguarda.inicio');
    }

    /**
     * Como no portal: limpa o histórico (as telas de proponentes não reabrem pelo Voltar) e sai também do gov.br.
     * Sai só da retaguarda: o login do portal, se houver, continua.
     */
    public function destroy(Request $request, EncerrarSessao $encerrarSessao): RedirectResponse
    {
        $saidaDoGovBr = $encerrarSessao->handle($request, 'retaguarda');

        return $saidaDoGovBr !== null ? redirect()->away($saidaDoGovBr) : redirect()->route('retaguarda.login');
    }
}
