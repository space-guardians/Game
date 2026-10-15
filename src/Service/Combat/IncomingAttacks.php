<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Planet;
use App\Enum\Combat\BattleStatus;
use App\Model\Combat\IncomingAttack;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Attaques entrantes d'un empire (§4.12) : batailles encore programmées sur ses planètes, lancées par un empire non
 * allié, de la plus proche à la plus lointaine. Alimente le bandeau d'alerte et son compte à rebours.
 */
final readonly class IncomingAttacks
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Allegiances $allegiances,
    ) {}

    /** @return list<IncomingAttack> */
    public function of(Empire $empire): array
    {
        /** @var list<Battle> $battles */
        $battles = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Battle::class, 'b')
            ->join(Planet::class, 'p', 'WITH', 'p.id = b.location.planetId')
            ->where('b.status = :scheduled')
            ->andWhere('p.owner = :empire')
            ->setParameter('scheduled', BattleStatus::Scheduled)
            ->setParameter('empire', $empire)
            ->orderBy('b.resolvesAt', 'ASC')
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        $attacks = [];
        foreach ($battles as $battle) {
            $attacker = $battle->getInitiator();
            $planet = $this->entityManager->find(Planet::class, $battle->getLocation()->planetId);
            if (null === $attacker || null === $planet || $this->allegiances->allied($attacker, $empire)) {
                continue;
            }
            $attacks[] = new IncomingAttack((int) $battle->getId(), $planet, $attacker, $battle->getScheduledAt(), $battle->getResolvesAt());
        }

        return $attacks;
    }
}
