<?php

namespace App\Http\Middleware;

use App\Support\VLibras;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    private const VITE_DEV_SERVER = 'http://localhost:5173';

    /** Tiles do mapa dos eventos (Leaflet, MapaLocal.vue): imagens do OpenStreetMap, nada de script. */
    private const TILES_DO_MAPA = 'https://tile.openstreetmap.org';

    /**
     * Páginas que mostram num quadro um arquivo do próprio site: a área do avaliador abre o PDF do documento na tela.
     * Toda a área, porque a navegação do Inertia mantém a CSP da primeira página carregada.
     */
    private const PAGINAS_COM_QUADRO = ['avaliacao.*'];

    /** Arquivos que essas páginas abrem no quadro: só elas (o mesmo site) podem emoldurá-los. */
    private const ARQUIVOS_EM_QUADRO = ['avaliacao.inscricoes.arquivo'];

    public function handle(Request $request, Closure $next): Response
    {
        // Nonce novo a cada request; o Vite o aplica nas tags que gera e o Blade
        // o usa no script inline de preferências de acessibilidade.
        $nonce = Vite::useCspNonce();

        // Como o nonce: decidido antes da resposta, para o Blade só incluir o VLibras
        // quando a CSP desta mesma resposta libera os domínios dele.
        $vlibras = VLibras::paraExibir($request);
        View::share('vlibras', $vlibras);

        $response = $next($request);

        $emQuadro = $request->routeIs(...self::ARQUIVOS_EM_QUADRO);

        $response->headers->set('Content-Security-Policy', $this->politica($nonce, $vlibras !== null, $request->routeIs(...self::PAGINAS_COM_QUADRO), $emQuadro));
        $response->headers->set('X-Frame-Options', $emQuadro ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function politica(string $nonce, bool $comVLibras, bool $comQuadro, bool $emQuadro): string
    {
        $dev = app()->environment('local') ? ' '.self::VITE_DEV_SERVER : '';
        $devWs = app()->environment('local') ? ' ws:' : '';

        // O login gov.br sai do portal por redirecionamento após GET/POST.
        $govbr = config('govbr.base_url') ? ' '.config('govbr.base_url') : '';

        // Domínios do VLibras (App\Support\VLibras::CSP) só nas páginas que exibem o widget.
        $vlibras = fn (string $diretiva): string => $comVLibras ? ' '.implode(' ', VLibras::CSP[$diretiva]) : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}".$vlibras('script-src'),
            // Sem nonce nem hash: com eles o navegador ignora 'unsafe-inline', e o VLibras injeta os próprios estilos.
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data:{$dev} ".self::TILES_DO_MAPA.$vlibras('img-src'),
            "font-src 'self' data:{$dev}".$vlibras('font-src'),
            "connect-src 'self'{$dev}{$devWs}".$vlibras('connect-src'),
            // Vídeos do YouTube na descrição do evento (HtmlSeguro só deixa este domínio) e o intérprete do VLibras.
            'frame-src '.($comQuadro ? "'self' " : '').'https://www.youtube-nocookie.com'.$vlibras('frame-src'),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'{$govbr}",
            'frame-ancestors '.($emQuadro ? "'self'" : "'none'"),
        ]);
    }
}
