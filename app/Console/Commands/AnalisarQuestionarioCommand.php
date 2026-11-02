<?php

namespace App\Console\Commands;

use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Models\ConfiguracaoIa;
use App\Models\Edital;
use App\Models\ImportacaoQuestionario;
use App\Support\Edital\SincronizadorDoQuestionario;
use App\Support\EditalIa\AnaliseComRedundancia;
use App\Support\EditalIa\EditalDaResposta;
use App\Support\EditalIa\EsquemaDoQuestionario;
use App\Support\EditalIa\PromptDoQuestionario;
use App\Support\EditalIa\QuestionarioDaResposta;
use App\Support\EditalIa\RespostaInvalida;
use App\Support\EditalIa\TextoDoDocx;
use App\Support\FalhaNaIa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Analisa o espelho do formulário de uma importação de questionário com a IA (o provedor principal e, se ele falhar,
 * a redundância) e substitui o questionário do edital. O .docx vai como texto (TextoDoDocx), sem arquivo; o PDF vai
 * como arquivo. Lançado em segundo plano por LancadorDeAnaliseDoQuestionario com a execução para a qual foi iniciado.
 *
 * Cada gravação confere, com a linha travada, que a importação ainda está nesta execução e analisando. A substituição
 * trava também a linha do edital e reconfere a trava do questionário (inscrição finalizada no meio da análise): travado,
 * nada muda e a importação fica com o aviso. Uma falha não toca no questionário. O erro gravado é sempre uma mensagem
 * em português, nunca a chave da API.
 */
#[Signature('questionarios:analisar {importacao : Id da importação} {execucao? : Execução a analisar (padrão: a atual da importação)}')]
#[Description('Analisa o espelho do formulário com a IA e substitui o questionário do edital')]
class AnalisarQuestionarioCommand extends Command
{
    private const int TEMPO_LIMITE = 600;

    private const string MEMORIA = '512M';

    private const string SUPERADA = 'A importação não está mais nesta análise; nada foi gravado.';

    /** O edital ganhou questionário durante a análise de uma importação enviada sem confirmar a substituição. */
    public const string ERRO_QUESTIONARIO_ALTERADO = 'O questionário foi alterado durante a análise. Importe de novo e confirme a substituição.';

    private int|string $id;

    private int|string $editalId;

    private string $execucao;

    public function handle(AnaliseComRedundancia $analise, QuestionarioDaResposta $montador, SincronizadorDoQuestionario $sincronizador): int
    {
        set_time_limit(self::TEMPO_LIMITE);
        // O arquivo (até 14 MB), o base64 e o JSON do pedido ficam na memória ao mesmo tempo; o padrão da CLI é 128M.
        $memoria = (string) ini_get('memory_limit');
        if ($memoria !== '-1' && ini_parse_quantity($memoria) < ini_parse_quantity(self::MEMORIA)) {
            ini_set('memory_limit', self::MEMORIA);
        }

        $importacao = ImportacaoQuestionario::query()->find($this->argument('importacao'));
        $execucao = $this->argument('execucao') ?? $importacao?->execucao;
        if ($importacao === null || ! is_string($execucao) || $execucao === '') {
            $this->error('Importação não encontrada.');

            return self::FAILURE;
        }

        $this->id = $importacao->getKey();
        $this->editalId = $importacao->edital_id;
        $this->execucao = $execucao;

        if (! $this->naExecucao(fn (ImportacaoQuestionario $atual) => $atual->update(['iniciada_em' => now(), 'erro' => null]))) {
            $this->error(self::SUPERADA);

            return self::FAILURE;
        }

        $provedores = [];

        try {
            $config = ConfiguracaoIa::atual();
            $provedores = $config->provedoresDaImportacao();
            if ($provedores === []) {
                throw new FalhaNaIa(AnaliseComRedundancia::SEM_CONFIGURACAO);
            }

            $edital = Edital::query()->with('categorias')->findOrFail($importacao->edital_id);
            $binario = Storage::disk(ImportacaoQuestionario::DISCO)->get($importacao->arquivo)
                ?? throw new FalhaNaIa('O arquivo enviado não foi encontrado. Envie o formulário de novo.');

            $prompt = PromptDoQuestionario::fixo()."\n\n".PromptDoQuestionario::editavel()."\n\n".PromptDoQuestionario::contexto($edital);
            if ($importacao->tipo_arquivo === 'docx') {
                // O Word vai como texto: os provedores não leem .docx como arquivo.
                $prompt .= "\n\n".PromptDoQuestionario::comTexto(TextoDoDocx::extrair($binario));
                $binario = null;
            }

            $resultado = $analise->analisar(
                $provedores,
                $binario,
                $prompt,
                $config->temperaturaDaImportacao(),
                EsquemaDoQuestionario::esquema(),
                QuestionarioDaResposta::conferirRaiz(...),
            );
            unset($binario);

            $guardou = $this->naExecucao(fn (ImportacaoQuestionario $atual) => $atual->update([
                'provedor' => $resultado['provedor'],
                'modelo' => $resultado['modelo'],
                'tokens_entrada' => $resultado['tokens']['entrada'],
                'tokens_saida' => $resultado['tokens']['saida'],
                'tokens_total' => $resultado['tokens']['total'],
                'resposta' => $resultado['dados'],
            ]));

            $recusa = null;
            if (! $guardou || ! $this->substituirQuestionario($resultado['dados'], $montador, $sincronizador, $this->avisoDaRedundancia($resultado), $recusa)) {
                $this->error(self::SUPERADA);

                return self::FAILURE;
            }

            if ($recusa !== null) {
                $this->error($recusa);

                return self::FAILURE;
            }
        } catch (FalhaNaIa $falha) {
            return $this->falhar($falha->getMessage());
        } catch (RespostaInvalida $invalida) {
            return $this->falhar($invalida->getMessage());
        } catch (Throwable $erro) {
            // Sem corpo de pedido nem de resposta; as chaves, se aparecerem na mensagem, saem.
            $chaves = array_values(array_filter(array_map(fn (array $provedor): string => (string) $provedor['conexao']->chaveGuardada(), $provedores)));
            Log::error('Importação de questionário: erro inesperado na análise.', [
                'importacao' => (int) $this->id,
                'excecao' => $erro::class,
                'mensagem' => mb_substr(str_replace($chaves, '[chave]', $erro->getMessage()), 0, 500),
                'local' => $erro->getFile().':'.$erro->getLine(),
            ]);

            return $this->falhar(AnalisarEditalCommand::ERRO_INESPERADO);
        }

        $this->info('Questionário importado.');

        return self::SUCCESS;
    }

    /**
     * Roda $gravar numa transação com a importação travada, só se ela ainda estiver nesta execução.
     *
     * @param  callable(ImportacaoQuestionario): mixed  $gravar
     */
    private function naExecucao(callable $gravar): bool
    {
        return DB::transaction(function () use ($gravar): bool {
            $atual = ImportacaoQuestionario::query()
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
     * Substitui o questionário e conclui a importação na mesma transação. Trava primeiro o edital e depois a importação
     * (a ordem do projeto: edital antes) e só segue se a importação ainda estiver nesta execução. Nada muda no edital, e
     * a importação fica com o motivo em $recusa, se o questionário estiver travado (inscrição finalizada) ou se ganhou
     * seções durante a análise de uma importação enviada sem confirmar a substituição. As posições das categorias vêm,
     * no montar e no substituir, da mesma relação ordenada ($edital->categorias).
     *
     * @param  array<mixed>  $dados
     * @param  list<array{bloco: string, tipo: string, texto: string, itens: list<string>}>  $avisos  Pontos de atenção a acrescentar.
     */
    private function substituirQuestionario(array $dados, QuestionarioDaResposta $montador, SincronizadorDoQuestionario $sincronizador, array $avisos, ?string &$recusa): bool
    {
        return DB::transaction(function () use ($dados, $montador, $sincronizador, $avisos, &$recusa): bool {
            $edital = Edital::query()->whereKey($this->editalId)->lockForUpdate()->first();
            $atual = ImportacaoQuestionario::query()
                ->whereKey($this->id)
                ->daExecucao($this->execucao)
                ->lockForUpdate()
                ->first();

            if ($edital === null || $atual === null) {
                return false;
            }

            $recusa = match (true) {
                $edital->questionarioTravado() => Edital::AVISO_QUESTIONARIO_TRAVADO,
                ! $atual->substituir && $edital->secoesDoQuestionario()->exists() => self::ERRO_QUESTIONARIO_ALTERADO,
                default => null,
            };

            if ($recusa !== null) {
                $atual->update([
                    'situacao' => SituacaoImportacao::Erro,
                    'erro' => $recusa,
                    'confiabilidade' => null,
                    'atencao' => null,
                ]);

                return true;
            }

            $montado = $montador->montar($dados, $edital);
            $sincronizador->substituir($edital, $montado['questionario']);

            $atual->fill([
                'confiabilidade' => $montado['confiabilidade'],
                'atencao' => [...$avisos, ...$montado['atencao']],
                'situacao' => SituacaoImportacao::Concluida,
                'concluida_em' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Ponto de atenção quando quem respondeu foi a redundância, com o motivo da falha do principal.
     *
     * @param  array{provedor: ProvedorIa, falhas: list<array{provedor: ProvedorIa, motivo: string}>}  $resultado
     * @return list<array{bloco: string, tipo: string, texto: string, itens: list<string>}>
     */
    private function avisoDaRedundancia(array $resultado): array
    {
        if ($resultado['falhas'] === []) {
            return [];
        }

        $motivo = rtrim($resultado['falhas'][0]['motivo'], '.');

        return [[
            'bloco' => QuestionarioDaResposta::BLOCO,
            'tipo' => EditalDaResposta::TIPO_INCERTA,
            'texto' => "Analisado pelo provedor de redundância ({$resultado['provedor']->rotulo()}) porque o principal falhou: {$motivo}.",
            'itens' => [],
        ]];
    }

    /** Grava o erro, se a importação ainda estiver nesta execução (senão, outra análise já cuidou dela). */
    private function falhar(string $mensagem): int
    {
        $this->naExecucao(fn (ImportacaoQuestionario $atual) => $atual->update([
            'situacao' => SituacaoImportacao::Erro,
            'erro' => mb_strcut($mensagem, 0, 1000, 'UTF-8'),
            'confiabilidade' => null,
            'atencao' => null,
        ]));

        $this->error($mensagem);

        return self::FAILURE;
    }
}
