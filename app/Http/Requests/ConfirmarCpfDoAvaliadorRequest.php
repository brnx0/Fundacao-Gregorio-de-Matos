<?php

namespace App\Http\Requests;

use App\Support\Cpf;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** CPF que o avaliador digita para confirmar o link pessoal. */
class ConfirmarCpfDoAvaliadorRequest extends FormRequest
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
            'cpf' => [
                'required',
                'string',
                fn (string $atributo, mixed $valor, Closure $falhar) => Cpf::valido((string) $valor) ? null : $falhar('CPF inválido. Confira os números.'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Este campo é obrigatório.',
            'string' => 'Digite um texto.',
        ];
    }

    /** Volta para a própria página de acesso: ela não fica guardada como "página anterior" (IniciarSessao). */
    protected function getRedirectUrl(): string
    {
        return $this->url();
    }
}
