<?php

declare(strict_types=1);

namespace App\Service\FairPlay;

/**
 * Protections d'un joueur (§4.13), pur, valeurs provisoires. Bouclier débutant : tant que l'empire a moins de
 * NEWBIE_DAYS jours et un score sous NEWBIE_SCORE, ses planètes ne peuvent être ni attaquées ni espionnées. Mode
 * vacances : production réduite à VACATION_PRODUCTION, mêmes protections, au moins VACATION_MIN_HOURS heures.
 */
final class ProtectionRules
{
    public const int NEWBIE_DAYS = 7;

    public const int NEWBIE_SCORE = 5000;

    public const int VACATION_MIN_HOURS = 48;

    public const float VACATION_PRODUCTION = 0.25;

    public function newbieShieldEnds(\DateTimeImmutable $foundedAt): \DateTimeImmutable
    {
        return $foundedAt->modify(\sprintf('+%d days', self::NEWBIE_DAYS));
    }

    public function isNewbie(\DateTimeImmutable $foundedAt, int $score, \DateTimeImmutable $now): bool
    {
        return $now < $this->newbieShieldEnds($foundedAt) && $score < self::NEWBIE_SCORE;
    }

    public function vacationEndsEarliest(\DateTimeImmutable $since): \DateTimeImmutable
    {
        return $since->modify(\sprintf('+%d hours', self::VACATION_MIN_HOURS));
    }
}
