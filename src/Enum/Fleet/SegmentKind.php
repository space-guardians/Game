<?php

declare(strict_types=1);

namespace App\Enum\Fleet;

/**
 * Nature d'un segment de trajectoire (§4.6.1).
 */
enum SegmentKind: string
{
    /** De la planète (ou du point) de départ jusqu'à la lisière du système d'origine */
    case Exit = 'exit';
    /** Entre deux systèmes, ou vers / depuis l'espace hors système */
    case Interstellar = 'interstellar';
    /** De la lisière du système cible jusqu'à la planète (ou au point) visé */
    case Approach = 'approach';
    /** Trajet entièrement à l'intérieur d'un même système */
    case Local = 'local';

    public function label(): string
    {
        return match ($this) {
            self::Exit => 'Sortie du système',
            self::Interstellar => 'Transit interstellaire',
            self::Approach => 'Approche',
            self::Local => 'Trajet local',
        };
    }
}
