<?php

namespace App\Enums;

/** Andamento do convite por e-mail enviado ao avaliador de um edital. */
enum SituacaoConvite: string
{
    case NaFila = 'na_fila';
    case Enviado = 'enviado';
    case Falhou = 'falhou';

    public function rotulo(): string
    {
        return match ($this) {
            self::NaFila => 'Na fila',
            self::Enviado => 'Enviado',
            self::Falhou => 'Falhou',
        };
    }
}
