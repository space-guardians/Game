<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Entity\Empire;
use App\Entity\ScheduledEvent;
use DH\Auditor\Model\Entry;

/**
 * Fiche d'un joueur dans le panneau (§5.6.1) : empire, planètes, journal d'activité. Recherches, flottes et
 * alliance s'y ajouteront avec leurs phases.
 */
final readonly class PlayerFile
{
    /**
     * @param list<PlayerPlanet>                                 $planets
     * @param list<array{entity: AuditedEntity, entry: Entry}> $actions modifications faites par le joueur
     * @param list<ScheduledEvent>                               $events  événements de jeu de ses planètes
     */
    public function __construct(
        public Empire $empire,
        public array $planets,
        public array $actions,
        public array $events,
    ) {}
}
