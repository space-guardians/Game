<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Alliance;
use App\Entity\AllianceInvitation;
use App\Entity\Empire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alliance>
 */
final class AllianceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alliance::class);
    }

    /** Nom ou tag déjà pris, sans tenir compte de la casse */
    public function isTaken(string $name, string $tag): bool
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('LOWER(a.name) = LOWER(:name) OR LOWER(a.tag) = LOWER(:tag)')
            ->setParameter('name', $name)
            ->setParameter('tag', $tag)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<AllianceInvitation> invitations reçues par un empire, les plus récentes d'abord */
    public function findInvitationsFor(Empire $empire): array
    {
        /** @var list<AllianceInvitation> */
        return $this->getEntityManager()->getRepository(AllianceInvitation::class)->findBy(['empire' => $empire], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /** @return list<AllianceInvitation> invitations en attente d'une alliance */
    public function findInvitationsOf(Alliance $alliance): array
    {
        /** @var list<AllianceInvitation> */
        return $this->getEntityManager()->getRepository(AllianceInvitation::class)->findBy(['alliance' => $alliance], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
