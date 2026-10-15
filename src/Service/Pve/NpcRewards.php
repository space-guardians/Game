<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Enum\Messaging\MessageCategory;
use App\Service\Economy\PlanetResources;
use App\Service\Messaging\GameReports;

/**
 * Récompense PvE (§4.14) : vaincre une civilisation non-joueuse sur sa planète rapporte, en plus du pillage, la
 * récompense de victoire de son profil, versée sur la planète mère de l'empire qui a lancé l'attaque, avec un rapport.
 */
final readonly class NpcRewards
{
    public function __construct(
        private PlanetResources $resources,
        private GameReports $reports,
    ) {}

    public function victory(Planet $planet, Empire $victor, \DateTimeImmutable $at): void
    {
        $owner = $planet->getOwner();
        $reward = $owner?->getNpcProfile()?->getVictoryReward();
        if (null === $owner || null === $reward || $reward->isZero() || $victor->isNpc()) {
            return;
        }
        $home = $victor->getHomePlanet();
        $stock = $this->resources->settle($home, $at)->amounts;
        $home->storeResources($stock->plus($reward), $at);
        $this->reports->report(
            $victor,
            MessageCategory::Combat,
            \sprintf('Victoire sur %s', $owner->getName()),
            \sprintf('La civilisation « %s » est vaincue sur %s : %s métal, %s cristal et %s deutérium vous sont versés sur %s.', $owner->getName(), $planet, number_format($reward->metal, 0, ',', ' '), number_format($reward->crystal, 0, ',', ' '), number_format($reward->deuterium, 0, ',', ' '), $home),
        );
    }
}
