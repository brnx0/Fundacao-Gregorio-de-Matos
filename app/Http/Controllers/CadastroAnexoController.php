<?php

namespace App\Http\Controllers;

use App\Enums\AnexoCadastro;
use App\Enums\SituacaoConferencia;
use App\Http\Requests\EnviarAnexoRequest;
use App\Models\ConfiguracaoAnexo;
use App\Models\ConfiguracaoIa;
use App\Models\PessoaFisica;
use App\Models\PessoaFisicaAnexo;
use App\Support\ArquivoArmazenado;
use App\Support\DocumentoIa\LancadorDeConferenciaDoAnexo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Etapa Anexos do cadastro: anexar (ou trocar), remover, abrir o arquivo, pedir a conferência de novo e concluir a
 * etapa. Sem ids na URL: tudo é da pessoa física de quem está logado (ExigirPessoaFisica garante que ela existe). A
 * conferência por IA, quando a retaguarda a pede para o campo, roda em segundo plano (ConferirAnexoCommand).
 */
class CadastroAnexoController extends Controller
{
    public const string ERRO_AO_INICIAR = 'O arquivo foi enviado, mas não foi possível iniciar a conferência. Tente conferir de novo em instantes.';

    /** Concluir (ou finalizar o cadastro) com algum anexo ainda pendente. */
    public const string ERRO_PENDENCIAS = 'Ainda há anexos a resolver. Confira a lista e tente de novo.';

    private const string ERRO_DO_PROCESSO = 'Não foi possível iniciar o processo da conferência.';

    public function index(Request $request): Response
    {
        $pessoa = $request->user()->pessoaFisica;
        $enviados = $this->enviados($pessoa);
        $configuracoes = ConfiguracaoAnexo::porCampo();
        $validacaoLigada = ConfiguracaoIa::validacaoLigada();
        $pendencias = $pessoa->pendenciasDosAnexos();

        return Inertia::render('Cadastro/Anexos', [
            'anexos' => array_map(fn (AnexoCadastro $campo): array => [
                'campo' => $campo->value,
                'rotulo' => $campo->rotulo(),
                'obrigatorio' => $campo->obrigatorio(),
                'somenteImagem' => $campo->somenteImagem(),
                'conferidoPelaIa' => $configuracoes[$campo->value]->confereNoCadastro($validacaoLigada),
                'enviado' => $this->arquivoEnviado($enviados->get($campo->value)),
            ], AnexoCadastro::cases()),
            'pendencias' => $pendencias,
            'podeContinuar' => $pendencias === [],
        ]);
    }

    /**
     * Situação da conferência de cada anexo enviado e o que ainda impede de concluir, para a tela consultar enquanto
     * algum está conferindo. Sem o detalhe técnico da falha, que fica só no banco; conferindo parado aparece como não
     * conferido.
     */
    public function situacao(Request $request): JsonResponse
    {
        $pessoa = $request->user()->pessoaFisica;
        $pendencias = $pessoa->pendenciasDosAnexos();

        return response()->json([
            'anexos' => $this->enviados($pessoa)
                ->map(fn (PessoaFisicaAnexo $anexo): array => ['campo' => $anexo->campo->value, ...$this->situacaoParaATela($anexo)])
                ->values()
                ->all(),
            'pendencias' => $pendencias,
            'podeContinuar' => $pendencias === [],
        ]);
    }

    /**
     * "Continuar": sem pendências, a etapa fica concluída (profile_completed_at, que libera as etapas seguintes e o
     * portal). Quem já finalizou o cadastro fica na aba Anexos.
     */
    public function concluir(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->pessoaFisica->pendenciasDosAnexos() !== []) {
            return redirect()->route('cadastro.anexos')->with('erro', self::ERRO_PENDENCIAS);
        }

        $user->profile_completed_at ??= now();
        $user->save();

        // Enquanto o tipo de cadastro não é escolhido, o cadastro segue pelas etapas seguintes.
        if (! $user->cadastroFinalizado()) {
            return redirect()->route('cadastro.organizacao')->with('sucesso', 'Anexos salvos. Você também pode cadastrar uma Pessoa Jurídica.');
        }

        return redirect()->route('cadastro.anexos')->with('sucesso', 'Anexos salvos.');
    }

    public function store(EnviarAnexoRequest $request, AnexoCadastro $campo, LancadorDeConferenciaDoAnexo $lancador): RedirectResponse
    {
        $user = $request->user();
        $pessoa = $user->pessoaFisica;
        $arquivo = $request->file('arquivo');

        $caminho = ArquivoArmazenado::guardar($arquivo, PessoaFisicaAnexo::DISCO, PessoaFisicaAnexo::pasta($user));
        $nome = mb_strcut($arquivo->getClientOriginalName(), 0, 255, 'UTF-8');
        $confere = ConfiguracaoAnexo::doCampo($campo)->confereNoCadastro(ConfiguracaoIa::validacaoLigada());
        $substituido = null;

        try {
            $enviado = DB::transaction(function () use ($pessoa, $campo, $caminho, $nome, $confere, &$substituido): PessoaFisicaAnexo {
                $travada = $this->travarPessoa($pessoa);

                $enviado = $travada->anexos()->firstOrNew(['campo' => $campo->value]);
                $substituido = $enviado->arquivo;
                $enviado->fill([
                    'arquivo' => $caminho,
                    'arquivo_nome' => $nome,
                    ...$this->atributosDaConferencia($confere),
                ])->save();

                return $enviado;
            });
        } catch (Throwable $erro) {
            ArquivoArmazenado::apagar(PessoaFisicaAnexo::DISCO, $caminho);

            throw $erro;
        }

        ArquivoArmazenado::apagar(PessoaFisicaAnexo::DISCO, $substituido);

        if ($enviado->situacao === SituacaoConferencia::Conferindo && ! $this->lancar($enviado, $lancador)) {
            return redirect()->route('cadastro.anexos')->with('erro', self::ERRO_AO_INICIAR);
        }

        return redirect()->route('cadastro.anexos')->with('sucesso', 'Arquivo enviado.');
    }

    /**
     * Qualquer anexo sai, também o CPF e o RG: sem eles a etapa volta a ficar pendente (pendenciasDosAnexos) até a
     * pessoa enviar outro.
     */
    public function destroy(Request $request, AnexoCadastro $campo): RedirectResponse
    {
        $pessoa = $request->user()->pessoaFisica;

        $arquivo = DB::transaction(function () use ($pessoa, $campo): ?string {
            $travada = $this->travarPessoa($pessoa);

            $removido = $travada->anexos()->where('campo', $campo->value)->first();
            $removido?->delete();

            return $removido?->arquivo;
        });

        ArquivoArmazenado::apagar(PessoaFisicaAnexo::DISCO, $arquivo);

        return redirect()->route('cadastro.anexos')->with('sucesso', 'Arquivo removido.');
    }

    /** O arquivo aberto no navegador (miniatura e "abrir"), só o da própria pessoa. */
    public function arquivo(Request $request, AnexoCadastro $campo): StreamedResponse
    {
        $enviado = $request->user()->pessoaFisica->anexos()->where('campo', $campo->value)->firstOrFail();
        $disco = Storage::disk(PessoaFisicaAnexo::DISCO);

        abort_unless($disco->exists($enviado->arquivo), 404);

        return $disco->response($enviado->arquivo, $enviado->arquivo_nome);
    }

    /**
     * "Conferir de novo": só o anexo reprovado ou não conferido (inclusive o conferindo parado) ganha uma execução
     * nova; com a conferência dispensada ele só volta a ser enviado, sem chamar a IA. "Conferir": o enviado sem
     * conferência (dispensada no envio) que agora a IA confere também ganha uma execução.
     */
    public function conferir(Request $request, AnexoCadastro $campo, LancadorDeConferenciaDoAnexo $lancador): RedirectResponse
    {
        $pessoa = $request->user()->pessoaFisica;
        // A equipe pode ter dispensado a conferência do campo (ou desligado a validação por IA) depois do envio: nada vai para a IA.
        $confere = ConfiguracaoAnexo::doCampo($campo)->confereNoCadastro(ConfiguracaoIa::validacaoLigada());

        $reiniciado = DB::transaction(function () use ($pessoa, $campo, $confere): ?PessoaFisicaAnexo {
            $travada = $this->travarPessoa($pessoa);

            $enviado = $travada->anexos()->where('campo', $campo->value)->lockForUpdate()->first();

            $situacao = $enviado?->situacaoExibida();
            $falhou = in_array($situacao, [SituacaoConferencia::Reprovado, SituacaoConferencia::NaoConferido], true);
            if (! $falhou && ! ($confere && $situacao === SituacaoConferencia::Enviado)) {
                return null;
            }

            $enviado->fill($this->atributosDaConferencia($confere))->save();

            return $enviado;
        });

        if ($reiniciado === null) {
            return redirect()->route('cadastro.anexos')->with('aviso', InscricaoDocumentoController::AVISO_CONFERIR_DE_NOVO);
        }

        if ($reiniciado->situacao !== SituacaoConferencia::Conferindo) {
            return redirect()->route('cadastro.anexos')->with('aviso', InscricaoDocumentoController::AVISO_SEM_CONFERENCIA);
        }

        if (! $this->lancar($reiniciado, $lancador)) {
            return redirect()->route('cadastro.anexos')->with('erro', self::ERRO_AO_INICIAR);
        }

        return redirect()->route('cadastro.anexos')->with('sucesso', 'Conferência iniciada.');
    }

    /**
     * Os anexos enviados, pelo valor do campo, na ordem da tela.
     *
     * @return Collection<string, PessoaFisicaAnexo>
     */
    private function enviados(PessoaFisica $pessoa): Collection
    {
        $porCampo = $pessoa->anexos()->get()->keyBy(fn (PessoaFisicaAnexo $anexo): string => $anexo->campo->value);

        return collect(AnexoCadastro::valores())
            ->filter(fn (string $campo): bool => $porCampo->has($campo))
            ->mapWithKeys(fn (string $campo): array => [$campo => $porCampo->get($campo)]);
    }

    /**
     * O arquivo enviado para o campo, com a situação da conferência; nulo se ainda não foi enviado. Sem o caminho no
     * disco nem o detalhe técnico da falha. A url leva uma versão que muda a cada troca do arquivo: sem ela, a
     * miniatura continuaria mostrando o anterior.
     *
     * @return array{nome: string, url: string, imagem: bool, situacao: string, motivo: ?string}|null
     */
    private function arquivoEnviado(?PessoaFisicaAnexo $anexo): ?array
    {
        if ($anexo === null) {
            return null;
        }

        return [
            'nome' => $anexo->arquivo_nome,
            'url' => route('cadastro.anexos.arquivo', ['campo' => $anexo->campo->value, 'v' => substr(md5($anexo->arquivo), 0, 8)]),
            'imagem' => $anexo->ehImagem(),
            ...$this->situacaoParaATela($anexo),
        ];
    }

    /**
     * A situação que a pessoa vê (conferindo parado vale como não conferido) e o motivo: o da IA no reprovado; no não
     * conferido, o gravado ou a mensagem padrão.
     *
     * @return array{situacao: string, motivo: ?string}
     */
    private function situacaoParaATela(PessoaFisicaAnexo $anexo): array
    {
        $situacao = $anexo->situacaoExibida();

        return [
            'situacao' => $situacao->value,
            'motivo' => match ($situacao) {
                SituacaoConferencia::Reprovado => $anexo->motivo,
                SituacaoConferencia::NaoConferido => $anexo->motivo ?? InscricaoDocumentoController::NAO_CONFERIDO,
                default => null,
            },
        ];
    }

    /**
     * O recomeço da conferência: sem conferência o anexo fica só enviado, senão conferindo, com uma execução nova (a
     * anterior, se ainda rodar, não grava mais) e sem nada do resultado anterior.
     *
     * @return array<string, mixed>
     */
    private function atributosDaConferencia(bool $confere): array
    {
        return [
            'situacao' => $confere ? SituacaoConferencia::Conferindo : SituacaoConferencia::Enviado,
            'motivo' => null,
            'erro' => null,
            'provedor' => null,
            'modelo' => null,
            'execucao' => $confere ? (string) Str::uuid() : null,
            'iniciada_em' => $confere ? now() : null,
            'conferido_em' => null,
        ];
    }

    /** Inicia a conferência; se o processo nem começar, o anexo já fica não conferido (com "Conferir de novo"). */
    private function lancar(PessoaFisicaAnexo $anexo, LancadorDeConferenciaDoAnexo $lancador): bool
    {
        try {
            $lancador->lancar($anexo);
        } catch (Throwable $erro) {
            Log::error('Conferência de anexo do cadastro: não foi possível iniciar o processo.', [
                'anexo' => (int) $anexo->getKey(),
                'excecao' => $erro::class,
            ]);

            PessoaFisicaAnexo::daExecucao((string) $anexo->execucao)->whereKey($anexo->getKey())->update([
                'situacao' => SituacaoConferencia::NaoConferido->value,
                'motivo' => InscricaoDocumentoController::NAO_CONFERIDO,
                'erro' => self::ERRO_DO_PROCESSO,
                'conferido_em' => now(),
            ]);

            return false;
        }

        return true;
    }

    /** Relê a pessoa física travando a linha: dois envios do mesmo campo ao mesmo tempo esperam um pelo outro. */
    private function travarPessoa(PessoaFisica $pessoa): PessoaFisica
    {
        return PessoaFisica::query()->whereKey($pessoa->getKey())->lockForUpdate()->firstOrFail();
    }
}
