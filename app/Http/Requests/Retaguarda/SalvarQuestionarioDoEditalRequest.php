<?php

namespace App\Http\Requests\Retaguarda;

use App\Http\Requests\Retaguarda\Concerns\MensagensDosCampos;
use App\Models\Edital;
use App\Models\ImportacaoQuestionario;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Questionário do edital, salvo na sua própria tela (QuestionarioDoEditalController). O formato está em
 * RegrasDoQuestionario; as perguntas se referem às categorias pela posição na ordem gravada, a mesma da tela.
 */
class SalvarQuestionarioDoEditalRequest extends FormRequest
{
    use MensagensDosCampos;

    /** Sem categoria não há a que vincular as perguntas: a tela nem abre, e o envio é recusado. */
    public function authorize(): bool
    {
        return $this->edital()->categorias()->exists();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categorias = $this->edital()->categorias()->count();

        return [
            'questionario_versao' => ['nullable', 'integer'],
            ...RegrasDoQuestionario::regras($categorias > 0 ? range(0, $categorias - 1) : []),
            // Sem o questionário no envio, nada é apagado.
            'questionario' => ['required', 'array'],
        ];
    }

    /**
     * Trava (o edital já tem inscrição finalizada), versão (uma importação concluiu com a tela aberta) e as regras
     * entre campos. Publicado não pode ter pergunta que não aparece a ninguém.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $edital = $this->edital();

                if ($edital->questionarioTravado()) {
                    $validator->errors()->add('questionario', Edital::AVISO_QUESTIONARIO_TRAVADO);

                    return;
                }

                $versao = $this->input('questionario_versao');
                if (ImportacaoQuestionario::versaoDoQuestionario($edital) !== ($versao === null || $versao === '' ? null : (int) $versao)) {
                    $validator->errors()->add('questionario', ImportacaoQuestionario::ERRO_VERSAO);

                    return;
                }

                RegrasDoQuestionario::conferir($validator, $this->input('questionario'));

                $semVinculo = $edital->publicado ? RegrasDoQuestionario::perguntasSemVinculo($this->input('questionario')) : 0;
                if ($semVinculo > 0) {
                    $validator->errors()->add('questionario', Edital::avisoSemVinculo($semVinculo));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            ...$this->mensagensDosCampos(),
            ...RegrasDoQuestionario::mensagens(),
        ];
    }

    /**
     * Questionário validado, no formato de SincronizadorDoQuestionario.
     *
     * @return array{secoes: list<array<string, mixed>>}
     */
    public function questionario(): array
    {
        return ['secoes' => array_values($this->validated('questionario.secoes') ?? [])];
    }

    private function edital(): Edital
    {
        $edital = $this->route('edital');
        assert($edital instanceof Edital);

        return $edital;
    }
}
