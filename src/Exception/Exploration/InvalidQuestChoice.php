<?php

declare(strict_types=1);

namespace App\Exception\Exploration;

/** Choix refusé : événement déjà résolu ou expiré, issue étrangère à l'événement, flotte partie */
final class InvalidQuestChoice extends \DomainException {}
