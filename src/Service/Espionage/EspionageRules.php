<?php

declare(strict_types=1);

namespace App\Service\Espionage;

/**
 * Niveau de détail et risque de détection de l'espionnage (§4.9), sans état ni base. Valeurs provisoires avant
 * l'équilibrage (§7) :
 * - score = niveau d'espionnage de l'espion − niveau de la cible + (sondes envoyées − 1) ; chaque section se révèle à
 *   partir d'un score : ressources toujours, flottes dès 0, défenses dès 1, bâtiments dès 2, recherches dès 3 ;
 * - chance de détection = 2 % par sonde, doublée par niveau d'espionnage d'avance de la cible (divisée par deux par
 *   niveau de retard), plafonnée à 100 %.
 */
final readonly class EspionageRules
{
    public const string TECHNOLOGY = 'espionage';

    public const string RESOURCES = 'resources';

    public const string FLEETS = 'fleets';

    public const string DEFENSES = 'defenses';

    public const string BUILDINGS = 'buildings';

    public const string RESEARCH = 'research';

    /** Score minimal révélant chaque section */
    public const array THRESHOLDS = [
        self::RESOURCES => \PHP_INT_MIN,
        self::FLEETS => 0,
        self::DEFENSES => 1,
        self::BUILDINGS => 2,
        self::RESEARCH => 3,
    ];

    /** Chance de détection par sonde, à niveaux d'espionnage égaux */
    public const float DETECTION_PER_PROBE = 0.02;

    /** Probabilité (0 à 1) que les sondes soient repérées par la cible */
    public function detectionChance(int $spyLevel, int $targetLevel, int $probes): float
    {
        return min(1.0, max(0, $probes) * self::DETECTION_PER_PROBE * 2 ** ($targetLevel - $spyLevel));
    }

    public function score(int $spyLevel, int $targetLevel, int $probes): int
    {
        return $spyLevel - $targetLevel + max(0, $probes - 1);
    }

    /** @return list<string> sections révélées, dans l'ordre de THRESHOLDS */
    public function revealed(int $score): array
    {
        return array_keys(array_filter(self::THRESHOLDS, static fn(int $threshold): bool => $score >= $threshold));
    }
}
