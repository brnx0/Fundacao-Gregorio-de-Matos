<?php

use App\Http\Controllers\Retaguarda\AreaAtuacaoController;
use App\Http\Controllers\Retaguarda\AvaliadorController;
use App\Http\Controllers\Retaguarda\ConexaoIaController;
use App\Http\Controllers\Retaguarda\EditalController;
use App\Http\Controllers\Retaguarda\EventoController;
use App\Http\Controllers\Retaguarda\ImportacaoEditalController;
use App\Http\Controllers\Retaguarda\ImportacaoQuestionarioController;
use App\Http\Controllers\Retaguarda\InstrucaoDocumentoController;
use App\Http\Controllers\Retaguarda\IntegracaoIaController;
use App\Http\Controllers\Retaguarda\LocalizacaoEventoController;
use App\Http\Controllers\Retaguarda\LoginController;
use App\Http\Controllers\Retaguarda\MinhaSenhaController;
use App\Http\Controllers\Retaguarda\PainelController;
use App\Http\Controllers\Retaguarda\PerfilController;
use App\Http\Controllers\Retaguarda\PrefeituraBairroController;
use App\Http\Controllers\Retaguarda\ProponenteController;
use App\Http\Controllers\Retaguarda\QuestionarioDoEditalController;
use App\Http\Controllers\Retaguarda\UsuarioController;
use App\Http\Controllers\Retaguarda\VLibrasController;
use App\Models\PessoaFisica;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('retaguarda')->name('retaguarda.')->group(function () {
    Route::controller(LoginController::class)->group(function () {
        Route::get('/login', 'create')->name('login');
        // Limitador 'retaguarda-login' (AppServiceProvider): 5/min por e-mail e IP.
        Route::post('/login', 'store')->middleware('throttle:retaguarda-login')->name('login.entrar');
        Route::post('/sair', 'destroy')->name('sair');
    });

    // Segue o fluxo gov.br comum (real ou simulado); o destino vale só para esta tentativa (ver GovBrRedirectController).
    Route::get('/govbr', function (): RedirectResponse {
        return redirect()->route('govbr.redirect', ['destino' => 'retaguarda']);
    })->middleware('throttle:govbr')->name('govbr');

    Route::middleware(['auth', 'gestor'])->group(function () {
        Route::get('/', function (): RedirectResponse {
            // Mantém avisos (ex.: limite de tentativas, vindo do login) através deste redirecionamento.
            session()->reflash();

            return to_route('retaguarda.painel');
        })->name('inicio');

        // Tela inicial: números agregados dos cadastros, editais e inscrições, com filtros na query string.
        Route::get('/painel', PainelController::class)->name('painel');

        // Importação do edital do PDF com IA (análise em segundo plano). Antes do resource, para não cair em editais/{edital}.
        Route::controller(ImportacaoEditalController::class)->prefix('/editais/importacoes')->name('editais.importacoes.')->group(function () {
            Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
            Route::get('/{importacao}', 'show')->whereNumber('importacao')->name('show');
            Route::post('/{importacao}/nova-tentativa', 'tentar')->whereNumber('importacao')->middleware('throttle:10,1')->name('tentar');
            Route::delete('/{importacao}', 'destroy')->whereNumber('importacao')->name('destroy');
        });
        Route::post('/editais/{edital}/importacao/conferida', [ImportacaoEditalController::class, 'conferida'])->name('editais.importacao.conferida');
        // Importação do questionário do edital com IA (PDF ou Word; análise em segundo plano). Antes do resource.
        Route::controller(ImportacaoQuestionarioController::class)->name('editais.questionario.')->group(function () {
            Route::post('/editais/{edital}/questionario/importacoes', 'store')->middleware('throttle:10,1')->name('importacoes.store');
            Route::get('/editais/questionario/importacoes/{importacao}', 'show')->whereNumber('importacao')->name('importacoes.show');
            Route::post('/editais/{edital}/questionario/importacao/conferida', 'conferida')->name('importacao.conferida');
        });
        // Questionário do edital na sua própria tela, aberta pelo ícone da lista. Antes do resource.
        Route::controller(QuestionarioDoEditalController::class)->name('editais.questionario.')->group(function () {
            Route::get('/editais/{edital}/questionario', 'edit')->name('edit');
            Route::put('/editais/{edital}/questionario', 'update')->name('update');
        });
        // Instruções da IA para os documentos, do formulário (edital salvo ou não); responde JSON. Cada chamada pode
        // custar: limite próprio (o prefixo separa esta contagem da importação e dos outros limites por usuário).
        Route::post('/editais/instrucoes-ia', InstrucaoDocumentoController::class)
            ->middleware('throttle:10,1,instrucoes-ia')
            ->name('editais.instrucoes-ia');

        // show abre o formulário só para ler (ícone Visualizar da lista).
        Route::resource('editais', EditalController::class)
            ->parameters(['editais' => 'edital']);
        Route::patch('/editais/{edital}/publicacao', [EditalController::class, 'publicacao'])->name('editais.publicacao');
        Route::get('/editais/{edital}/pdf', [EditalController::class, 'pdf'])->name('editais.pdf');
        Route::get('/editais/{edital}/documentos/{documento}/modelo', [EditalController::class, 'modelo'])
            ->whereNumber('documento')
            ->scopeBindings()
            ->name('editais.documentos.modelo');

        // Imagens da descrição (editor de texto rico): responde JSON com a URL.
        Route::post('/eventos/conteudo/imagem', [EventoController::class, 'imagemConteudo'])
            ->middleware('throttle:30,1')
            ->name('eventos.conteudo.imagem');
        // Coordenadas do endereço para o pin do mapa (Nominatim): limite próprio para não abusar do serviço.
        Route::get('/eventos/localizar', LocalizacaoEventoController::class)
            ->middleware('throttle:20,1')
            ->name('eventos.localizar');
        Route::resource('eventos', EventoController::class)->except('show');
        Route::patch('/eventos/{evento}/publicacao', [EventoController::class, 'publicacao'])->name('eventos.publicacao');

        // VLibras (tradutor de Libras) nas páginas fora da retaguarda.
        Route::get('/vlibras', [VLibrasController::class, 'edit'])->name('vlibras.edit');
        Route::put('/vlibras', [VLibrasController::class, 'update'])->name('vlibras.update');

        Route::get('/perfil', [PerfilController::class, 'edit'])->name('perfil.edit');
        Route::put('/perfil', [PerfilController::class, 'update'])->middleware('throttle:6,1')->name('perfil.update');

        Route::get('/minha-senha', [MinhaSenhaController::class, 'edit'])->name('senha.edit');
        Route::put('/minha-senha', [MinhaSenhaController::class, 'update'])->middleware('throttle:6,1')->name('senha.update');

        // Usuário não se exclui, só se desativa.
        Route::resource('usuarios', UsuarioController::class)
            ->only(['index', 'create', 'store', 'edit', 'update'])
            ->parameters(['usuarios' => 'user']);
        Route::patch('/usuarios/{user}/ativo', [UsuarioController::class, 'ativo'])->name('usuarios.ativo');

        // Cadastros do portal, só para consulta. As telas ficam cifradas no histórico, como as de dados
        // pessoais do portal; o anexo aceita só as colunas de arquivo da pessoa física.
        Route::controller(ProponenteController::class)->group(function () {
            Route::middleware('inertia.encrypt')->group(function () {
                Route::get('/proponentes', 'index')->name('proponentes.index');
                Route::get('/proponentes/{user}', 'show')->whereNumber('user')->name('proponentes.show');
            });
            Route::get('/proponentes/{user}/anexos/{campo}', 'anexo')
                ->whereNumber('user')
                ->whereIn('campo', PessoaFisica::ARQUIVOS)
                ->name('proponentes.anexo');
        });

        // Cadastro. Avaliador não se exclui, só se desativa. As telas ficam cifradas no histórico (CPF e data de nascimento).
        Route::middleware('inertia.encrypt')->group(function () {
            Route::resource('avaliadores', AvaliadorController::class)
                ->only(['index', 'create', 'store', 'edit', 'update'])
                ->parameters(['avaliadores' => 'avaliador'])
                ->where(['avaliador' => '[0-9]+']);
        });
        Route::patch('/avaliadores/{avaliador}/ativo', [AvaliadorController::class, 'ativo'])
            ->whereNumber('avaliador')
            ->name('avaliadores.ativo');

        // Parametrização. Prefeitura-Bairro não se exclui, só se desativa (o cadastro do proponente guarda o código dela).
        Route::resource('prefeituras-bairro', PrefeituraBairroController::class)
            ->only(['index', 'create', 'store', 'edit', 'update'])
            ->parameters(['prefeituras-bairro' => 'prefeitura'])
            ->where(['prefeitura' => '[0-9]+']);
        Route::patch('/prefeituras-bairro/{prefeitura}/ativo', [PrefeituraBairroController::class, 'ativo'])
            ->whereNumber('prefeitura')
            ->name('prefeituras-bairro.ativo');

        // Parametrização. Área de atuação não se exclui, só se desativa (vai ser escolhida nos editais e nas inscrições).
        Route::resource('areas-atuacao', AreaAtuacaoController::class)
            ->only(['index', 'create', 'store', 'edit', 'update'])
            ->parameters(['areas-atuacao' => 'area'])
            ->where(['area' => '[0-9]+']);
        Route::patch('/areas-atuacao/{area}/ativo', [AreaAtuacaoController::class, 'ativo'])
            ->whereNumber('area')
            ->name('areas-atuacao.ativo');

        // Parametrização. Integração com IA: chave e modelo de cada provedor, o principal e a redundância.
        Route::controller(IntegracaoIaController::class)->group(function () {
            Route::get('/integracao-ia', 'edit')->name('integracao-ia.edit');
            Route::put('/integracao-ia', 'update')->name('integracao-ia.update');
            Route::put('/integracao-ia/redundancia', 'redundancia')->name('integracao-ia.redundancia');
        });
        // Vão ao provedor com a chave digitada ou a guardada, e cada chamada pode custar: limite próprio
        // (o prefixo separa esta contagem da dos outros limites por usuário).
        Route::controller(ConexaoIaController::class)->middleware('throttle:10,1,integracao-ia')->group(function () {
            Route::post('/integracao-ia/modelos', 'modelos')->name('integracao-ia.modelos');
            Route::post('/integracao-ia/teste', 'teste')->name('integracao-ia.teste');
        });
    });
});
