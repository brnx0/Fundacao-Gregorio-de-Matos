<?php

use App\Http\Controllers\Avaliacao\AcessoDoAvaliadorController;
use App\Http\Controllers\Avaliacao\InicioController;
use App\Http\Controllers\Avaliacao\InscricoesDoAvaliadorController;
use Illuminate\Support\Facades\Route;

// Área do avaliador: sem conta de usuário, entra pelo link pessoal do e-mail e o CPF.
Route::prefix('avaliacao')->name('avaliacao.')->group(function () {
    Route::controller(AcessoDoAvaliadorController::class)->group(function () {
        Route::get('/acesso/{codigo}', 'create')->where('codigo', '[A-Za-z0-9]{48}')->name('acesso');
        Route::post('/acesso/{codigo}', 'store')
            ->where('codigo', '[A-Za-z0-9]{48}')
            ->middleware('throttle:avaliacao-cpf')
            ->name('acesso.confirmar');
        Route::post('/sair', 'destroy')->name('sair');
    });

    // As inscrições dele, com nome e documento do proponente: as páginas cifradas no histórico. Inscrição que não é do
    // avaliador da sessão dá 404 em todas (o controller confere). Os arquivos saem só pela rota de arquivo.
    Route::middleware('avaliador')->group(function () {
        Route::middleware('inertia.encrypt')->group(function () {
            Route::get('/', InicioController::class)->name('inicio');
            Route::get('/inscricoes/{inscricao}', [InscricoesDoAvaliadorController::class, 'show'])
                ->whereNumber('inscricao')
                ->name('inscricoes.show');
        });

        Route::controller(InscricoesDoAvaliadorController::class)->group(function () {
            Route::get('/inscricoes/{inscricao}/documentos/{documento}/arquivo', 'arquivo')
                ->whereNumber(['inscricao', 'documento'])
                ->name('inscricoes.arquivo');
            Route::put('/inscricoes/{inscricao}/notas', 'salvar')
                ->whereNumber('inscricao')
                ->name('inscricoes.notas');
            Route::post('/inscricoes/{inscricao}/conclusao', 'concluir')
                ->whereNumber('inscricao')
                ->name('inscricoes.conclusao');
        });
    });
});
