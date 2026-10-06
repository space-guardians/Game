<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Enum\Fleet\FleetAction;

/**
 * Étape d'une mission demandée par le joueur : se rendre à une position, puis y effectuer une action (§4.6).
 */
final readonly class MissionStep
{
    public function __construct(
        public SpacePosition $destination,
        public FleetAction $action,
        /** Libellé de la destination (« 1:42:7 », « système 1:42 ») */
        public string $label,
        /** Flotte à ravitailler (action « Ravitaillement ») */
        public ?int $targetFleetId = null,
    ) {}
}
