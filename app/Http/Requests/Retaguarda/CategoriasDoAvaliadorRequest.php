<?php

namespace App\Http\Requests\Retaguarda;

use App\Http\Controllers\Retaguarda\AvaliadoresDaAvaliacaoController;
use App\Models\Edital;
use App\Models\EditalCategoria;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Categorias que o avaliador avalia no edital (lista vazia: nenhuma). Só categorias deste edital. */
class CategoriasDoAvaliadorRequest extends FormRequest
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
            'categorias' => [
                'present',
                'array',
                // Os ids não inteiros já falham em categorias.*; aqui vale que todos sejam categorias deste edital.
                function (string $atributo, mixed $valor, Closure $falhar) use ($edital): void {
                    if (! is_array($valor)) {
                        return;
                    }

                    $ids = array_values(array_unique(array_map(
                        intval(...),
                        array_filter($valor, fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false),
                    )));
                    $doEdital = $ids === [] ? 0 : EditalCategoria::query()->where('edital_id', $edital->id)->whereKey($ids)->count();

                    if ($doEdital !== count($ids)) {
                        $falhar(AvaliadoresDaAvaliacaoController::ERRO_CATEGORIAS);
                    }
                },
            ],
            'categorias.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Os ids escolhidos.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_map(intval(...), $this->validated('categorias')));
    }
}
