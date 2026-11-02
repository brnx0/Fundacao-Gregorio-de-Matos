<?php

namespace App\Http\Controllers\Retaguarda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\AjustarDescricaoRequest;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\RedatorDaDescricao;
use App\Support\FalhaNaIa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * "Ajustar com IA" da descrição do card do edital. Responde JSON e não grava nada: a descrição volta ao formulário e
 * só fica quando a equipe salva o edital.
 */
class DescricaoDoCardController extends Controller
{
    public function __invoke(AjustarDescricaoRequest $request, RedatorDaDescricao $redator): JsonResponse
    {
        try {
            $descricao = $redator->ajustar($request->edital());
        } catch (FalhaNaIa $falha) {
            // A equipe vê só a mensagem geral; o motivo de cada provedor (sem a chave, ver FalhaNaIa) fica para o administrador.
            Log::warning('Descrição do card por IA: o ajuste falhou.', ['motivo' => $falha->getMessage()]);

            return response()->json(['erro' => AnaliseComRedundancia::INDISPONIVEL], 422);
        }

        return response()->json(['descricao' => $descricao]);
    }
}
