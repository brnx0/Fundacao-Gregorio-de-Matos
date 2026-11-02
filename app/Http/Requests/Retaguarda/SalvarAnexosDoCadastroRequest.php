<?php

namespace App\Http\Requests\Retaguarda;

use App\Enums\AnexoCadastro;
use App\Rules\TamanhoEmBytes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Para cada anexo do cadastro, se a IA confere o arquivo e o que ela verifica. O texto é obrigatório só com "Validar
 * com IA"; com a validação dispensada ele continua sendo salvo, para não se perder quando ela voltar.
 */
class SalvarAnexosDoCadastroRequest extends FormRequest
{
    /** O mesmo tamanho da coluna configuracoes_anexos.instrucao_ia (e da instrução gerada pela IA). */
    public const int TAMANHO_DA_INSTRUCAO = 2000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Um grupo por anexo, pela chave do campo: anexos.{campo}.validar_ia e anexos.{campo}.instrucao_ia.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $regras = ['anexos' => ['required', 'array:'.implode(',', AnexoCadastro::valores())]];

        foreach (AnexoCadastro::valores() as $campo) {
            $regras["anexos.{$campo}"] = ['required', 'array'];
            $regras["anexos.{$campo}.validar_ia"] = ['required', 'boolean'];
            $regras["anexos.{$campo}.instrucao_ia"] = [
                "required_if_accepted:anexos.{$campo}.validar_ia",
                'nullable',
                'string',
                'max:'.self::TAMANHO_DA_INSTRUCAO,
                new TamanhoEmBytes(self::TAMANHO_DA_INSTRUCAO),
            ];
        }

        return $regras;
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
            'array' => 'Valor inválido.',
            'boolean' => 'Valor inválido.',
            'string' => 'Digite um texto.',
            'max' => 'Use no máximo :max caracteres.',
            'anexos.*.validar_ia.required' => 'Escolha se a IA valida este documento.',
            'anexos.*.instrucao_ia.required_if_accepted' => 'Descreva o que a IA deve conferir neste documento ou use Gerar com IA.',
        ];
    }

    /**
     * O que gravar de cada anexo, na ordem do enum; texto vazio vira null.
     *
     * @return array<string, array{validar_ia: bool, instrucao_ia: string|null}>
     */
    public function anexos(): array
    {
        $anexos = [];

        foreach (AnexoCadastro::valores() as $campo) {
            $instrucao = $this->validated("anexos.{$campo}.instrucao_ia");

            $anexos[$campo] = [
                'validar_ia' => $this->boolean("anexos.{$campo}.validar_ia"),
                'instrucao_ia' => blank($instrucao) ? null : $instrucao,
            ];
        }

        return $anexos;
    }
}
