<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Enum\Combat\CombatSide;
use App\Model\Economy\Resources;
use App\Repository\CombatReportRepository;
use App\Service\Economy\PlanetResources;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pillage (§4.7) : après une bataille gagnée sur une planète d'un empire non allié, les assaillants survivants
 * repartent avec des ressources de la planète, dans la limite de leur cargo restant (PillageRules). Le butin est noté
 * dans le rapport de combat. Appelé sous le verrou de la planète (événement de la bataille).
 */
final readonly class Pillage
{
    public function __construct(
        private PlanetResources $resources,
        private PillageRules $rules,
        private Allegiances $allegiances,
        private CombatReportRepository $reports,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param list<Fleet> $attackers flottes assaillantes encore debout après le combat
     */
    public function plunder(Battle $battle, Empire $initiator, array $attackers, \DateTimeImmutable $at): Resources
    {
        $planetId = $battle->getLocation()->planetId;
        $planet = null === $planetId ? null : $this->entityManager->find(Planet::class, $planetId);
        // Planète d'un empire ou d'une alliance non alliés à l'assaillant, et pas gelée par une mise en vente (§4.15)
        if (CombatSide::Attacker !== $battle->getWinner() || null === $planet || !$planet->isClaimed() || $planet->isFrozen() || $this->allegiances->planetAllied($planet, $initiator)) {
            return new Resources();
        }

        $capacities = [];
        foreach ($attackers as $fleet) {
            if (!$fleet->isEmpty()) {
                $capacities[(int) $fleet->getId()] = $fleet->freeCargo();
            }
        }
        // Stock consolidé à l'heure du combat, puis butin
        $stock = $this->resources->settle($planet, $at)->amounts;
        // Civilisation non-joueuse : part de son profil (§4.14)
        $profile = $planet->getOwner()?->getNpcProfile();
        $ratio = null === $profile ? PillageRules::PLUNDER_RATIO : $profile->getLootPercent() / 100;
        $shares = $this->rules->share($this->rules->loot($stock, array_sum($capacities), $ratio), $capacities);

        $taken = new Resources();
        foreach ($attackers as $fleet) {
            $share = $shares[(int) $fleet->getId()] ?? null;
            if (null !== $share && $share->metal + $share->crystal + $share->deuterium > 0) {
                $fleet->load($share);
                $taken = $taken->plus($share);
            }
        }
        $planet->storeResources($stock->minus($taken), $at);
        $this->reports->findOneBy(['battle' => $battle])?->recordPlunder($taken);

        return $taken;
    }
}
