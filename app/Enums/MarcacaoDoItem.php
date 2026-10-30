<?php

namespace App\Enums;

/** Marcação do gestor sobre um documento ou resposta na conferência da inscrição. */
enum MarcacaoDoItem: string
{
    case Ok = 'ok';
    case Problema = 'problema';

    public function rotulo(): string
    {
        return match ($this) {
            self::Ok => 'Ok',
            self::Problema => 'Com problema',
        };
    }
}
