<?php

namespace App\Enums;

/** Decisão do gestor sobre o recurso de uma inscrição não homologada. */
enum DecisaoRecurso: string
{
    case Aceito = 'aceito';
    case Negado = 'negado';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aceito => 'Aceito',
            self::Negado => 'Negado',
        };
    }
}
