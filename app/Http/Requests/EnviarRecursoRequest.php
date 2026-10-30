<?php

namespace App\Http\Requests;

use App\Http\Controllers\InscricaoDocumentoController;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use App\Support\Inscricao\ConferenciaDaInscricao;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Recurso do proponente contra a não homologação: a justificativa, arquivos novos (pelo id do documento do edital) só
 * para os documentos marcados com problema, ou os dois. Texto só com espaços chega nulo (o framework apara) e conta
 * como vazio. Só do dono (InscricaoPolicy): para os outros, 404 antes de validar.
 */
class EnviarRecursoRequest extends FormRequest
{
    public const string ERRO_VAZIO = 'Escreva a justificativa ou envie ao menos um documento.';

    public const string ERRO_DOCUMENTO = 'Só os documentos marcados com problema podem ser trocados.';

    public function authorize(): bool
    {
        Gate::authorize('gerenciar', $this->inscricao());

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'justificativa' => ['nullable', 'string', 'max:4000'],
            'arquivos' => ['nullable', 'array'],
            'arquivos.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:'.implode(',', Inscricao::TIPOS_DE_ARQUIVO), 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_fill_keys(
            ['arquivos.*.file', 'arquivos.*.mimes', 'arquivos.*.mimetypes', 'arquivos.*.max', 'arquivos.*.uploaded'],
            InscricaoDocumentoController::ERRO_ARQUIVO,
        );
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! filled($this->input('justificativa')) && $this->arquivos() === []) {
                    $validator->errors()->add('justificativa', self::ERRO_VAZIO);
                }

                $comProblema = (new ConferenciaDaInscricao($this->inscricao()))->documentosComProblema()
                    ->map(fn (EditalDocumento $documento): int => (int) $documento->id)
                    ->all();

                foreach (array_keys($this->arquivos()) as $documentoId) {
                    if (! in_array((int) $documentoId, $comProblema, true)) {
                        $validator->errors()->add('arquivos', self::ERRO_DOCUMENTO);

                        return;
                    }
                }
            },
        ];
    }

    /**
     * Os arquivos enviados, pelo id do documento do edital.
     *
     * @return array<int|string, UploadedFile>
     */
    public function arquivos(): array
    {
        $arquivos = $this->file('arquivos');

        return is_array($arquivos) ? $arquivos : [];
    }

    public function inscricao(): Inscricao
    {
        return $this->route('inscricao');
    }
}
