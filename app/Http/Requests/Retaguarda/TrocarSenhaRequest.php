<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Troca da própria senha. Quem entra só pelo gov.br não tem senha: não informa a atual e define uma.
 */
class TrocarSenhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $temSenha = $this->user()?->password !== null;

        return [
            'senha_atual' => $temSenha ? ['required', 'string', 'current_password:retaguarda'] : ['nullable'],
            'nova_senha' => array_filter([
                'required',
                'string',
                'min:8',
                'confirmed',
                $temSenha ? 'different:senha_atual' : null,
            ]),
        ];
    }

    /**
     * O app não tem tradução do Laravel; as mensagens aparecem embaixo de cada campo.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Digite um texto.',
            'senha_atual.current_password' => 'A senha atual está incorreta.',
            'nova_senha.min' => 'A nova senha precisa ter ao menos 8 caracteres.',
            'nova_senha.confirmed' => 'A confirmação não confere.',
            'nova_senha.different' => 'A nova senha precisa ser diferente da atual.',
        ];
    }
}
