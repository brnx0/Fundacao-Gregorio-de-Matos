<?php

namespace App\Http\Requests\Avaliacao;

use App\Http\Controllers\Avaliacao\InscricoesDoAvaliadorController;
use App\Models\EditalCriterio;
use App\Models\Inscricao;
use App\Rules\TamanhoEmBytes;
use App\Support\Avaliacao\CriteriosDaInscricao;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Notas do avaliador pelo id do critério (vazia ou nula: deixar em branco) e a observação. Só os critérios que valem
 * para a inscrição; cada nota de 0 até a pontuação máxima do critério, com até 2 casas (vírgula ou ponto). Inscrição que
 * não é do avaliador da sessão dá 404 antes de validar.
 */
class SalvarNotasRequest extends FormRequest
{
    public const string ERRO_CRITERIO = 'Critério que não vale para esta inscrição.';

    public const string ERRO_FORMATO = 'Use um número de 0 em diante, com até 2 casas decimais.';

    public const string ERRO_MAXIMO = 'A nota de ":nome" vai de 0 a :maximo.';

    public function authorize(): bool
    {
        InscricoesDoAvaliadorController::avaliacaoDoAvaliador($this, $this->inscricao());

        return true;
    }

    /** Vírgula decimal vira ponto; quebras de linha "\r\n" contam como uma só no limite de caracteres. */
    protected function prepareForValidation(): void
    {
        $notas = $this->input('notas');

        if (is_array($notas)) {
            $this->merge(['notas' => array_map(fn (mixed $nota): mixed => is_string($nota) ? str_replace(',', '.', $nota) : $nota, $notas)]);
        }

        $observacao = $this->input('observacao');

        if (is_string($observacao)) {
            $this->merge(['observacao' => str_replace("\r\n", "\n", $observacao)]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notas' => ['nullable', 'array'],
            'notas.*' => ['bail', 'nullable', 'numeric', 'min:0', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'observacao' => ['nullable', 'string', 'max:4000', new TamanhoEmBytes(4000)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(['notas.*.numeric', 'notas.*.min', 'notas.*.regex'], self::ERRO_FORMATO);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('notas')) {
                    return;
                }

                $erros = self::errosDasNotas(CriteriosDaInscricao::para($this->inscricao()), $this->notasEnviadas());

                foreach ($erros as $campo => $mensagem) {
                    if (! $validator->errors()->has($campo)) {
                        $validator->errors()->add($campo, $mensagem);
                    }
                }
            },
        ];
    }

    /**
     * O que está errado nas notas diante dos critérios que valem: id de critério que não vale (no campo `notas`) e nota
     * acima da pontuação máxima (no campo da nota). O controller confere de novo com o edital travado.
     *
     * @param  Collection<int, EditalCriterio>  $criterios
     * @param  array<int|string, mixed>  $notas
     * @return array<string, string>
     */
    public static function errosDasNotas(Collection $criterios, array $notas): array
    {
        $porId = $criterios->keyBy(fn (EditalCriterio $criterio): int => (int) $criterio->id);
        $erros = [];

        foreach ($notas as $id => $nota) {
            $criterio = ctype_digit((string) $id) ? $porId->get((int) $id) : null;

            if ($criterio === null) {
                $erros['notas'] = self::ERRO_CRITERIO;

                continue;
            }

            if (! is_numeric($nota)) {
                continue;
            }

            $maximo = $criterio->pontos_maximos;

            if ($maximo === null || self::centesimos((string) $nota) > self::centesimos((string) $maximo)) {
                $erros["notas.{$id}"] = strtr(self::ERRO_MAXIMO, [
                    ':nome' => $criterio->nome,
                    ':maximo' => InscricoesDoAvaliadorController::numero($maximo ?? '0'),
                ]);
            }
        }

        return $erros;
    }

    /**
     * As notas enviadas pelo id do critério, com ponto decimal; nula quando deixada em branco.
     *
     * @return array<int|string, mixed>
     */
    public function notasEnviadas(): array
    {
        $notas = $this->input('notas');

        return is_array($notas) ? $notas : [];
    }

    public function observacao(): ?string
    {
        return $this->filled('observacao') ? $this->string('observacao')->toString() : null;
    }

    public function inscricao(): Inscricao
    {
        return $this->route('inscricao');
    }

    private static function centesimos(string $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
