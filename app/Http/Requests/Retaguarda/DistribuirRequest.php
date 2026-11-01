<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\ModoDistribuicao;
use App\Models\Edital;
use App\Support\Avaliacao\DistribuidorDeInscricoes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** O modo da distribuição: por categoria só em edital com categorias. */
class DistribuirRequest extends FormRequest
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
            'modo' => [
                'required',
                'string',
                Rule::in(array_map(fn (ModoDistribuicao $modo): string => $modo->value, DistribuidorDeInscricoes::modos($edital))),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'modo.required' => 'Escolha como distribuir.',
            'modo.in' => 'Escolha um dos modos de distribuição deste edital.',
        ];
    }

    public function modo(): ModoDistribuicao
    {
        return ModoDistribuicao::from($this->validated('modo'));
    }
}
