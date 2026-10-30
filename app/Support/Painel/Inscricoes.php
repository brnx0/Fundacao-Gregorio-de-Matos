<?php

namespace App\Support\Painel;

use App\Enums\ModalidadeCota;
use App\Enums\NaturezaProponente;
use App\Enums\SituacaoConferencia;
use App\Models\InscricaoDocumento;

/**
 * Inscrições: quantas foram enviadas e quantas ficaram no rascunho, quem se inscreve (natureza, cotas, território) e
 * como o perfil de quem se inscreve se compara ao de quem se cadastra.
 *
 * @phpstan-import-type Fatia from ConsultasDoPainel
 * @phpstan-import-type Distribuicao from ConsultasDoPainel
 */
final class Inscricoes
{
    /**
     * @return array{
     *     enviadas: int,
     *     rascunhos: int,
     *     reabertas: int,
     *     conclusao: float,
     *     natureza: Distribuicao,
     *     cotas: list<Fatia>,
     *     comparacao: list<array{valor: string, rotulo: string, cadastro: float, inscricao: float}>,
     *     territorios: list<array{codigo: string, rotulo: string, total: int, percentual: float}>,
     *     documentos: Distribuicao,
     * }
     */
    public static function dados(ConsultasDoPainel $consultas): array
    {
        $enviadas = $consultas->enviadas();
        $totalEnviadas = (clone $enviadas)->count();
        $rascunhos = $consultas->rascunhos()->count();
        $porTerritorio = $consultas->totaisPor($enviadas, 'pessoas_fisicas.territorio');
        $territorios = ConsultasDoPainel::territorios();

        return [
            'enviadas' => $totalEnviadas,
            'rascunhos' => $rascunhos,
            // Reaberta: já teve número (foi enviada) e voltou ao rascunho para correção.
            'reabertas' => $consultas->rascunhos()->whereNotNull('inscricoes.numero')->count(),
            'conclusao' => ConsultasDoPainel::percentual($totalEnviadas, $totalEnviadas + $rascunhos),
            'natureza' => $consultas->distribuicao($enviadas, 'inscricoes.natureza', NaturezaProponente::class),
            'cotas' => array_map(function (ModalidadeCota $modalidade) use ($enviadas, $totalEnviadas): array {
                $total = (clone $enviadas)->where('inscricoes.cotas', 'like', ConsultasDoPainel::naLista($modalidade->value))->count();

                return [
                    'valor' => $modalidade->value,
                    'rotulo' => $modalidade->rotulo(),
                    'total' => $total,
                    'percentual' => ConsultasDoPainel::percentual($total, $totalEnviadas),
                ];
            }, ModalidadeCota::cases()),
            'comparacao' => self::comparacao($consultas),
            'territorios' => array_map(
                fn (string $codigo, string $rotulo): array => [
                    'codigo' => $codigo,
                    'rotulo' => $rotulo,
                    'total' => $porTerritorio[$codigo] ?? 0,
                    'percentual' => ConsultasDoPainel::percentual($porTerritorio[$codigo] ?? 0, $totalEnviadas),
                ],
                array_keys($territorios),
                array_values($territorios),
            ),
            'documentos' => $consultas->distribuicao(
                InscricaoDocumento::query()->whereIn('inscricao_id', (clone $enviadas)->select('inscricoes.id'))->toBase(),
                'inscricao_documentos.situacao',
                SituacaoConferencia::class,
            ),
        ];
    }

    /**
     * Percentual de cada recorte do perfil entre as pessoas cadastradas e entre as pessoas que enviaram inscrição
     * (cada pessoa conta uma vez, mesmo com várias inscrições). A diferença mostra quem fica pelo caminho.
     *
     * @return list<array{valor: string, rotulo: string, cadastro: float, inscricao: float}>
     */
    private static function comparacao(ConsultasDoPainel $consultas): array
    {
        $pessoas = $consultas->pessoas();
        $enviadas = $consultas->enviadas();
        $totalPessoas = (clone $pessoas)->count();
        $totalInscritas = (clone $enviadas)->distinct()->count('inscricoes.user_id');

        $comparacao = [];
        foreach (ConsultasDoPainel::recortesDoPerfil() as $valor => $recorte) {
            $comparacao[] = [
                'valor' => $valor,
                'rotulo' => $recorte['rotulo'],
                'cadastro' => ConsultasDoPainel::percentual(($recorte['condicao'])(clone $pessoas)->count(), $totalPessoas),
                'inscricao' => ConsultasDoPainel::percentual(
                    ($recorte['condicao'])(clone $enviadas)->distinct()->count('inscricoes.user_id'),
                    $totalInscritas,
                ),
            ];
        }

        return $comparacao;
    }
}
