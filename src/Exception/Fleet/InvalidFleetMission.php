<?php

declare(strict_types=1);

namespace App\Exception\Fleet;

/**
 * Mission refusée : flotte déjà en vol, carnet vide ou trop long, action impossible sur la destination, cargaison
 * trop lourde ou indisponible.
 */
final class InvalidFleetMission extends \DomainException {}
