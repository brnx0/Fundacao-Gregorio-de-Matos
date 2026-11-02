<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Entrada na retaguarda pelo gov.br: só quem já é gestor; nunca cria usuário nem altera seus dados. Entra no login
 * da retaguarda, sem mexer no do portal.
 */
class EntrarNaRetaguardaPorGovBr
{
    public function handle(string $cpf): ?User
    {
        $user = User::where('cpf', $cpf)->first();

        if ($user === null || ! $user->ehGestor()) {
            return null;
        }

        Auth::guard('retaguarda')->login($user);
        session()->regenerate();
        session(['retaguarda.via_govbr' => true]);

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
