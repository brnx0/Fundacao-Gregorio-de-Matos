<?php

namespace App\Models;

use App\Enums\NaturezaProponente;
use App\Enums\SituacaoEdital;
use App\Enums\TipoEdital;
use App\Models\Concerns\UsaDiaCivil;
use Carbon\CarbonInterface;
use Database\Factories\EditalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Edital da vitrine. Rascunho pode ficar sem título, categoria, resumo e datas; publicar exige pendenciasParaPublicar() vazia.
 * As datas de inscrição do edital são as que a vitrine, a situação e os filtros usam.
 */
class Edital extends Model
{
    /** @use HasFactory<EditalFactory> */
    use HasFactory;

    use SoftDeletes;
    use UsaDiaCivil;

    public const string AVISO_QUESTIONARIO_TRAVADO = 'O questionário está travado porque o edital já tem inscrição finalizada: mudar as perguntas deixaria respostas enviadas sem pergunta.';

    /** Aviso da trava dos documentos exigidos (ver documentosTravados()). */
    public const string AVISO_DOCUMENTOS_TRAVADOS = 'Os documentos exigidos não podem ser removidos porque o edital já tem inscrição com resultado da homologação: a remoção apagaria os arquivos em que a decisão se apoiou.';

    /** Avisos das travas depois da primeira nota (ver avaliacaoIniciada()). */
    public const string AVISO_CRITERIOS_TRAVADOS = 'Este edital já tem notas dadas: os critérios não podem ser acrescentados, removidos, nem ter peso, pontuação máxima ou categorias alterados. Nome e descrição continuam editáveis.';

    public const string AVISO_CATEGORIAS_TRAVADAS = 'Este edital já tem notas dadas: as categorias salvas não podem ser removidas.';

    /** Pendências para distribuir as inscrições entre os avaliadores. */
    public const string PENDENCIA_SEM_AVALIADOR = 'Inclua ao menos um avaliador ativo no edital.';

    public const string PENDENCIA_SEM_CRITERIO = 'Cadastre ao menos um critério na aba Avaliação do edital.';

    public const string PENDENCIA_CRITERIO_SEM_PONTOS = 'Preencha a pontuação máxima de todos os critérios na aba Avaliação do edital.';

    /** Pendências para publicar que o formulário também confere no envio (SalvarEditalRequest). */
    public const string PENDENCIA_CHAMADA = 'Cadastre ao menos uma chamada com início e fim das inscrições.';

    public const string PENDENCIA_NATUREZA = 'Marque ao menos uma natureza que pode se inscrever.';

    public const string PENDENCIA_CATEGORIA = 'Cadastre ao menos uma categoria.';

    /** A imagem aparece em todas as vitrines do portal: sem ela o edital não é publicado. */
    public const string PENDENCIA_IMAGEM = 'Envie a imagem da vitrine.';

    /**
     * Limite da descrição do card (resumo): o que cabe inteiro nas três linhas do card da lista de editais e nas duas
     * do card da vitrine da página inicial no computador. No carrossel do celular, o card corta a partir de ~55.
     */
    public const int TAMANHO_DO_RESUMO = 70;

    /** Parâmetros da lista da retaguarda na aba Publicados; sem eles, a lista abre em Não publicados. */
    public const array ABA_PUBLICADOS = ['publicado' => 'sim'];

    /** Seções de texto informativas (coluna `informacoes`), na ordem de exibição: chave => rótulo. */
    public const array SECOES_INFORMATIVAS = [
        'recursos_pagamento' => 'Recursos e pagamento',
        'vedacoes' => 'Vedações',
        'etapas_prazos' => 'Etapas e prazos',
        'contrapartida_execucao' => 'Contrapartida e execução',
        'prestacao_contas' => 'Prestação de contas',
        'base_legal' => 'Base legal',
        'anexos' => 'Anexos',
        'contatos' => 'Contatos',
        'outras_regras' => 'Outras regras',
    ];

    protected $table = 'editais';

    protected $fillable = [
        'titulo', 'slug', 'resumo', 'categoria',
        'inscricoes_inicio', 'inscricoes_fim', 'imagem', 'pdf', 'publicado',
        'numero', 'tipo', 'objeto', 'dom_data', 'dom_numero', 'naturezas', 'aceita_coletivos',
        'inscricoes_por_proponente', 'limite_por_chamada', 'cotas', 'nota_maxima', 'nota_corte',
        'pontuacao_extra', 'informacoes', 'forma_inscricao_original',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'inscricoes_inicio' => 'date',
            'inscricoes_fim' => 'date',
            'publicado' => 'boolean',
            'tipo' => TipoEdital::class,
            'dom_data' => 'date',
            'naturezas' => AsEnumCollection::of(NaturezaProponente::class),
            'aceita_coletivos' => 'boolean',
            'inscricoes_por_proponente' => 'integer',
            'limite_por_chamada' => 'boolean',
            'cotas' => 'array',
            'nota_maxima' => 'integer',
            'nota_corte' => 'decimal:2',
            'pontuacao_extra' => 'array',
            'informacoes' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function inscricoesInicio(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function inscricoesFim(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    protected function domData(): Attribute
    {
        return Attribute::set(fn ($v) => self::diaParaGravar($v));
    }

    /** @return HasMany<EditalChamada, $this> */
    public function chamadas(): HasMany
    {
        return $this->hasMany(EditalChamada::class)->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<EditalCategoria, $this> */
    public function categorias(): HasMany
    {
        return $this->hasMany(EditalCategoria::class)->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<EditalDocumento, $this> */
    public function documentos(): HasMany
    {
        return $this->hasMany(EditalDocumento::class)->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<EditalCriterio, $this> */
    public function criterios(): HasMany
    {
        return $this->hasMany(EditalCriterio::class)->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<EditalQuestionarioSecao, $this> */
    public function secoesDoQuestionario(): HasMany
    {
        return $this->hasMany(EditalQuestionarioSecao::class)->orderBy('ordem')->orderBy('id');
    }

    /** @return HasMany<EditalPergunta, $this> */
    public function perguntas(): HasMany
    {
        return $this->hasMany(EditalPergunta::class);
    }

    /** @return HasMany<Inscricao, $this> */
    public function inscricoes(): HasMany
    {
        return $this->hasMany(Inscricao::class);
    }

    /** @return HasMany<EditalAvaliador, $this> */
    public function avaliadores(): HasMany
    {
        return $this->hasMany(EditalAvaliador::class);
    }

    /** O número nasce na primeira finalização e fica ao reabrir (finalizada_em é zerado), então é ele que marca a trava. */
    public function questionarioTravado(): bool
    {
        return $this->inscricoes()->whereNotNull('numero')->exists();
    }

    /** Com resultado da homologação em alguma inscrição, os documentos exigidos não saem mais (só entram ou mudam). */
    public function documentosTravados(): bool
    {
        return $this->inscricoes()->whereNotNull('homologacao')->exists();
    }

    /** Com nota dada em alguma inscrição, critérios e categorias deixam de poder mudar. */
    public function avaliacaoIniciada(): bool
    {
        return InscricaoAvaliacaoNota::query()
            ->whereHas('avaliacao.inscricao', fn (Builder $inscricoes) => $inscricoes->where('edital_id', $this->id))
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function pendenciasDaDistribuicao(): array
    {
        $pendencias = [];

        $temAvaliadorAtivo = $this->avaliadores()->whereHas('avaliador', fn (Builder $avaliadores) => $avaliadores->where('ativo', true))->exists();
        if (! $temAvaliadorAtivo) {
            $pendencias[] = self::PENDENCIA_SEM_AVALIADOR;
        }

        if (! $this->criterios()->exists()) {
            $pendencias[] = self::PENDENCIA_SEM_CRITERIO;
        } elseif ($this->criterios()->whereNull('pontos_maximos')->exists()) {
            $pendencias[] = self::PENDENCIA_CRITERIO_SEM_PONTOS;
        }

        return $pendencias;
    }

    /**
     * @return list<string>
     */
    public function pendenciasDoQuestionario(): array
    {
        $semVinculo = $this->perguntas()
            ->where('todas_categorias', false)
            ->whereDoesntHave('categorias')
            ->count();

        return $semVinculo === 0 ? [] : [self::avisoSemVinculo($semVinculo)];
    }

    /** Pergunta sem vínculo não aparece a ninguém: nem é de todas as categorias, nem de alguma. */
    public static function avisoSemVinculo(int $perguntas): string
    {
        return $perguntas === 1
            ? 'Vincule às categorias 1 pergunta do questionário.'
            : "Vincule às categorias {$perguntas} perguntas do questionário.";
    }

    /** Nula enquanto o edital não tem as duas datas de inscrição (rascunho). */
    public function situacao(?CarbonInterface $agora = null): ?SituacaoEdital
    {
        if (! $this->temPeriodo()) {
            return null;
        }

        $hoje = self::hoje($agora)->toDateString();

        return match (true) {
            $hoje < $this->inscricoes_inicio->toDateString() => SituacaoEdital::EmBreve,
            $hoje > $this->inscricoes_fim->toDateString() => SituacaoEdital::Encerrado,
            default => SituacaoEdital::Aberto,
        };
    }

    public function temPeriodo(): bool
    {
        return $this->inscricoes_inicio !== null && $this->inscricoes_fim !== null;
    }

    /**
     * O que falta para o edital poder ser publicado; vazia quando está completo.
     *
     * @return list<string>
     */
    public function pendenciasParaPublicar(): array
    {
        return array_values($this->pendenciasPorCampo());
    }

    /**
     * As mesmas pendências, cada uma no campo do formulário que a resolve (a tela marca a aba dele).
     *
     * @return array<string, string>
     */
    public function pendenciasPorCampo(): array
    {
        $pendencias = [];

        if (blank($this->titulo)) {
            $pendencias['titulo'] = 'Informe o título do edital.';
        }
        if (blank($this->pdf)) {
            $pendencias['pdf'] = 'Envie o PDF do edital.';
        }
        if (blank($this->imagem)) {
            $pendencias['imagem'] = self::PENDENCIA_IMAGEM;
        }
        if (! $this->chamadas()->whereNotNull('inicio')->whereNotNull('fim')->exists()) {
            $pendencias['chamadas'] = self::PENDENCIA_CHAMADA;
        }
        if (blank($this->naturezas)) {
            $pendencias['naturezas'] = self::PENDENCIA_NATUREZA;
        }
        if (! $this->categorias()->exists()) {
            $pendencias['categorias'] = self::PENDENCIA_CATEGORIA;
        }
        foreach ($this->pendenciasDoQuestionario() as $pendencia) {
            $pendencias['questionario'] = $pendencia;
        }

        return $pendencias;
    }

    /** URL da rota que entrega o PDF; nula enquanto o edital não tem arquivo. */
    public function pdfUrl(): ?string
    {
        return $this->pdf ? route('editais.pdf', $this) : null;
    }

    public function imagemUrl(): ?string
    {
        return $this->imagem ? Storage::disk('public')->url($this->imagem) : null;
    }

    public function scopePublicados(Builder $query): void
    {
        $query->where('publicado', true);
    }

    /** Visível no portal: publicado, com PDF e com as datas de inscrição. */
    public function scopeVisiveis(Builder $query): void
    {
        $query->publicados()
            ->whereNotNull('pdf')
            ->whereNotNull('inscricoes_inicio')
            ->whereNotNull('inscricoes_fim');
    }

    public function estaVisivel(): bool
    {
        return $this->publicado && filled($this->pdf) && $this->temPeriodo();
    }

    /**
     * Parâmetros da lista da retaguarda que abrem a aba deste edital: Não publicados (a padrão) ou Publicados.
     *
     * @return array<string, string>
     */
    public function abaDaLista(): array
    {
        return $this->publicado ? self::ABA_PUBLICADOS : [];
    }

    /** Sem as colunas CLOB (resumo, cotas, pontuação extra, informações): o que listagens e cards precisam. */
    public function scopeParaListagem(Builder $query): void
    {
        $query->select([
            'id', 'titulo', 'slug', 'categoria', 'inscricoes_inicio', 'inscricoes_fim', 'imagem', 'pdf', 'publicado',
        ]);
    }

    public function scopeNaVitrine(Builder $query, ?CarbonInterface $agora = null): void
    {
        $query->visiveis()
            ->where('inscricoes_fim', '>=', self::hoje($agora)->toDateString())
            ->orderBy('inscricoes_inicio');
    }

    /** Mesma regra de situacao(), em consulta: dias em intervalo semiaberto (ver UsaDiaCivil). Sem datas não entra em nenhuma (comparação com nulo é falsa). */
    public function scopeNaSituacao(Builder $query, SituacaoEdital $situacao, ?CarbonInterface $agora = null): void
    {
        $hoje = self::hoje($agora);
        $amanha = $hoje->addDay()->toDateString();

        match ($situacao) {
            SituacaoEdital::EmBreve => $query->where('inscricoes_inicio', '>=', $amanha),
            SituacaoEdital::Encerrado => $query->where('inscricoes_fim', '<', $hoje->toDateString()),
            SituacaoEdital::Aberto => $query
                ->where('inscricoes_inicio', '<', $amanha)
                ->where('inscricoes_fim', '>=', $hoje->toDateString()),
        };
    }

    public static function contarAbertos(?CarbonInterface $agora = null): int
    {
        return static::query()->visiveis()->naSituacao(SituacaoEdital::Aberto, $agora)->count();
    }
}
