<?php

namespace App\Enums;

/** Como as inscrições homologadas são repartidas entre os avaliadores do edital. */
enum ModoDistribuicao: string
{
    case Igual = 'igual';
    case PorCategoria = 'por_categoria';

    public function rotulo(): string
    {
        return match ($this) {
            self::Igual => 'Igual entre os avaliadores',
            self::PorCategoria => 'Por categoria',
        };
    }
}
