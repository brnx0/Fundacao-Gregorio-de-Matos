<?php

namespace App\Http\Requests;

use App\Enums\AnexoCadastro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Um arquivo da etapa Anexos, enviado assim que a pessoa o escolhe: até 2 MB, nos tipos do campo (a foto de perfil só
 * como imagem). O tipo é conferido pela extensão e pelo conteúdo, que é o que a conferência por IA lê.
 */
class EnviarAnexoRequest extends FormRequest
{
    public const string ERRO_IMAGEM = 'Envie uma imagem JPG ou PNG de até 2 MB.';

    public const string ERRO_DOCUMENTO = 'Envie um PDF ou uma imagem JPG ou PNG de até 2 MB.';

    /** 2 MB (max em KB). */
    private const int TAMANHO_MAXIMO_KB = 2048;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $campo = $this->campo();

        return [
            'arquivo' => [
                'required',
                'file',
                'mimes:'.implode(',', $campo->extensoes()),
                'mimetypes:'.implode(',', $campo->tiposDeArquivo()),
                'max:'.self::TAMANHO_MAXIMO_KB,
            ],
        ];
    }

    /**
     * Uma só mensagem para qualquer recusa do arquivo, como nos documentos da inscrição.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(
            ['arquivo.required', 'arquivo.file', 'arquivo.mimes', 'arquivo.mimetypes', 'arquivo.max', 'arquivo.uploaded'],
            $this->campo()->somenteImagem() ? self::ERRO_IMAGEM : self::ERRO_DOCUMENTO,
        );
    }

    /** O campo da rota (a rota só aceita os valores de AnexoCadastro). */
    public function campo(): AnexoCadastro
    {
        $campo = $this->route('campo');

        return $campo instanceof AnexoCadastro ? $campo : AnexoCadastro::from((string) $campo);
    }
}
