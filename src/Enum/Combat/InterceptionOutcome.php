<?php

declare(strict_types=1);

namespace App\Enum\Combat;

/** Issue de l'entrée d'une flotte dans un système gardé (§4.6.2) */
enum InterceptionOutcome
{
    /** Aucune flotte hostile ne garde le système : la flotte poursuit */
    case None;
    /** Gardes du système anéantis : la flotte poursuit vers sa destination */
    case Broken;
    /** Interceptée : la flotte est arrêtée au niveau du système, son ordre échoue */
    case Stopped;
    /** Anéantie par les gardes */
    case Destroyed;
}
