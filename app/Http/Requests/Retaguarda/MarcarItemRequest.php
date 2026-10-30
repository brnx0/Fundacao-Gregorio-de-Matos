<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\MarcacaoDoItem;
use App\Rules\TamanhoEmBytes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marcação do gestor sobre um documento ou uma pergunta na conferência da inscrição. A observação é obrigatória com
 * problema; texto só com espaços chega nulo (o framework apara) e conta como vazio.
 */
class MarcarItemRequest extends FormRequest
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
            'tipo' => ['required', 'in:documento,pergunta'],
            'item' => ['required', 'integer'],
            'situacao' => ['required', Rule::enum(MarcacaoDoItem::class)],
            'observacao' => ['nullable', 'string', 'max:1000', new TamanhoEmBytes(1000), 'required_if:situacao,problema'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'observacao.required_if' => 'Descreva o problema.',
        ];
    }
}
