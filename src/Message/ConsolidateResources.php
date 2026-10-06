<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Consolidation périodique légère des stocks (§4.2) : enregistre la production des planètes qui n'ont pas été
 * touchées depuis longtemps, pour que les données en base (classements, panneau) restent proches du réel.
 */
final readonly class ConsolidateResources {}
