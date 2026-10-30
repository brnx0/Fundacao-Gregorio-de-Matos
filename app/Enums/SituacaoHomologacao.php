<?php

namespace App\Enums;

/** Resultado da homologação de uma inscrição finalizada. */
enum SituacaoHomologacao: string
{
    case Homologada = 'homologada';
    case NaoHomologada = 'nao_homologada';
    case EmRecurso = 'em_recurso';
    case RecursoNegado = 'recurso_negado';

    /** Rótulo da retaguarda. */
    public function rotulo(): string
    {
        return match ($this) {
            self::Homologada => 'Homologada',
            self::NaoHomologada => 'Não homologada',
            self::EmRecurso => 'Em recurso',
            self::RecursoNegado => 'Recurso negado',
        };
    }

    /** Rótulo mostrado ao proponente no portal. */
    public function rotuloNoPortal(): string
    {
        return match ($this) {
            self::Homologada => 'Homologada',
            self::NaoHomologada => 'Não homologada',
            self::EmRecurso => 'Recurso em análise',
            self::RecursoNegado => 'Não homologada (recurso negado)',
        };
    }
}
