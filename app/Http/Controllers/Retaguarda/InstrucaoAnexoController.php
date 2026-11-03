<?php

namespace App\Http\Controllers\Retaguarda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\GerarInstrucoesDosAnexosRequest;
use App\Support\DocumentoIa\GeradorDeInstrucoesDosAnexos;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\FalhaNaIa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Instruções da IA para os anexos do cadastro ("Gerar com IA" num anexo, "Gerar instruções de todos" na tela).
 * Responde JSON e não grava nada: as instruções voltam ao formulário e só ficam quando a equipe salva.
 */
class InstrucaoAnexoController extends Controller
{
    public function __invoke(GerarInstrucoesDosAnexosRequest $request, GeradorDeInstrucoesDosAnexos $gerador): JsonResponse
    {
        try {
            $instrucoes = $gerador->gerar($request->anexos());
        } catch (FalhaNaIa $falha) {
            // A equipe vê só a mensagem geral; o motivo de cada provedor (sem a chave, ver FalhaNaIa) fica para o administrador.
            Log::warning('Instruções dos anexos por IA: a geração falhou.', ['motivo' => $falha->getMessage()]);

            return response()->json(['erro' => AnaliseComRedundancia::INDISPONIVEL], 422);
        }

        return response()->json(['instrucoes' => $instrucoes]);
    }
}
