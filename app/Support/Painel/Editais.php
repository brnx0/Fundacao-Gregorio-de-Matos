<?php

namespace App\Support\Painel;

use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\TipoEdital;
use App\Models\Edital;
use App\Models\EditalCategoria;
use App\Models\EditalCategoriaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Editais e investimento: situação, valor e vagas oferecidos, cotas, naturezas aceitas e a demanda de cada edital.
 * Editais são poucos: carregam-se as colunas (inclusive o CLOB das cotas) e as contas saem em PHP.
 *
 * @phpstan-type Fatia array{valor: string, rotulo: string, total: int, percentual: float}
 * @phpstan-type Oferta array{centavos: int, vagas: int, semValor: int}
 * @phpstan-type Demanda array{id: int, titulo: string, publicado: bool, situacao: string, situacaoRotulo: string, vagas: int, enviadas: int, rascunhos: int, porVaga: float|null, centavos: int, cotistas: float}
 */
final class Editais
{
    /** Quantos editais a tabela de demanda mostra, dos mais procurados para os menos. */
    private const int LINHAS_DA_DEMANDA = 12;

    private const string SEM_PERIODO = 'sem_periodo';

    /**
     * @return array{
     *     total: int,
     *     publicados: int,
     *     situacoes: list<Fatia>,
     *     centavos: int,
     *     vagas: int,
     *     semValor: int,
     *     porCategoria: list<array{rotulo: string, centavos: int, editais: int}>,
     *     tipos: list<Fatia>,
     *     cotas: list<Fatia>,
     *     naturezas: list<Fatia>,
     *     coletivos: array{total: int, percentual: float},
     *     demanda: list<Demanda>,
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $editais = $consultas->editais()
            ->get(['id', 'titulo', 'categoria', 'tipo', 'publicado', 'inscricoes_inicio', 'inscricoes_fim', 'naturezas', 'aceita_coletivos', 'cotas']);
        $publicados = $editais->filter(fn (Edital $edital): bool => $edital->publicado)->values();
        $oferta = self::oferta($consultas->editais()->select('editais.id'));
        $daOferta = fn (Edital $edital): array => $oferta[(int) $edital->id] ?? ['centavos' => 0, 'vagas' => 0, 'semValor' => 0];
        $totalPublicados = $publicados->count();
        $coletivos = $publicados->filter(fn (Edital $edital): bool => (bool) $edital->aceita_coletivos)->count();
        $rotulosDosTipos = collect(TipoEdital::cases())
            ->mapWithKeys(fn (TipoEdital $tipo): array => [$tipo->value => $tipo->rotulo()])
            ->put('', 'Não informado')
            ->all();

        return [
            'total' => $editais->count(),
            'publicados' => $totalPublicados,
            'situacoes' => self::fatias(
                $publicados->countBy(fn (Edital $edital): string => $edital->situacao()?->value ?? self::SEM_PERIODO)->all(),
                [
                    'aberto' => 'Abertos',
                    'em_breve' => 'Em breve',
                    'encerrado' => 'Encerrados',
                    self::SEM_PERIODO => 'Sem período de inscrição',
                ],
                $totalPublicados,
            ),
            'centavos' => $publicados->sum(fn (Edital $edital): int => $daOferta($edital)['centavos']),
            'vagas' => $publicados->sum(fn (Edital $edital): int => $daOferta($edital)['vagas']),
            'semValor' => $publicados->sum(fn (Edital $edital): int => $daOferta($edital)['semValor']),
            'porCategoria' => self::porCategoria($publicados, $daOferta),
            'tipos' => self::fatias(
                $publicados->countBy(fn (Edital $edital): string => $edital->tipo?->value ?? '')->all(),
                $rotulosDosTipos,
                $totalPublicados,
            ),
            'cotas' => array_map(function (ModalidadeCota $modalidade) use ($publicados, $totalPublicados): array {
                $total = $publicados->filter(fn (Edital $edital): bool => (bool) ($edital->cotas[$modalidade->value]['ativa'] ?? false))->count();

                return ['valor' => $modalidade->value, 'rotulo' => $modalidade->rotulo(), 'total' => $total, 'percentual' => ConsultasDoPainel::percentual($total, $totalPublicados)];
            }, ModalidadeCota::cases()),
            'naturezas' => array_map(function (NaturezaProponente $natureza) use ($publicados, $totalPublicados): array {
                $total = $publicados->filter(fn (Edital $edital): bool => (bool) $edital->naturezas?->contains($natureza))->count();

                return ['valor' => $natureza->value, 'rotulo' => $natureza->rotulo(), 'total' => $total, 'percentual' => ConsultasDoPainel::percentual($total, $totalPublicados)];
            }, NaturezaProponente::cases()),
            'coletivos' => ['total' => $coletivos, 'percentual' => ConsultasDoPainel::percentual($coletivos, $totalPublicados)],
            'demanda' => self::demanda($consultas, $editais, $daOferta),
        ];
    }

    /**
     * Valor e vagas oferecidos por edital. Cada categoria conta valor × vagas; a que tem itens conta os itens no lugar
     * dela. Categoria ou item sem valor ou sem vagas não entra no valor (conta em semValor), mas as vagas informadas contam.
     *
     * @param  Builder<Edital>  $editais  Consulta que seleciona só o id dos editais.
     * @return array<int, Oferta>
     */
    public static function oferta(Builder $editais): array
    {
        $categorias = EditalCategoria::query()
            ->whereIn('edital_id', $editais)
            ->get(['id', 'edital_id', 'valor_centavos', 'vagas']);
        $itens = EditalCategoriaItem::query()
            ->whereIn('edital_categoria_id', EditalCategoria::query()->select('id')->whereIn('edital_id', $editais))
            ->get(['edital_categoria_id', 'valor_centavos', 'vagas'])
            ->groupBy(fn (EditalCategoriaItem $item): int => (int) $item->edital_categoria_id);

        $porEdital = [];
        foreach ($categorias as $categoria) {
            $id = (int) $categoria->edital_id;
            $porEdital[$id] ??= ['centavos' => 0, 'vagas' => 0, 'semValor' => 0];

            foreach ($itens->get((int) $categoria->id, collect([$categoria])) as $linha) {
                $porEdital[$id]['vagas'] += (int) $linha->vagas;
                if ($linha->valor_centavos === null || $linha->vagas === null) {
                    $porEdital[$id]['semValor']++;

                    continue;
                }
                $porEdital[$id]['centavos'] += (int) $linha->valor_centavos * (int) $linha->vagas;
            }
        }

        return $porEdital;
    }

    /**
     * Valor oferecido por "categoria" do edital (o rótulo livre da vitrine), sem diferenciar maiúsculas e acentos.
     *
     * @param  Collection<int, Edital>  $publicados
     * @param  callable(Edital): Oferta  $daOferta
     * @return list<array{rotulo: string, centavos: int, editais: int}>
     */
    private static function porCategoria(Collection $publicados, callable $daOferta): array
    {
        return $publicados
            ->groupBy(fn (Edital $edital): string => Str::slug((string) $edital->categoria))
            ->map(fn (Collection $grupo, string $chave): array => [
                'rotulo' => $chave === '' ? 'Sem categoria' : trim((string) $grupo->first()->categoria),
                'centavos' => $grupo->sum(fn (Edital $edital): int => $daOferta($edital)['centavos']),
                'editais' => $grupo->count(),
            ])
            ->sortByDesc('centavos')
            ->values()
            ->all();
    }

    /**
     * Procura de cada edital: inscrições enviadas e em rascunho, inscrições por vaga e quanto das enviadas pediu cota.
     *
     * @param  Collection<int, Edital>  $editais
     * @param  callable(Edital): Oferta  $daOferta
     * @return list<Demanda>
     */
    private static function demanda(ConsultasDoPainel $consultas, Collection $editais, callable $daOferta): array
    {
        $enviadas = $consultas->totaisPor($consultas->enviadas(), 'inscricoes.edital_id');
        $rascunhos = $consultas->totaisPor($consultas->rascunhos(), 'inscricoes.edital_id');
        // Lista de cotas vazia é "[]": com cota, há ao menos um valor entre aspas.
        $cotistas = $consultas->totaisPor($consultas->enviadas()->where('inscricoes.cotas', 'like', '%"%'), 'inscricoes.edital_id');

        return $editais
            ->map(function (Edital $edital) use ($enviadas, $rascunhos, $cotistas, $daOferta): array {
                $id = (string) $edital->id;
                $situacao = $edital->situacao();
                $oferta = $daOferta($edital);
                $enviadasDoEdital = $enviadas[$id] ?? 0;

                return [
                    'id' => (int) $edital->id,
                    'titulo' => $edital->titulo ?? 'Sem título',
                    'publicado' => (bool) $edital->publicado,
                    'situacao' => $situacao?->value ?? self::SEM_PERIODO,
                    'situacaoRotulo' => $situacao?->rotulo() ?? 'Sem período',
                    'vagas' => $oferta['vagas'],
                    'enviadas' => $enviadasDoEdital,
                    'rascunhos' => $rascunhos[$id] ?? 0,
                    'porVaga' => $oferta['vagas'] > 0 ? round($enviadasDoEdital / $oferta['vagas'], 1) : null,
                    'centavos' => $oferta['centavos'],
                    'cotistas' => ConsultasDoPainel::percentual($cotistas[$id] ?? 0, $enviadasDoEdital),
                ];
            })
            ->sortBy([['enviadas', 'desc'], ['rascunhos', 'desc'], ['titulo', 'asc']])
            ->take(self::LINHAS_DA_DEMANDA)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $totais
     * @param  array<string, string>  $rotulos  Valor => rótulo, na ordem em que aparecem.
     * @return list<Fatia>
     */
    private static function fatias(array $totais, array $rotulos, int $base): array
    {
        return array_map(
            fn (string $valor, string $rotulo): array => [
                'valor' => $valor,
                'rotulo' => $rotulo,
                'total' => $totais[$valor] ?? 0,
                'percentual' => ConsultasDoPainel::percentual($totais[$valor] ?? 0, $base),
            ],
            array_map('strval', array_keys($rotulos)),
            array_values($rotulos),
        );
    }
}
