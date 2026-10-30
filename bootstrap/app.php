<?php

use App\Http\Middleware\BloquearGestorNoCadastro;
use App\Http\Middleware\EncerrarSessaoDoProponente;
use App\Http\Middleware\ExigirCadastroCompleto;
use App\Http\Middleware\ExigirTipoCadastro;
use App\Http\Middleware\GarantirGestor;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'gestor' => GarantirGestor::class,
            'nao-gestor' => BloquearGestorNoCadastro::class,
            'cadastro-completo' => ExigirCadastroCompleto::class,
            'tipo-cadastro' => ExigirTipoCadastro::class,
            'encerra-sessao-proponente' => EncerrarSessaoDoProponente::class,
        ]);

        // Visitante da retaguarda vai ao login dela; no portal do proponente, à home.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('retaguarda', 'retaguarda/*') ? route('retaguarda.login') : '/'
        );

        // Proxies confiáveis (TRUSTED_PROXIES) são aplicados no AppServiceProvider,
        // a partir de config('app.trusted_proxies'), para funcionar com config:cache.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Chave de API e senhas digitadas não voltam como "old input": a sessão fica em arquivo, sem cifra.
        $exceptions->dontFlash(['chave_api', 'senha', 'senha_confirmation', 'senha_atual', 'nova_senha', 'nova_senha_confirmation']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Limite de tentativas: nas telas (Inertia), volta com o aviso em vez da página de erro 429.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?RedirectResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            $mensagem = 'Muitas tentativas. Aguarde um minuto e tente novamente.';

            if ($request->routeIs('retaguarda.login.entrar')) {
                return back()->withErrors(['email' => $mensagem]);
            }

            if ($request->routeIs('retaguarda.senha.update')) {
                return back()->withErrors(['senha_atual' => $mensagem]);
            }

            // GET não volta "para trás": o endereço anterior pode ser outro passo limitado do mesmo fluxo (ex.: gov.br).
            if ($request->isMethod('GET')) {
                $daRetaguarda = $request->is('retaguarda', 'retaguarda/*') || $request->query('destino') === 'retaguarda';

                return redirect($daRetaguarda ? route('retaguarda.login') : '/')->with('erro', $mensagem);
            }

            return back()->with('erro', $mensagem);
        });

        // Envio acima do post_max_size do PHP. Acontece antes da sessão (middleware global),
        // por isso a sessão é aberta aqui só para devolver o aviso ao formulário.
        $exceptions->render(function (PostTooLargeException $e, Request $request): ?RedirectResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return app(Pipeline::class)
                ->send($request)
                ->through([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])
                ->then(function () use ($request): RedirectResponse {
                    $mensagem = 'Os arquivos enviados passam do tamanho máximo permitido.';

                    // No edital o aviso fica junto ao PDF; nas demais telas (portal, eventos, usuários,
                    // minha senha), no aviso geral da página.
                    return $request->is('retaguarda/editais', 'retaguarda/editais/*')
                        ? back()->withErrors(['pdf' => $mensagem])
                        : back()->with('erro', $mensagem);
                });
        });
    })->create();
