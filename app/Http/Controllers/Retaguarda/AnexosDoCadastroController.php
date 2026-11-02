<?php

namespace App\Http\Controllers\Retaguarda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\SalvarAnexosDoCadastroRequest;
use App\Models\ConfiguracaoAnexo;
use App\Models\ConfiguracaoIa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anexos do cadastro (Parametrização): para cada arquivo da etapa Anexos do portal, se a IA o confere e o que ela
 * verifica. Vale para os próximos envios; só confere de fato com a validação por IA ligada na Integração com IA.
 */
class AnexosDoCadastroController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Retaguarda/AnexosDoCadastro', [
            'anexos' => array_values(array_map(fn (ConfiguracaoAnexo $configuracao): array => [
                'campo' => $configuracao->campo->value,
                'rotulo' => $configuracao->campo->rotulo(),
                'descricao' => $configuracao->campo->descricao(),
                'obrigatorio' => $configuracao->campo->obrigatorio(),
                'validar_ia' => $configuracao->validar_ia,
                'instrucao_ia' => $configuracao->instrucao_ia ?? '',
            ], ConfiguracaoAnexo::porCampo())),
            'validacaoLigada' => ConfiguracaoIa::validacaoLigada(),
        ]);
    }

    public function update(SalvarAnexosDoCadastroRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            foreach ($request->anexos() as $campo => $parametros) {
                ConfiguracaoAnexo::query()->updateOrCreate(['campo' => $campo], $parametros);
            }
        });

        return redirect()->route('retaguarda.anexos-cadastro.edit')->with('sucesso', 'Anexos do cadastro salvos.');
    }
}
