<?php

use App\Http\Controllers\Avaliacao\AcessoDoAvaliadorController;
use App\Http\Controllers\Avaliacao\InicioController;
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

    Route::middleware(['avaliador', 'inertia.encrypt'])->group(function () {
        Route::get('/', InicioController::class)->name('inicio');
    });
});
