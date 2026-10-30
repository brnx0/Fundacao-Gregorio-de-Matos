<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisão do gestor sobre a inscrição conferida: homologada ou não homologada, com o motivo opcional (sem texto, a
 * não homologação usa o motivo montado dos itens com problema). Texto só com espaços chega nulo (o framework apara) e
 * conta como vazio.
 */
class DecidirHomologacaoRequest extends FormRequest
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
            'decisao' => ['required', 'in:homologada,nao_homologada'],
            'motivo' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
