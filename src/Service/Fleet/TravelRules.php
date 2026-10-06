<?php

declare(strict_types=1);

namespace App\Service\Fleet;

/**
 * Vitesse et durée d'un trajet (§4.6.1), sans accès aux données : vitesse du vaisseau le plus lent, améliorée par
 * sa technologie de propulsion, pourcentage de vitesse choisi par le joueur, vitesse d'univers.
 */
final readonly class TravelRules
{
    /** Gain de vitesse par niveau de la technologie de propulsion d'un vaisseau (+10 %, +20 %, +30 % par niveau) */
    public const array DRIVE_BONUS_PER_LEVEL = [
        'combustion_drive' => 0.1,
        'impulse_drive' => 0.2,
        'hyperspace_drive' => 0.3,
    ];

    /** Pourcentages de vitesse proposés au joueur */
    public const array SPEED_PERCENTS = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    /** Durée fixe ajoutée à tout trajet (décollage, manœuvres), en secondes */
    private const float BASE_SECONDS = 10.0;
    private const float DURATION_FACTOR = 35_000.0;

    /** Vitesse d'un type de vaisseau : vitesse de base × (1 + gain par niveau × niveau de sa propulsion) */
    public function shipSpeed(int $baseSpeed, ?string $driveCode, int $driveLevel): float
    {
        return $baseSpeed * (1 + (self::DRIVE_BONUS_PER_LEVEL[$driveCode ?? ''] ?? 0.0) * max(0, $driveLevel));
    }

    /**
     * Durée du trajet en secondes (au moins une) : (35 000 / % × √(10 × distance / vitesse) + 10) / vitesse d'univers,
     * calculée sur la distance totale ; la flotte parcourt ensuite ses segments à vitesse constante.
     */
    public function durationSeconds(float $distance, float $fleetSpeed, int $speedPercent, float $universeSpeed): int
    {
        if (!\in_array($speedPercent, self::SPEED_PERCENTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Pourcentage de vitesse invalide : %d.', $speedPercent));
        }
        if ($fleetSpeed <= 0.0) {
            throw new \InvalidArgumentException('Une flotte immobile ne peut pas se déplacer.');
        }

        $seconds = (self::DURATION_FACTOR / $speedPercent * sqrt(10 * max(0.0, $distance) / $fleetSpeed) + self::BASE_SECONDS) / $universeSpeed;

        return max(1, (int) ceil($seconds));
    }

    /** Fraction du trajet parcourue à l'instant donné (0 avant le départ, 1 à l'arrivée et après) */
    public function progress(\DateTimeImmutable $departedAt, \DateTimeImmutable $arrivesAt, \DateTimeImmutable $at): float
    {
        $total = (float) $arrivesAt->format('U.u') - (float) $departedAt->format('U.u');
        if ($total <= 0.0) {
            return 1.0;
        }

        return max(0.0, min(1.0, ((float) $at->format('U.u') - (float) $departedAt->format('U.u')) / $total));
    }
}
