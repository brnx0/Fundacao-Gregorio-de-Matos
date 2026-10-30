<?php

namespace App\Models;

use App\Enums\ChecagemDocumento;
use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\SituacaoConferencia;
use App\Enums\SituacaoEdital;
use App\Enums\SituacaoHomologacao;
use App\Enums\SituacaoInscricao;
use App\Models\Concerns\UsaDiaCivil;
use App\Support\Inscricao\QuestionarioDaInscricao;
use App\Support\Inscricao\RegrasDaInscricao;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\InscricaoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Inscrição de um proponente (CPF ou CNPJ, só dígitos em documento_cadastro) num edital.
 * cotas: lista de ModalidadeCota escolhidas na inscrição.
 * perfil: retrato do perfil da pessoa física dona (PessoaFisica::retratoDoPerfil), refeito a cada finalização.
 */
class Inscricao extends Model
{
    /** @use HasFactory<InscricaoFactory> */
    use HasFactory;

    use UsaDiaCivil;

    /** Disco e pasta dos documentos enviados. */
    public const string DISCO = 'local';

    public const string PASTA = 'inscricoes';

    /** Tipos aceitos nos documentos enviados: PDF ou imagem, que a IA confere do mesmo jeito. */
    public const array TIPOS_DE_ARQUIVO = ['application/pdf', 'image/jpeg', 'image/png'];

    protected $table = 'inscricoes';

    protected $fillable = [
        'edital_id', 'edital_chamada_id', 'user_id', 'organizacao_id', 'documento_cadastro', 'natureza',
        'edital_categoria_id', 'cotas', 'situacao', 'numero', 'finalizada_em', 'perfil',
        'homologacao', 'homologacao_motivo', 'homologada_por', 'homologada_em',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'natureza' => NaturezaProponente::class,
            'cotas' => AsEnumCollection::of(ModalidadeCota::class),
            'situacao' => SituacaoInscricao::class,
            'finalizada_em' => 'datetime',
            'perfil' => 'array',
            'homologacao' => SituacaoHomologacao::class,
            'homologada_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Edital, $this> */
    public function edital(): BelongsTo
    {
        return $this->belongsTo(Edital::class);
    }

    /** @return BelongsTo<EditalChamada, $this> */
    public function chamada(): BelongsTo
    {
        return $this->belongsTo(EditalChamada::class, 'edital_chamada_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organizacao, $this> */
    public function organizacao(): BelongsTo
    {
        return $this->belongsTo(Organizacao::class);
    }

    /** @return BelongsTo<EditalCategoria, $this> */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(EditalCategoria::class, 'edital_categoria_id');
    }

    /** @return HasMany<InscricaoDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(InscricaoDocumento::class);
    }

    /** @return HasMany<InscricaoResposta, $this> */
    public function respostas(): HasMany
    {
        return $this->hasMany(InscricaoResposta::class);
    }

    /** @return HasMany<InscricaoConferencia, $this> */
    public function conferencias(): HasMany
    {
        return $this->hasMany(InscricaoConferencia::class);
    }

    /** @return HasOne<InscricaoRecurso, $this> */
    public function recurso(): HasOne
    {
        return $this->hasOne(InscricaoRecurso::class);
    }

    /** @return BelongsTo<User, $this> */
    public function homologador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homologada_por');
    }

    /**
     * Documentos do edital que esta inscrição precisa enviar (de todas as etapas), na ordem do edital:
     * os da natureza do cadastro, da categoria escolhida e das cotas marcadas.
     *
     * @return Collection<int, EditalDocumento>
     */
    public function documentosExigidos(): Collection
    {
        $categoriaId = $this->edital_categoria_id;
        $cotas = $this->cotas?->map(fn (ModalidadeCota $cota): string => $cota->value)->all() ?? [];

        return $this->edital->documentos()
            ->where(function (Builder $query) use ($categoriaId): void {
                $query->where('todas_categorias', true);
                if ($categoriaId !== null) {
                    $query->orWhereHas('categorias', fn (Builder $categorias) => $categorias->whereKey($categoriaId));
                }
            })
            ->where(fn (Builder $query) => $query->whereNull('modalidade_cota')->orWhereIn('modalidade_cota', $cotas))
            ->get()
            ->filter(fn (EditalDocumento $documento): bool => RegrasDaInscricao::aceitaNatureza($documento->naturezas, $this->natureza))
            ->values();
    }

    /**
     * O que impede de finalizar, na ordem dos documentos do edital: obrigatório sem arquivo, documento ainda sendo
     * conferido (a conferência parada há mais de 5 minutos já conta como não conferida) e documento em "Rigorosa"
     * (obrigatório ou opcional anexado) que não foi aprovado. Reprovado ou não conferido em "Aceita sem validar" não impede,
     * nem "Rigorosa" com a validação por IA desligada (o documento segue como enviado). Depois dos documentos, uma linha
     * por seção visível do questionário com pergunta obrigatória sem resposta.
     *
     * @return list<string>
     */
    public function pendenciasParaFinalizar(?CarbonInterface $agora = null, ?QuestionarioDaInscricao $questionario = null): array
    {
        $enviados = $this->documentos()->get()->keyBy(fn (InscricaoDocumento $enviado): int => (int) $enviado->edital_documento_id);
        $pendencias = [];
        $validacaoLigada = ConfiguracaoIa::validacaoLigada();

        foreach ($this->documentosExigidos() as $exigido) {
            $situacao = $enviados->get((int) $exigido->id)?->situacaoExibida($agora);

            if ($situacao === null) {
                if ($exigido->obrigatorio) {
                    $pendencias[] = "Anexe \"{$exigido->nome}\".";
                }
            } elseif ($situacao === SituacaoConferencia::Conferindo) {
                $pendencias[] = "Aguarde a conferência de \"{$exigido->nome}\".";
            } elseif ($exigido->checagemNaInscricao($validacaoLigada) === ChecagemDocumento::Rigorosa && $situacao !== SituacaoConferencia::Aprovado) {
                $pendencias[] = "\"{$exigido->nome}\" precisa ser aprovado na conferência.";
            }
        }

        $questionario ??= new QuestionarioDaInscricao($this);
        $respostas = $questionario->respostasSalvas();

        foreach ($questionario->secoesVisiveis($respostas) as $secao) {
            /** @var EditalQuestionarioSecao $secao */
            if ($questionario->faltando($respostas, (int) $secao->id) !== []) {
                $pendencias[] = "Responda as perguntas obrigatórias de \"{$secao->titulo}\".";
            }
        }

        return $pendencias;
    }

    /** Edital no portal e chamada da inscrição aberta (dias da chamada, ver UsaDiaCivil). */
    public function noPeriodo(?CarbonInterface $agora = null): bool
    {
        return $this->edital->estaVisivel() && $this->chamada?->situacao($agora) === SituacaoEdital::Aberto;
    }

    /** Chamada da inscrição no período de homologação; falso sem chamada (apagada) ou sem as datas. */
    public function naHomologacao(?CarbonInterface $agora = null): bool
    {
        return $this->chamada?->naHomologacao($agora) === true;
    }

    /**
     * Último dia para recorrer (meia-noite, fuso da aplicação; vale até 23:59 dele): três dias corridos depois do
     * fim da homologação ou do dia da decisão, o que vier por último. Nulo sem decisão.
     */
    public function prazoDoRecurso(): ?CarbonImmutable
    {
        if ($this->homologada_em === null) {
            return null;
        }

        $ultimoDia = self::hoje($this->homologada_em);
        $fimDaHomologacao = $this->chamada?->homologacao_fim;

        if ($fimDaHomologacao !== null) {
            $ultimoDia = $ultimoDia->max(CarbonImmutable::parse($fimDaHomologacao->toDateString(), config('app.timezone')));
        }

        return $ultimoDia->addDays(3);
    }

    /** Não homologada, sem recurso enviado e hoje até o último dia do prazo do recurso (dias inteiros, ver UsaDiaCivil). */
    public function podeRecorrer(?CarbonInterface $agora = null): bool
    {
        $prazo = $this->prazoDoRecurso();

        return $this->homologacao === SituacaoHomologacao::NaoHomologada
            && $prazo !== null
            && self::hoje($agora)->toDateString() <= $prazo->toDateString()
            && ! $this->recurso()->exists();
    }

    /**
     * Situação mostrada ao proponente: rascunho, em homologação (finalizada sem decisão) ou a da homologação.
     *
     * @return array{chave: string, rotulo: string}
     */
    public function situacaoNoPortal(): array
    {
        if ($this->situacao === SituacaoInscricao::Rascunho) {
            return ['chave' => 'rascunho', 'rotulo' => 'Rascunho'];
        }

        if ($this->homologacao === null) {
            return ['chave' => 'em_homologacao', 'rotulo' => 'Em homologação'];
        }

        return ['chave' => $this->homologacao->value, 'rotulo' => $this->homologacao->rotuloNoPortal()];
    }
}
