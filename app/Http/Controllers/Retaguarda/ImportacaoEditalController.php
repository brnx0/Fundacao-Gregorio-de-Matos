<?php

namespace App\Http\Controllers\Retaguarda;

use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Http\Controllers\Controller;
use App\Http\Requests\Retaguarda\ImportarEditalRequest;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\ImportacaoEdital;
use App\Support\ArquivoArmazenado;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\LancadorDeAnalise;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Importação de edital do PDF com IA. A análise roda em segundo plano (LancadorDeAnalise); a tela consulta a
 * situação. Fica sempre disponível, esteja ou não ligada a validação por IA; enviar e tentar de novo, porém, só com o
 * provedor principal configurado na Integração com IA, com modelo e chave (senão, a recusa diz onde configurar).
 * Consultar, descartar e marcar como conferida valem para as importações já feitas.
 */
class ImportacaoEditalController extends Controller implements HasMiddleware
{
    public const string MENSAGEM_INICIADA = 'Análise iniciada. Você pode continuar usando o sistema.';

    public const string ERRO_AO_INICIAR = 'Não foi possível iniciar a análise. Tente de novo.';

    /** Atributo do pedido com o provedor principal da importação, guardado pelo middleware. */
    private const string PRINCIPAL = 'importacao.principal';

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next): Response {
                $provedores = ConfiguracaoIa::atual()->provedoresDaImportacao();

                // Recusa antes de guardar o PDF e de qualquer chamada: o envio mostra o erro na janela, com o link para a
                // Integração com IA; a nova tentativa, no aviso da página.
                if ($provedores === []) {
                    return $request->routeIs('retaguarda.editais.importacoes.store')
                        ? back()->withErrors(['configuracao' => AnaliseComRedundancia::SEM_CONFIGURACAO])
                        : back()->with('erro', AnaliseComRedundancia::SEM_CONFIGURACAO);
                }

                $request->attributes->set(self::PRINCIPAL, $provedores[0]);

                return $next($request);
            }, only: ['store', 'tentar']),
        ];
    }

    public function store(ImportarEditalRequest $request, LancadorDeAnalise $lancador): RedirectResponse
    {
        $pdf = $request->file('pdf');
        $caminho = ArquivoArmazenado::guardar($pdf, ImportacaoEdital::DISCO, ImportacaoEdital::PASTA);

        $importacao = ImportacaoEdital::create([
            'user_id' => $request->user()->getKey(),
            'situacao' => SituacaoImportacao::Analisando,
            'pdf' => $caminho,
            'pdf_nome' => mb_strcut($pdf->getClientOriginalName(), 0, 255, 'UTF-8'),
            ...$this->principal($request),
            'iniciada_em' => now(),
            'execucao' => ImportacaoEdital::novaExecucao(),
        ]);

        // Sem agendador no projeto, cada envio apaga as importações com erro que já saíram do painel, com o PDF.
        (new ImportacaoEdital)->pruneAll();

        return $this->lancar($importacao, $lancador);
    }

    /**
     * Inicia a análise; se o processo nem começar, a importação já fica com erro (e com "Tentar de novo"). Volta para a
     * aba Não publicados da lista, onde o edital importado aparece.
     */
    private function lancar(ImportacaoEdital $importacao, LancadorDeAnalise $lancador): RedirectResponse
    {
        try {
            $lancador->lancar($importacao);
        } catch (Throwable $erro) {
            Log::error('Importação de edital: não foi possível iniciar a análise.', [
                'importacao' => (int) $importacao->getKey(),
                'excecao' => $erro::class,
            ]);
            $importacao->update(['situacao' => SituacaoImportacao::Erro, 'erro' => self::ERRO_AO_INICIAR]);

            return redirect()->route('retaguarda.editais.index')->with('erro', self::ERRO_AO_INICIAR);
        }

        return redirect()->route('retaguarda.editais.index')->with('sucesso', self::MENSAGEM_INICIADA);
    }

    /** Situação para a tela consultar até a análise terminar (consulta repetida: sem as colunas CLOB). */
    public function show(int $importacao): JsonResponse
    {
        $importacao = ImportacaoEdital::query()
            ->select(['id', 'edital_id', 'situacao', 'erro', 'iniciada_em'])
            ->with('edital:id,slug')
            ->findOrFail($importacao);
        $edital = $importacao->edital;

        return response()->json([
            'situacao' => $importacao->situacaoExibida()->value,
            'erro' => $importacao->erroExibido(),
            'editalUrl' => $edital !== null ? route('retaguarda.editais.edit', $edital) : null,
        ]);
    }

    /** Refaz a análise no mesmo registro (só com erro); de dois cliques seguidos, só um lança a análise. */
    public function tentar(Request $request, ImportacaoEdital $importacao, LancadorDeAnalise $lancador): RedirectResponse
    {
        ['provedor' => $provedor, 'modelo' => $modelo] = $this->principal($request);

        if (! $importacao->abrirNovaTentativa($provedor, $modelo)) {
            return back()->with('erro', 'Só a importação com erro pode ser analisada de novo.');
        }

        return $this->lancar($importacao, $lancador);
    }

    /**
     * O provedor principal e o modelo previstos para a análise (o middleware já conferiu que há um); quem respondeu de
     * fato é gravado pelo comando.
     *
     * @return array{provedor: ProvedorIa, modelo: string}
     */
    private function principal(Request $request): array
    {
        /** @var array{provedor: ProvedorIa, conexao: ConexaoIa} $principal */
        $principal = $request->attributes->get(self::PRINCIPAL);

        return ['provedor' => $principal['provedor'], 'modelo' => (string) $principal['conexao']->modelo];
    }

    /** Apaga a importação com erro e o PDF enviado. */
    public function destroy(ImportacaoEdital $importacao): RedirectResponse
    {
        if ($importacao->situacaoExibida() !== SituacaoImportacao::Erro) {
            return back()->with('erro', 'Só a importação com erro pode ser descartada.');
        }

        $importacao->delete();
        ArquivoArmazenado::apagar(ImportacaoEdital::DISCO, $importacao->pdf);

        return redirect()->route('retaguarda.editais.index')->with('sucesso', 'Importação descartada.');
    }

    /** Tira do edital o quadro "Importado pela IA". */
    public function conferida(Edital $edital): RedirectResponse
    {
        $importacao = ImportacaoEdital::query()->where('edital_id', $edital->getKey())->firstOrFail();

        $importacao->update(['conferida_em' => now()]);

        return back()->with('sucesso', 'Importação marcada como conferida.');
    }
}
