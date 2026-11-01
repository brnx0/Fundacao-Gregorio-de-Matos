<?php

namespace App\Http\Requests\Retaguarda;

use App\Models\Edital;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Avaliadores a incluir no edital: só os ativos do Cadastro que ainda não estão nele. */
class IncluirAvaliadoresRequest extends FormRequest
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
        /** @var Edital $edital */
        $edital = $this->route('edital');

        return [
            'avaliadores' => ['required', 'array', 'min:1', 'max:200'],
            'avaliadores.*' => [
                'integer',
                'distinct',
                Rule::exists('avaliadores', 'id')->where('ativo', true),
                Rule::unique('edital_avaliadores', 'avaliador_id')->where('edital_id', $edital->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avaliadores.required' => 'Escolha ao menos um avaliador.',
            'avaliadores.min' => 'Escolha ao menos um avaliador.',
            'avaliadores.*.exists' => 'Escolha avaliadores ativos do Cadastro.',
            'avaliadores.*.unique' => 'Este avaliador já está no edital.',
        ];
    }

    /**
     * Os ids escolhidos, sem repetição.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_unique(array_map(intval(...), $this->validated('avaliadores'))));
    }
}
