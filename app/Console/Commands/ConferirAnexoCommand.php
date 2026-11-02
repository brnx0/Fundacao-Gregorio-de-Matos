<?php

namespace App\Console\Commands;

use App\Enums\ProvedorIa;
use App\Enums\SituacaoConferencia;
use App\Http\Controllers\InscricaoDocumentoController;
use App\Models\ConexaoIa;
use App\Models\ConfiguracaoAnexo;
use App\Models\ConfiguracaoIa;
use App\Models\PessoaFisicaAnexo;
use App\Support\DocumentoIa\PromptDaConferencia;
use App\Support\DocumentoIa\PromptDaConferenciaDoAnexo;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\RespostaInvalida;
use App\Support\FalhaNaIa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Confere com a IA (o provedor principal e, se ele falhar, a redundância) o PDF ou a imagem de um anexo do cadastro de
 * pessoa física, com a instrução da retaguarda (Parametrização > Anexos do cadastro). Lançado em segundo plano por
 * LancadorDeConferenciaDoAnexo com a execução (PessoaFisicaAnexo::execucao) para a qual foi iniciado.
 *
 * Mesmas garantias de ConferirDocumentoCommand: cada gravação confere, com a linha travada, que o anexo ainda está
 * conferindo nesta execução (um processo antigo ou repetido não desfaz o resultado de outro, e o anexo apagado no meio
 * do caminho não quebra nada); sem resposta da IA o anexo fica não conferido, com a mensagem genérica para a pessoa e o
 * detalhe em `erro`, que é sempre texto em português, nunca a chave da API.
 */
#[Signature('cadastros:conferir {anexo : Id do anexo do cadastro} {execucao? : Execução a conferir (padrão: a atual do anexo)}')]
#[Description('Confere com a IA o PDF ou a imagem de um anexo do cadastro de pessoa física')]
class ConferirAnexoCommand extends Command
{
    private const int TEMPO_LIMITE = 300;

    private const float TEMPERATURA = 0.1;

    /** O tamanho das colunas `motivo` e `erro`. */
    private const int TAMANHO_MAXIMO_EM_BYTES = 1000;

    private const string SUPERADA = 'O anexo não está mais nesta conferência; nada foi gravado.';

    private int|string $id;

    private string $execucao;

    public function handle(AnaliseComRedundancia $analise): int
    {
        set_time_limit(self::TEMPO_LIMITE);

        $this->id = $this->argument('anexo');
        $execucao = $this->argument('execucao') ?? PessoaFisicaAnexo::query()->whereKey($this->id)->value('execucao');
        if (! is_string($execucao) || $execucao === '') {
            $this->error('Anexo não encontrado.');

            return self::FAILURE;
        }

        $this->execucao = $execucao;
        $anexo = PessoaFisicaAnexo::query()->whereKey($this->id)->daExecucao($this->execucao)->first();
        if ($anexo === null) {
            $this->error(self::SUPERADA);

            return self::FAILURE;
        }

        // A equipe pode ter dispensado a conferência do campo (ou desligado a validação por IA) depois do lançamento:
        // o arquivo não sai do servidor.
        $configuracao = ConfiguracaoAnexo::doCampo($anexo->campo);
        if (! $configuracao->confereNoCadastro(ConfiguracaoIa::validacaoLigada())) {
            return $this->dispensar();
        }

        $provedores = [];

        try {
            $provedores = ConfiguracaoIa::atual()->provedoresDaImportacao();
            if ($provedores === []) {
                throw new FalhaNaIa(AnaliseComRedundancia::SEM_CONFIGURACAO);
            }

            $disco = Storage::disk(PessoaFisicaAnexo::DISCO);
            $arquivo = $disco->get($anexo->arquivo)
                ?? throw new FalhaNaIa('O arquivo enviado não foi encontrado. Envie o arquivo de novo.');
            // O tipo vem do conteúdo gravado: é ele que diz ao provedor como ler o arquivo. Uma foto GIF de antes da
            // etapa Anexos para aqui.
            $tipo = $disco->mimeType($anexo->arquivo);
            if (! in_array($tipo, $anexo->campo->tiposDeArquivo(), true)) {
                throw new FalhaNaIa($anexo->campo->somenteImagem()
                    ? 'O arquivo enviado não é uma imagem JPG ou PNG. Envie o arquivo de novo.'
                    : 'O arquivo enviado não é um PDF nem uma imagem JPG ou PNG. Envie o arquivo de novo.');
            }

            $resultado = $analise->analisar(
                $provedores,
                $arquivo,
                PromptDaConferenciaDoAnexo::montar($anexo, $configuracao),
                self::TEMPERATURA,
                PromptDaConferencia::esquema(),
                PromptDaConferencia::conferir(...),
                $tipo,
            );
            unset($arquivo);

            $aprovado = $resultado['dados']['aprovado'] === true;

            $guardou = $this->naExecucao(fn (PessoaFisicaAnexo $atual) => $atual->update([
                'situacao' => $aprovado ? SituacaoConferencia::Aprovado : SituacaoConferencia::Reprovado,
                'motivo' => $aprovado ? null : $this->cortar(trim($resultado['dados']['motivo'])),
                'erro' => null,
                'provedor' => $resultado['provedor'],
                'modelo' => $resultado['modelo'],
                'conferido_em' => now(),
            ]));

            if (! $guardou) {
                $this->error(self::SUPERADA);

                return self::FAILURE;
            }
        } catch (FalhaNaIa|RespostaInvalida $falha) {
            return $this->falhar($falha->getMessage(), $provedores);
        } catch (Throwable $erro) {
            // Sem corpo de pedido nem de resposta; as chaves, se aparecerem na mensagem, saem.
            $chaves = array_values(array_filter(array_map(fn (array $provedor): string => (string) $provedor['conexao']->chaveGuardada(), $provedores)));
            Log::error('Conferência de anexo do cadastro: erro inesperado.', [
                'anexo' => (int) $this->id,
                'excecao' => $erro::class,
                'mensagem' => mb_substr(str_replace($chaves, '[chave]', $erro->getMessage()), 0, 500),
                'local' => $erro->getFile().':'.$erro->getLine(),
            ]);

            return $this->falhar(ConferirDocumentoCommand::ERRO_INESPERADO, $provedores);
        }

        $this->info($aprovado ? 'Anexo aprovado.' : 'Anexo reprovado.');

        return self::SUCCESS;
    }

    /**
     * Roda $gravar numa transação com o anexo travado, só se ele ainda estiver conferindo nesta execução.
     *
     * @param  callable(PessoaFisicaAnexo): mixed  $gravar
     */
    private function naExecucao(callable $gravar): bool
    {
        return DB::transaction(function () use ($gravar): bool {
            $atual = PessoaFisicaAnexo::query()
                ->whereKey($this->id)
                ->daExecucao($this->execucao)
                ->lockForUpdate()
                ->first();

            if ($atual === null) {
                return false;
            }

            $gravar($atual);

            return true;
        });
    }

    /**
     * Deixa o anexo não conferido, se ele ainda estiver nesta execução (senão, outra conferência já cuidou dele). O
     * provedor e o modelo gravados são os do principal, o primeiro a ser chamado.
     *
     * @param  list<array{provedor: ProvedorIa, conexao: ConexaoIa}>  $provedores
     */
    private function falhar(string $mensagem, array $provedores): int
    {
        $principal = $provedores[0] ?? null;

        $this->naExecucao(fn (PessoaFisicaAnexo $atual) => $atual->update([
            'situacao' => SituacaoConferencia::NaoConferido,
            'motivo' => InscricaoDocumentoController::NAO_CONFERIDO,
            'erro' => $this->cortar($mensagem),
            'provedor' => $principal['provedor'] ?? null,
            'modelo' => $principal === null ? null : (string) $principal['conexao']->modelo,
            'conferido_em' => now(),
        ]));

        $this->error($mensagem);

        return self::FAILURE;
    }

    /** Conferência dispensada: o anexo volta a ser só enviado, sem passar pela IA (se ainda estiver nesta execução). */
    private function dispensar(): int
    {
        $guardou = $this->naExecucao(fn (PessoaFisicaAnexo $atual) => $atual->update([
            'situacao' => SituacaoConferencia::Enviado,
            'motivo' => null,
            'erro' => null,
            'provedor' => null,
            'modelo' => null,
            'execucao' => null,
            'iniciada_em' => null,
            'conferido_em' => null,
        ]));

        if (! $guardou) {
            $this->error(self::SUPERADA);

            return self::FAILURE;
        }

        $this->info('Conferência do anexo dispensada: a IA não foi chamada.');

        return self::SUCCESS;
    }

    private function cortar(string $texto): string
    {
        return mb_strcut($texto, 0, self::TAMANHO_MAXIMO_EM_BYTES, 'UTF-8');
    }
}
