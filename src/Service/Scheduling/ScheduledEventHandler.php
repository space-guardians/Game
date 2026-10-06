<?php

declare(strict_types=1);

namespace App\Service\Scheduling;

use App\Entity\ScheduledEvent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Résout un type d'événement planifié (fin de construction, arrivée de flotte…). Le résolveur l'appelle sous
 * verrou et dans une transaction : le gestionnaire modifie les entités sans flush. Une exception annule ses
 * modifications et marque l'événement en échec.
 */
#[AutoconfigureTag(self::TAG)]
interface ScheduledEventHandler
{
    public const string TAG = 'app.scheduled_event_handler';

    /** Type traité, enregistré dans ScheduledEvent::$type (ex. « building.completed ») */
    public static function type(): string;

    public function handle(ScheduledEvent $event): void;
}
