<?php

namespace App\Console\Commands;

use App\Enums\SituacaoConvite;
use App\Mail\ConviteDaAvaliacao;
use App\Models\Avaliador;
use App\Models\ConfiguracaoEmail;
use App\Models\EditalAvaliador;
use App\Models\EditalChamada;
use App\Support\FalhaNoEnvioDeEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envia ao avaliador um e-mail só, com o link pessoal e os editais dos vínculos lançados com esta execução
 * (EditalAvaliador::convite_execucao). Lançado em segundo plano por LancadorDeConvite. Vale o envio padrão do sistema
 * (a configuração de e-mail ativa ou o .env). Uma execução que não é mais a gravada é ignorada.
 *
 * O link nunca vai para o log nem para o erro gravado: só a mensagem de FalhaNoEnvioDeEmail e a classe e o código da falha.
 */
#[Signature('avaliacao:enviar-convite {avaliador : Id do avaliador} {execucao : Execução do convite}')]
#[Description('Envia por e-mail o convite do avaliador para os editais em que ele foi escolhido')]
class EnviarConviteCommand extends Command
{
    private const string SUPERADA = 'O convite não está mais nesta execução; nada foi enviado.';

    public function handle(): int
    {
        $execucao = (string) $this->argument('execucao');
        $avaliador = Avaliador::query()->find($this->argument('avaliador'));
        $vinculos = $avaliador === null ? new Collection : $this->vinculosDaExecucao($avaliador, $execucao);

        if ($avaliador === null || $vinculos->isEmpty()) {
            $this->error(self::SUPERADA);

            return self::FAILURE;
        }

        $link = $avaliador->linkDeAcesso();
        if ($link === null) {
            $this->marcarFalhou($avaliador, $execucao, EditalAvaliador::ERRO_AO_INICIAR);

            return self::FAILURE;
        }

        $editais = $vinculos->map(fn (EditalAvaliador $vinculo): array => [
            'titulo' => (string) $vinculo->edital?->titulo,
            'periodo' => $this->periodoDeAvaliacao($vinculo),
        ])->values()->all();

        try {
            Mail::to($avaliador->email)->send(new ConviteDaAvaliacao($avaliador->nome, $link, $editais));
        } catch (Throwable $falha) {
            Log::warning('Convite do avaliador: o envio falhou.', ['falha' => $falha::class, 'codigo' => $falha->getCode()]);
            [$host, $porta] = $this->servidorEmUso();
            $this->marcarFalhou($avaliador, $execucao, FalhaNoEnvioDeEmail::mensagem($falha, $host, $porta));

            return self::FAILURE;
        }

        $this->daExecucao($avaliador, $execucao)->update([
            'convite_situacao' => SituacaoConvite::Enviado->value,
            'convite_enviado_em' => now(),
            'convite_erro' => null,
        ]);
        $this->info('Convite enviado.');

        return self::SUCCESS;
    }

    /** @return Collection<int, EditalAvaliador> */
    private function vinculosDaExecucao(Avaliador $avaliador, string $execucao): Collection
    {
        return $this->daExecucao($avaliador, $execucao)
            ->with(['edital:id,titulo', 'edital.chamadas'])
            ->orderBy('id')
            ->get();
    }

    /** @return Builder<EditalAvaliador> */
    private function daExecucao(Avaliador $avaliador, string $execucao): Builder
    {
        return EditalAvaliador::query()
            ->where('avaliador_id', $avaliador->getKey())
            ->where('convite_execucao', $execucao)
            ->where('convite_situacao', SituacaoConvite::NaFila->value);
    }

    private function marcarFalhou(Avaliador $avaliador, string $execucao, string $mensagem): void
    {
        $this->daExecucao($avaliador, $execucao)->update([
            'convite_situacao' => SituacaoConvite::Falhou->value,
            'convite_erro' => mb_strcut($mensagem, 0, 500, 'UTF-8'),
        ]);

        $this->error($mensagem);
    }

    /**
     * O servidor de e-mail que vale: o da configuração ativa e completa ou, senão, o do .env.
     *
     * @return array{0: string, 1: int}
     */
    private function servidorEmUso(): array
    {
        $configuracao = ConfiguracaoEmail::atual();

        if ($configuracao->ativo && $configuracao->completa()) {
            return [(string) $configuracao->host, (int) $configuracao->porta];
        }

        $mailer = 'mail.mailers.'.config('mail.default');

        return [(string) config("{$mailer}.host", ''), (int) config("{$mailer}.port", 0)];
    }

    /** O período de avaliação do edital: as datas de cada chamada (com o nome dela se houver várias). */
    private function periodoDeAvaliacao(EditalAvaliador $vinculo): string
    {
        $chamadas = $vinculo->edital?->chamadas ?? new Collection;

        if ($chamadas->isEmpty()) {
            return 'Não cadastrado';
        }

        return $chamadas->map(function (EditalChamada $chamada) use ($chamadas): string {
            $periodo = $chamada->temPeriodoDeAvaliacao()
                ? $chamada->validacao_inicio->format('d/m/Y').' a '.$chamada->validacao_fim->format('d/m/Y')
                : 'Não cadastrado';

            return $chamadas->count() > 1 ? "{$periodo} ({$chamada->nome})" : $periodo;
        })->implode('; ');
    }
}
