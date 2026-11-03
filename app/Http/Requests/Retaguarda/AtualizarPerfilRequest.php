<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dados do próprio usuário da retaguarda: nome, e-mail e telefone. O e-mail é o login,
 * então trocá-lo pede a senha atual (quem entra só pelo gov.br não tem senha e não informa).
 * CPF, papéis e situação só mudam pela tela Usuários.
 */
class AtualizarPerfilRequest extends FormRequest
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
        $usuario = $this->user();
        $pedeSenha = $usuario->password !== null && $this->emailMudou();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario->id)],
            'telefone' => ['nullable', 'digits_between:10,11'],
            'senha_atual' => $pedeSenha ? ['required', 'string', 'current_password:retaguarda'] : ['nullable'],
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
            'max' => 'Use no máximo :max caracteres.',
            'email.email' => 'Digite um e-mail válido.',
            'email.unique' => 'Este e-mail já está em uso.',
            'telefone.digits_between' => 'Digite o telefone com DDD.',
            'senha_atual.required' => 'Informe sua senha atual para trocar o e-mail.',
            'senha_atual.current_password' => 'A senha atual está incorreta.',
        ];
    }

    /** O e-mail chega como digitado; compara já normalizado, como o model grava. */
    public function emailMudou(): bool
    {
        $novo = mb_strtolower(trim((string) $this->input('email')));

        return $novo !== (string) $this->user()->email;
    }

    /** Telefone chega com máscara; fica só com os números. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('telefone'))) {
            $this->merge(['telefone' => preg_replace('/\D/', '', $this->input('telefone'))]);
        }
    }
}
