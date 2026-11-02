<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\AnexoCadastro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Gerar com IA" e "Gerar instruções de todos" da tela Anexos do cadastro: só os campos, sem texto da tela (a IA
 * escreve a partir do rótulo, da descrição e dos dados que cada anexo leva). Nada disso é gravado.
 */
class GerarInstrucoesDosAnexosRequest extends FormRequest
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
            'campos' => ['required', 'array', 'list', 'max:'.count(AnexoCadastro::cases())],
            'campos.*' => ['required', 'distinct', Rule::enum(AnexoCadastro::class)],
        ];
    }

    /**
     * @return list<AnexoCadastro> Na ordem recebida.
     */
    public function anexos(): array
    {
        return array_map(fn (string $campo): AnexoCadastro => AnexoCadastro::from($campo), $this->validated('campos'));
    }
}
