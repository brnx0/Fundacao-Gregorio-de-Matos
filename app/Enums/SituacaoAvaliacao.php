<?php

namespace App\Enums;

/** Andamento da avaliação de uma inscrição pelo avaliador. */
enum SituacaoAvaliacao: string
{
    case NaoIniciada = 'nao_iniciada';
    case EmAndamento = 'em_andamento';
    case Concluida = 'concluida';

    public function rotulo(): string
    {
        return match ($this) {
            self::NaoIniciada => 'Não iniciada',
            self::EmAndamento => 'Em andamento',
            self::Concluida => 'Concluída',
        };
    }
}
