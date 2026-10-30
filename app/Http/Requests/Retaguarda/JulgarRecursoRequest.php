<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\DecisaoRecurso;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Julgamento do recurso pelo gestor: aceito ou negado, sempre com o parecer. Texto só com espaços chega nulo (o
 * framework apara) e conta como vazio.
 */
class JulgarRecursoRequest extends FormRequest
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
            'decisao' => ['required', Rule::enum(DecisaoRecurso::class)],
            'parecer' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parecer.required' => 'Escreva o parecer.',
        ];
    }
}
