<?php

declare(strict_types=1);

namespace App\Exception\Fleet;

/**
 * Flotte impossible à constituer : aucun vaisseau choisi, plus de vaisseaux que l'inventaire n'en compte, nom invalide.
 */
final class InvalidFleetComposition extends \DomainException {}
