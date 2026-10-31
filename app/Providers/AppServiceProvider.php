<?php

namespace App\Providers;

use App\Auth\GovBr\FakeLoginGate;
use App\Database\OracleConnection;
use App\Models\ConfiguracaoEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Reaproveita o resolver do yajra (PDO e variáveis de sessão já aplicadas) e
        // troca só a classe da conexão, para o schema builder tolerar bancos sem Oracle Text.
        $yajra = Connection::getResolver('oracle');

        Connection::resolverFor('oracle', function ($pdo, $database, $prefix, $config) use ($yajra) {
            $base = $yajra($pdo, $database, $prefix, $config);

            return new OracleConnection($base->getPdo(), $database, $prefix, $config);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(FakeLoginGate::class)->assertNaoEmProducao();

        // E-mail configurado na retaguarda vale no lugar do .env. Só quando o envio é carregado, para não ler o banco
        // em toda requisição; sem a tabela (migration ainda não rodada), o .env continua valendo.
        $this->app->afterResolving('mail.manager', function (): void {
            try {
                ConfiguracaoEmail::aplicar();
            } catch (QueryException) {
                // Segue com o .env.
            }
        });

        // 10/min por rota e por IP: um login (redirect + callback) não gasta o limite da outra rota.
        RateLimiter::for('govbr', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->route()?->getName() ?? $request->method().':'.$request->path()).'|'.$request->ip()
        ));

        // 5/min por e-mail (normalizado) e IP, contra tentativa de senha na retaguarda.
        RateLimiter::for('retaguarda-login', fn (Request $request) => Limit::perMinute(5)->by(
            mb_strtolower(trim((string) $request->input('email'))).'|'.$request->ip()
        ));

        // 30/min por usuário nas consultas de CNPJ, CEP e municípios do cadastro.
        RateLimiter::for('consultas', fn (Request $request) => Limit::perMinute(30)->by(
            (string) ($request->user()?->id ?? $request->ip())
        ));

        // Atrás de um proxy que termina o TLS, honra X-Forwarded-Proto; sem isso
        // isSecure() fica falso e o HSTS (SecurityHeaders) nunca é enviado.
        $proxies = trim((string) config('app.trusted_proxies'));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        // Staging e produção rodam só em HTTPS. 'testing' fica de fora para os
        // testes seguirem com URLs http://localhost.
        if (! $this->app->environment(['local', 'testing'])) {
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }
    }
}
