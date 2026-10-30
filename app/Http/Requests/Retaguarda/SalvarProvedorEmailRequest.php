<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\CriptografiaEmail;
use App\Models\ConfiguracaoEmail;
use App\Rules\TamanhoEmBytes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/** Servidor SMTP, conta e remetente dos e-mails do sistema. Senha em branco mantém a guardada. */
class SalvarProvedorEmailRequest extends FormRequest
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
        return [
            'ativo' => ['required', 'boolean'],
            // Nome de servidor ou IP: letras, números, ponto e hífen.
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'porta' => ['required', 'integer', 'between:1,65535'],
            'criptografia' => ['required', Rule::enum(CriptografiaEmail::class)],
            'usuario' => ['required', 'string', 'max:255'],
            'senha' => [ConfiguracaoEmail::atual()->senhaGuardada() === null ? 'required' : 'nullable', 'string', 'max:255'],
            'remetente_email' => ['required', 'string', 'email', 'max:255'],
            'remetente_nome' => ['required', 'string', 'max:120', new TamanhoEmBytes(120)],
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
            'boolean' => 'Valor inválido.',
            'max' => 'Use no máximo :max caracteres.',
            'host.regex' => 'Digite um endereço de servidor válido, como smtp.gmail.com.',
            'porta.integer' => 'Informe uma porta de 1 a 65535.',
            'porta.between' => 'Informe uma porta de 1 a 65535.',
            'senha.required' => 'Informe a senha de app.',
            'remetente_email.email' => 'Digite um e-mail válido.',
            Enum::class => 'Selecione uma opção da lista.',
        ];
    }

    /** E-mails chegam como digitados; valem sem espaços. O host, sem espaços nas pontas. */
    protected function prepareForValidation(): void
    {
        foreach (['host', 'usuario', 'remetente_email'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo))]);
            }
        }
    }

    /**
     * Dados para gravar; sem a senha quando ela veio em branco.
     *
     * @return array<string, mixed>
     */
    public function dados(): array
    {
        $dados = $this->safe()->except('senha');
        $senha = $this->validated('senha');

        return filled($senha) ? [...$dados, 'senha' => $senha] : $dados;
    }
}
