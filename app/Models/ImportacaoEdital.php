<?php

namespace App\Models;

use App\Enums\ProvedorIa;
use App\Enums\SituacaoImportacao;
use App\Support\ArquivoArmazenado;
use Carbon\CarbonInterface;
use Database\Factories\ImportacaoEditalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Importação de um edital a partir do PDF, analisado pela IA em segundo plano (comando editais:analisar).
 *
 * resposta: JSON recebido da IA; confiabilidade: {geral: ?int, blocos: {bloco: {nota: int, justificativa: string}}};
 * atencao: list<{bloco, tipo: 'nao_encontrado'|'divergencia'|'incerta', texto, itens: list<string>}>.
 * provedor e modelo: os da Integração com IA que responderam (ou, antes da resposta, os previstos).
 * execucao: identifica a análise em curso; só o processo lançado com ela pode concluir ou dar erro (ver editais:analisar).
 */
class ImportacaoEdital extends Model
{
    /** @use HasFactory<ImportacaoEditalFactory> */
    use HasFactory;

    use Prunable;

    /** Disco e pasta dos PDFs enviados. */
    public const string DISCO = 'local';

    public const string PASTA = 'editais/importacoes';

    /** Depois disso, uma análise que não terminou é dada como perdida (o processo morreu). */
    public const int MINUTOS_LIMITE = 15;

    public const string ERRO_SEM_TERMINO = 'A análise não terminou. Tente de novo.';

    /** Por quanto tempo a importação com erro continua no painel da lista de editais. */
    public const int MINUTOS_ERRO_NO_PAINEL = 30;

    /**
     * Folga depois de sair do painel até a importação com erro ser apagada (Prunable): quem ainda está com a página
     * aberta consegue tentar de novo ou descartar.
     */
    public const int MINUTOS_ATE_APAGAR = 60;

    /** Duração prevista de uma análise sem histórico, e os limites da prevista pelo histórico. */
    public const int SEGUNDOS_PREVISTOS_PADRAO = 120;

    public const int SEGUNDOS_PREVISTOS_MINIMO = 20;

    public const int SEGUNDOS_PREVISTOS_MAXIMO = 600;

    /** Quantas análises concluídas, das mais recentes, entram na previsão. */
    private const int ANALISES_NA_PREVISAO = 10;

    protected $table = 'importacoes_edital';

    protected $fillable = [
        'user_id', 'edital_id', 'situacao', 'pdf', 'pdf_nome', 'provedor', 'modelo',
        'tokens_entrada', 'tokens_saida', 'tokens_total', 'erro', 'resposta', 'confiabilidade', 'atencao',
        'conferida_em', 'iniciada_em', 'execucao',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'situacao' => SituacaoImportacao::class,
            'provedor' => ProvedorIa::class,
            'tokens_entrada' => 'integer',
            'tokens_saida' => 'integer',
            'tokens_total' => 'integer',
            'resposta' => 'array',
            'confiabilidade' => 'array',
            'atencao' => 'array',
            'conferida_em' => 'datetime',
            'iniciada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Edital, $this> */
    public function edital(): BelongsTo
    {
        return $this->belongsTo(Edital::class);
    }

    /** A situação para a tela: "analisando" há mais de 15 minutos é mostrada como erro. */
    public function situacaoExibida(?CarbonInterface $agora = null): SituacaoImportacao
    {
        return $this->semTermino($agora) ? SituacaoImportacao::Erro : $this->situacao;
    }

    /** O erro para a tela (o gravado ou o de análise sem término). */
    public function erroExibido(?CarbonInterface $agora = null): ?string
    {
        return $this->semTermino($agora) ? self::ERRO_SEM_TERMINO : $this->erro;
    }

    /**
     * Quanto uma análise costuma levar, para a barra de progresso da tela: a mediana das últimas concluídas, do início
     * da análise à criação do edital (mesmo excluído depois); sem histórico, o padrão.
     */
    public static function segundosPrevistos(): int
    {
        $duracoes = static::query()
            ->select(['id', 'edital_id', 'iniciada_em'])
            ->where('situacao', SituacaoImportacao::Concluida->value)
            ->whereNotNull('edital_id')
            ->with(['edital' => fn (BelongsTo $edital) => $edital->withTrashed()->select(['id', 'created_at'])])
            ->latest('id')
            ->limit(self::ANALISES_NA_PREVISAO)
            ->get()
            ->filter(fn (ImportacaoEdital $importacao): bool => $importacao->edital !== null)
            ->map(fn (ImportacaoEdital $importacao): float => $importacao->iniciada_em->diffInSeconds($importacao->edital->created_at))
            ->filter(fn (float $segundos): bool => $segundos > 0);

        if ($duracoes->isEmpty()) {
            return self::SEGUNDOS_PREVISTOS_PADRAO;
        }

        return (int) min(self::SEGUNDOS_PREVISTOS_MAXIMO, max(self::SEGUNDOS_PREVISTOS_MINIMO, round($duracoes->median())));
    }

    /** Segundos desde o início da análise em curso. */
    public function segundosDecorridos(?CarbonInterface $agora = null): int
    {
        return max(0, (int) $this->iniciada_em->diffInSeconds($agora ?? now()));
    }

    /** Identificador para uma nova análise. */
    public static function novaExecucao(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Abre uma nova análise no mesmo registro se ele estiver com erro (gravado ou por análise sem término), numa só
     * atualização condicional: de duas tentativas ao mesmo tempo, só uma consegue.
     */
    public function abrirNovaTentativa(ProvedorIa $provedor, string $modelo, ?CarbonInterface $agora = null): bool
    {
        $agora ??= now();

        $abertas = static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $consulta) => $consulta
                ->where('situacao', SituacaoImportacao::Erro->value)
                ->orWhere(fn (Builder $analisando) => $analisando
                    ->where('situacao', SituacaoImportacao::Analisando->value)
                    ->where('iniciada_em', '<', $agora->copy()->subMinutes(self::MINUTOS_LIMITE))))
            ->update([
                'situacao' => SituacaoImportacao::Analisando->value,
                'erro' => null,
                'provedor' => $provedor->value,
                'modelo' => $modelo,
                'iniciada_em' => $agora,
                'execucao' => self::novaExecucao(),
            ]);

        if ($abertas !== 1) {
            return false;
        }

        $this->refresh();

        return true;
    }

    /**
     * As do painel da lista de editais: as analisando e as com erro há menos de MINUTOS_ERRO_NO_PAINEL (a análise sem
     * término vira erro aos MINUTOS_LIMITE). As concluídas saem, porque o edital já está na lista.
     */
    public function scopeDoPainel(Builder $consulta, ?CarbonInterface $agora = null): void
    {
        $agora ??= now();

        $consulta->where(fn (Builder $painel) => $painel
            ->where(fn (Builder $analisando) => $analisando
                ->where('situacao', SituacaoImportacao::Analisando->value)
                ->where('iniciada_em', '>=', $agora->copy()->subMinutes(self::MINUTOS_LIMITE + self::MINUTOS_ERRO_NO_PAINEL)))
            ->orWhere(fn (Builder $comErro) => $comErro
                ->where('situacao', SituacaoImportacao::Erro->value)
                ->where('updated_at', '>=', $agora->copy()->subMinutes(self::MINUTOS_ERRO_NO_PAINEL))));
    }

    /**
     * As importações com erro que saíram do painel há mais de MINUTOS_ATE_APAGAR (inclusive a análise sem término), já
     * fora do alcance da tela. Apagadas com o PDF ao enviar uma nova importação (ImportacaoEditalController::store) ou
     * pelo model:prune. As concluídas ficam: o edital mostra o quadro "Importado pela IA" a partir delas.
     */
    public function prunable(): Builder
    {
        $agora = now();

        return static::query()->where(fn (Builder $abandonadas) => $abandonadas
            ->where(fn (Builder $semTermino) => $semTermino
                ->where('situacao', SituacaoImportacao::Analisando->value)
                ->where('iniciada_em', '<', $agora->copy()->subMinutes(self::MINUTOS_LIMITE + self::MINUTOS_ERRO_NO_PAINEL + self::MINUTOS_ATE_APAGAR)))
            ->orWhere(fn (Builder $comErro) => $comErro
                ->where('situacao', SituacaoImportacao::Erro->value)
                ->where('updated_at', '<', $agora->copy()->subMinutes(self::MINUTOS_ERRO_NO_PAINEL + self::MINUTOS_ATE_APAGAR))));
    }

    /** O PDF enviado sai junto com a importação apagada pelo Prunable. */
    protected function pruning(): void
    {
        ArquivoArmazenado::apagar(self::DISCO, $this->pdf);
    }

    /** Só a importação ainda na análise $execucao: analisando e sem edital. */
    public function scopeDaExecucao(Builder $consulta, string $execucao): void
    {
        $consulta->where('execucao', $execucao)
            ->where('situacao', SituacaoImportacao::Analisando->value)
            ->whereNull('edital_id');
    }

    private function semTermino(?CarbonInterface $agora): bool
    {
        return $this->situacao === SituacaoImportacao::Analisando
            && $this->iniciada_em->lt(($agora ?? now())->copy()->subMinutes(self::MINUTOS_LIMITE));
    }
}
