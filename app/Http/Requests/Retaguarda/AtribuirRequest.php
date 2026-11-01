<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** O avaliador que recebe a inscrição (se é ativo e do edital, o controller confere com o edital travado). */
class AtribuirRequest extends FormRequest
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
            'avaliador_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avaliador_id.required' => 'Escolha o avaliador.',
            'avaliador_id.integer' => 'Escolha o avaliador.',
        ];
    }

    public function avaliadorId(): int
    {
        return (int) $this->validated('avaliador_id');
    }
}
