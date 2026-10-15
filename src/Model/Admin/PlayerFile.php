<?php

declare(strict_types=1);

namespace App\Model\Admin;

use App\Entity\ActivityLog;
use App\Entity\Empire;
use App\Entity\ScheduledEvent;
use App\Entity\User;
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
     * @param list<ActivityLog>                                  $activity connexions et actions sensibles (§4.13)
     * @param list<User>                                         $sharedAddress autres comptes vus depuis les mêmes adresses
     */
    public function __construct(
        public Empire $empire,
        public array $planets,
        public array $actions,
        public array $events,
        public array $activity = [],
        public array $sharedAddress = [],
    ) {}
}
