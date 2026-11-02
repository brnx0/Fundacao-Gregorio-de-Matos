<?php

namespace App\Http\Requests\Retaguarda;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * "Ajustar com IA" da descrição do card (aba Publicação do edital). Vem do formulário, que pode ainda não estar salvo:
 * o texto como está na tela e o contexto do edital. Os limites são os do SalvarEditalRequest; nada disso é gravado.
 */
class AjustarDescricaoRequest extends FormRequest
{
    public const string SEM_CONTEXTO = 'Preencha o título, o objeto ou um rascunho da descrição antes de usar a IA.';

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
            'titulo' => ['nullable', 'string', 'max:255'],
            'objeto' => ['nullable', 'string', 'max:1000'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'texto' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Sem título, objeto nem texto, a IA não tem de onde tirar a descrição (e inventaria).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (blank($this->input('titulo')) && blank($this->input('objeto')) && blank($this->input('texto'))) {
                    $validator->errors()->add('texto', self::SEM_CONTEXTO);
                }
            },
        ];
    }

    /**
     * @return array{titulo: string|null, objeto: string|null, categoria: string|null, texto: string|null}
     */
    public function edital(): array
    {
        return [
            'titulo' => $this->validated('titulo'),
            'objeto' => $this->validated('objeto'),
            'categoria' => $this->validated('categoria'),
            'texto' => $this->validated('texto'),
        ];
    }
}
