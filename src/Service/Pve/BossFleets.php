<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\Fleet;
use App\Enum\Account\EmpireKind;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Pve\NpcCategory;
use App\Repository\FleetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Ranking\RankingRules;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Recalcul périodique de la flotte des civilisations « boss » (§4.14) : quand l'intervalle de son profil est écoulé,
 * la flotte stationnée sur sa planète mère est ramenée à la composition qui vaut la flotte militaire moyenne du top 10
 * des joueurs (§4.11) multipliée par la force du profil — renforcée ou réduite, créée au besoin. Une flotte absente de
 * sa planète (au combat) attend le prochain passage.
 */
final readonly class BossFleets
{
    public const int TOP = 10;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetRepository $fleets,
        private ShipTypeRepository $shipTypes,
        private BossFleetRules $rules,
        private RankingRules $ranking,
        private ClockInterface $clock,
    ) {}

    /** Recalcule les flottes dues ; renvoie le nombre de civilisations recalculées */
    public function recalculateDue(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach ($this->bosses() as $boss) {
            $profile = $boss->getNpcProfile();
            \assert(null !== $profile);
            $last = $boss->getNpcFleetRecalculatedAt();
            if (null !== $last && $last->modify(\sprintf('+%d hours', $profile->getFleetRecalcHours())) > $now) {
                continue;
            }
            if (null !== $this->recalculate($boss)) {
                ++$count;
            }
        }
        $this->entityManager->flush();

        return $count;
    }

    /**
     * Ramène la flotte du boss à sa composition cible ; null si sa flotte n'est pas chez elle.
     *
     * @return array<string, int>|null
     */
    public function recalculate(Empire $boss): ?array
    {
        $profile = $boss->getNpcProfile();
        if (!$boss->isNpc() || null === $profile || NpcCategory::Boss !== $profile->getCategory()) {
            throw new \DomainException('Seule une civilisation « boss » a une flotte recalculée.');
        }
        $fleet = $this->homeFleet($boss);
        if (null === $fleet) {
            return null;
        }
        $types = [];
        $unitPoints = [];
        $base = $profile->getFleetComposition() ?: BossFleetRules::BASE;
        foreach (array_keys($base) as $code) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \LogicException(\sprintf('Type de vaisseau « %s » introuvable.', $code));
            $types[$code] = $type;
            $unitPoints[$code] = $this->ranking->unitsCost($type->getCost(), 1) / RankingRules::RESOURCES_PER_POINT;
        }
        $composition = $this->rules->composition($base, $unitPoints, $this->rules->target($this->topMilitary(), $profile->getFleetStrengthPercent()));
        $current = $fleet->shipCounts();
        foreach ($composition as $code => $quantity) {
            $delta = $quantity - ($current[$code] ?? 0);
            if ($delta > 0) {
                $fleet->addShips($types[$code], $delta);
            } elseif ($delta < 0) {
                $fleet->removeShips($types[$code], -$delta);
            }
        }
        $fleet->refuel(max(0.0, $fleet->tankCapacity() - $fleet->getFuel()));
        $boss->npcFleetRecalculated($this->clock->now());

        return $composition;
    }

    /** @return list<int> points militaires des meilleurs joueurs (civilisations exclues) */
    private function topMilitary(): array
    {
        return array_map('intval', $this->entityManager->createQueryBuilder()
            ->select('s.military')
            ->from(EmpireScore::class, 's')
            ->join('s.empire', 'e')
            ->where('e.kind = :player')
            ->setParameter('player', EmpireKind::Player)
            ->orderBy('s.military', 'DESC')
            ->setMaxResults(self::TOP)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /** @return list<Empire> */
    private function bosses(): array
    {
        /** @var list<Empire> */
        return $this->entityManager->createQueryBuilder()
            ->select('e', 'profile')
            ->from(Empire::class, 'e')
            ->join('e.npcProfile', 'profile')
            ->where('e.kind = :npc')
            ->andWhere('profile.category = :boss')
            ->setParameter('npc', EmpireKind::Npc)
            ->setParameter('boss', NpcCategory::Boss)
            ->getQuery()
            ->getResult();
    }

    /** Flotte stationnée sur la planète mère, créée si le boss n'en a plus aucune */
    private function homeFleet(Empire $boss): ?Fleet
    {
        $fleets = $this->fleets->findBy(['empire' => $boss], ['id' => 'ASC']);
        foreach ($fleets as $fleet) {
            if (FleetStatus::Stationed === $fleet->getStatus() && $fleet->isAtHome()) {
                return $fleet;
            }
        }
        if ([] !== $fleets) {
            return null;
        }
        $fleet = new Fleet($boss, \sprintf('Flotte de %s', $boss->getName()), $boss->getHomePlanet(), $this->clock->now());
        $this->entityManager->persist($fleet);

        return $fleet;
    }
}
