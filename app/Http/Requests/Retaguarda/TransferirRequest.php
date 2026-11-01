<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * De qual avaliador saem as inscrições não concluídas e para qual vão (os vínculos com o edital, o controller confere
 * com o edital travado).
 */
class TransferirRequest extends FormRequest
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
            'de' => ['required', 'integer'],
            'para' => ['required', 'integer', 'different:de'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'de.required' => 'Escolha de quem saem as inscrições.',
            'de.integer' => 'Escolha de quem saem as inscrições.',
            'para.required' => 'Escolha para quem vão as inscrições.',
            'para.integer' => 'Escolha para quem vão as inscrições.',
            'para.different' => 'Escolha um avaliador diferente de quem as inscrições saem.',
        ];
    }

    public function de(): int
    {
        return (int) $this->validated('de');
    }

    public function para(): int
    {
        return (int) $this->validated('para');
    }
}
