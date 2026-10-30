<?php

use App\Http\Controllers\Auth\FakeGovBrController;
use App\Http\Controllers\Auth\GovBrController;
use App\Http\Controllers\Auth\GovBrRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CadastroController;
use App\Http\Controllers\ConsultaCadastroController;
use App\Http\Controllers\DocumentoModeloController;
use App\Http\Controllers\EditalController;
use App\Http\Controllers\EventoController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InscricaoController;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Http\Controllers\InscricaoRecursoController;
use App\Http\Controllers\InscricaoRespostaController;
use App\Http\Controllers\OrganizacaoController;
use App\Http\Controllers\PaginaController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\VinculoOrganizacaoController;
use Illuminate\Support\Facades\Route;

// Voltar para a home encerra a sessão do proponente (logo, "Voltar ao site" e Voltar do navegador).
Route::get('/', HomeController::class)->middleware('encerra-sessao-proponente')->name('home');
Route::get('/editais/{edital:slug}', [EditalController::class, 'show'])->name('editais.show');
Route::get('/editais/{edital:slug}/pdf', [EditalController::class, 'pdf'])->name('editais.pdf');
Route::get('/editais/{edital:slug}/documentos/{documento}/modelo', DocumentoModeloController::class)
    ->whereNumber('documento')
    ->scopeBindings()
    ->name('editais.documentos.modelo');
Route::get('/eventos/{evento:slug}', [EventoController::class, 'show'])->name('eventos.show');
Route::get('/eventos/{evento:slug}/detalhes', [EventoController::class, 'detalhes'])->name('eventos.detalhes');

Route::controller(PaginaController::class)->group(function () {
    Route::get('/a-fgm', 'aFgm')->name('paginas.a-fgm');
    Route::get('/duvidas', 'duvidas')->name('paginas.duvidas');
    Route::get('/privacidade', 'privacidade')->name('paginas.privacidade');
    Route::get('/termos', 'termos')->name('paginas.termos');
});

// inertia.encrypt: as telas com dados pessoais ficam cifradas no histórico do navegador e deixam
// de abrir pelo Voltar depois que a sessão termina (Inertia::clearHistory em EncerrarSessao).
Route::middleware(['auth', 'nao-gestor', 'inertia.encrypt'])->controller(CadastroController::class)->group(function () {
    Route::get('/cadastro', 'completar')->name('cadastro');
    Route::post('/cadastro', 'salvar')->name('cadastro.salvar');
});

Route::middleware(['auth', 'nao-gestor', 'cadastro-completo', 'inertia.encrypt'])->controller(CadastroController::class)->group(function () {
    Route::get('/cadastro/organizacao', 'organizacao')->name('cadastro.organizacao');
    Route::get('/cadastro/tipo', 'tipo')->name('cadastro.tipo');
    Route::post('/cadastro/tipo', 'salvarTipo')->name('cadastro.tipo.salvar');
});

// Telas do menu do portal, conforme o tipo de cadastro escolhido na última etapa.
Route::middleware(['auth', 'nao-gestor', 'cadastro-completo', 'inertia.encrypt'])->controller(PortalController::class)->group(function () {
    Route::get('/portal/editais', 'editais')->middleware('tipo-cadastro:proponente')->name('portal.editais');
    Route::get('/portal/editais/{edital:slug}/inscricao', 'inscricao')->middleware('tipo-cadastro:proponente')->name('portal.editais.inscricao');
    Route::get('/portal/projetos', 'projetos')->middleware('tipo-cadastro:patrocinador')->name('portal.projetos');
});

// A inscrição é só de quem a criou (InscricaoPolicy): para os outros, 404. whereNumber: id não numérico não chega ao Oracle (ORA-01722).
Route::middleware(['auth', 'nao-gestor', 'cadastro-completo', 'inertia.encrypt', 'tipo-cadastro:proponente'])->controller(InscricaoController::class)->group(function () {
    Route::get('/portal/inscricoes', 'index')->name('portal.inscricoes.index');
    Route::post('/portal/editais/{edital:slug}/inscricoes', 'store')->name('portal.inscricoes.store');
    Route::get('/portal/inscricoes/{inscricao}', 'show')->whereNumber('inscricao')->name('portal.inscricoes.show');
    Route::put('/portal/inscricoes/{inscricao}', 'update')->whereNumber('inscricao')->name('portal.inscricoes.update');
    Route::get('/portal/inscricoes/{inscricao}/situacao', 'situacao')->whereNumber('inscricao')->name('portal.inscricoes.situacao');
    Route::post('/portal/inscricoes/{inscricao}/finalizar', 'finalizar')->whereNumber('inscricao')->name('portal.inscricoes.finalizar');
    Route::post('/portal/inscricoes/{inscricao}/reabrir', 'reabrir')->whereNumber('inscricao')->name('portal.inscricoes.reabrir');

    // {documento} é o id em edital_documentos; se a inscrição não o exige, 404.
    Route::controller(InscricaoDocumentoController::class)->group(function () {
        Route::post('/portal/inscricoes/{inscricao}/documentos/{documento}', 'store')->middleware('throttle:30,1')->whereNumber(['inscricao', 'documento'])->name('portal.inscricoes.documentos.store');
        Route::delete('/portal/inscricoes/{inscricao}/documentos/{documento}', 'destroy')->whereNumber(['inscricao', 'documento'])->name('portal.inscricoes.documentos.destroy');
        Route::get('/portal/inscricoes/{inscricao}/documentos/{documento}/arquivo', 'arquivo')->whereNumber(['inscricao', 'documento'])->name('portal.inscricoes.documentos.arquivo');
        Route::post('/portal/inscricoes/{inscricao}/documentos/{documento}/conferir', 'conferir')->middleware('throttle:10,1,conferir-documento')->whereNumber(['inscricao', 'documento'])->name('portal.inscricoes.documentos.conferir');
    });

    // Recurso contra a não homologação; o throttle com chave própria não divide a contagem com o envio de documentos.
    Route::controller(InscricaoRecursoController::class)->group(function () {
        Route::get('/portal/inscricoes/{inscricao}/recurso', 'create')->whereNumber('inscricao')->name('portal.inscricoes.recurso.create');
        Route::post('/portal/inscricoes/{inscricao}/recurso', 'store')->middleware('throttle:10,1,recurso-da-inscricao')->whereNumber('inscricao')->name('portal.inscricoes.recurso.store');
    });

    // {secao} é uma seção do questionário do edital da inscrição; de outro edital, 404.
    Route::put('/portal/inscricoes/{inscricao}/secoes/{secao}', [InscricaoRespostaController::class, 'update'])->whereNumber(['inscricao', 'secao'])->name('portal.inscricoes.secoes.update');
});

Route::middleware(['auth', 'nao-gestor', 'cadastro-completo', 'inertia.encrypt'])->controller(OrganizacaoController::class)->group(function () {
    Route::get('/organizacoes', 'index')->name('organizacoes.index');
    Route::get('/cadastro/pessoa-juridica', 'create')->name('organizacoes.create');
    Route::post('/organizacoes', 'store')->name('organizacoes.store');
    Route::get('/organizacoes/{organizacao}/editar', 'edit')->whereNumber('organizacao')->name('organizacoes.edit');
    Route::put('/organizacoes/{organizacao}', 'update')->whereNumber('organizacao')->name('organizacoes.update');
});

// scopeBindings: o vínculo precisa ser da organização da URL, senão 404. whereNumber: id não numérico não chega ao Oracle (ORA-01722).
Route::middleware(['auth', 'nao-gestor', 'cadastro-completo'])->controller(VinculoOrganizacaoController::class)->group(function () {
    Route::get('/organizacoes/{organizacao}/pessoas', 'index')->whereNumber('organizacao')->name('organizacoes.vinculos');
    Route::post('/organizacoes/{organizacao}/pessoas', 'store')->whereNumber('organizacao')->name('organizacoes.vinculos.store');
    Route::delete('/organizacoes/{organizacao}/pessoas/{vinculo}', 'destroy')->whereNumber(['organizacao', 'vinculo'])->scopeBindings()->name('organizacoes.vinculos.destroy');
});

// Limitador 'consultas' (AppServiceProvider): 30/min por usuário.
Route::middleware(['auth', 'nao-gestor', 'throttle:consultas'])->controller(ConsultaCadastroController::class)->group(function () {
    Route::get('/cadastro/consultas/cnpj/{cnpj}', 'cnpj')->name('consultas.cnpj');
    Route::get('/cadastro/consultas/cep/{cep}', 'cep')->name('consultas.cep');
    Route::get('/cadastro/consultas/municipios', 'municipios')->name('consultas.municipios');
});

Route::post('/logout', LogoutController::class)->name('logout');

// Limitador 'govbr' (AppServiceProvider): 10/min por rota e por IP.
Route::middleware('throttle:govbr')->group(function () {
    Route::get('/auth/govbr/redirect', GovBrRedirectController::class)->name('govbr.redirect');

    Route::controller(GovBrController::class)->group(function () {
        Route::get('/auth/govbr/callback', 'callback')->name('govbr.callback');
        Route::get('/auth/govbr/logout', 'logout')->name('govbr.logout');
    });

    Route::controller(FakeGovBrController::class)->group(function () {
        Route::get('/auth/govbr/fake', 'create')->name('govbr.fake');
        Route::post('/auth/govbr/fake', 'store');
    });
});

require __DIR__.'/retaguarda.php';
