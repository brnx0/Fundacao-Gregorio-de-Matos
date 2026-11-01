<?php

namespace App\Support\Avaliacao;

/** O que uma rodada da distribuição faz: quem recebe cada inscrição e o que fica sem avaliador. */
final readonly class PlanoDeDistribuicao
{
    /**
     * @param  array<int, int>  $atribuicoes  Id da inscrição → id do avaliador.
     * @param  array<int, int>  $porAvaliador  Id do avaliador → quantas inscrições ele recebe nesta rodada.
     * @param  array<string, int>  $semAvaliador  Nome da categoria (ou "Sem categoria") → quantas ficaram sem avaliador.
     */
    public function __construct(
        public array $atribuicoes,
        public array $porAvaliador,
        public array $semAvaliador,
    ) {}

    public function vazio(): bool
    {
        return $this->atribuicoes === [];
    }
}
