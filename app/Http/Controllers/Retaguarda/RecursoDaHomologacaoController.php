<?php

namespace App\Http\Controllers\Retaguarda;

use App\Http\Controllers\Controller;
use App\Models\EditalDocumento;
use App\Models\Inscricao;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Recurso contra a não homologação, na retaguarda: os arquivos novos que o proponente enviou. */
class RecursoDaHomologacaoController extends Controller
{
    /** Abre na aba o arquivo novo enviado no recurso para o documento; 404 sem ele ou sem o arquivo no disco. */
    public function arquivo(Inscricao $inscricao, EditalDocumento $documento): StreamedResponse
    {
        $novo = $inscricao->recurso?->documentos()->where('edital_documento_id', $documento->id)->first();

        abort_unless($novo !== null && Storage::disk(Inscricao::DISCO)->exists($novo->arquivo), 404);

        return Storage::disk(Inscricao::DISCO)->response($novo->arquivo, $novo->arquivo_nome);
    }
}
