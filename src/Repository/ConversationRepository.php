<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Empire;
use App\Enum\Messaging\MessageCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
final class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Places d'un empire dans ses conversations visibles (boîte ou archives), dernier message d'abord.
     *
     * @return list<ConversationParticipant>
     */
    public function findPlacesOf(Empire $empire, bool $archived = false, int $limit = 100, ?MessageCategory $category = null, bool $playersOnly = false): array
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('p', 'c')
            ->from(ConversationParticipant::class, 'p')
            ->join('p.conversation', 'c')
            ->andWhere('p.empire = :empire')
            ->andWhere('p.archived = :archived')
            ->andWhere('p.clearedAt IS NULL OR c.lastMessageAt > p.clearedAt')
            ->setParameter('empire', $empire)
            ->setParameter('archived', $archived)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $category) {
            $query->andWhere('c.category = :category')->setParameter('category', $category);
        } elseif ($playersOnly) {
            $query->andWhere('c.system = false');
        }
        /** @var list<ConversationParticipant> $places */
        $places = $query->getQuery()->getResult();

        return $places;
    }

    /** Conversations non lues d'un empire (compteur de la navigation) */
    public function countUnread(Empire $empire): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ConversationParticipant::class, 'p')
            ->join('p.conversation', 'c')
            ->andWhere('p.empire = :empire')
            ->andWhere('p.leftAt IS NULL')
            ->andWhere('p.clearedAt IS NULL OR c.lastMessageAt > p.clearedAt')
            ->andWhere('(p.lastReadAt IS NULL AND c.lastMessageAt >= p.joinedAt) OR c.lastMessageAt > p.lastReadAt')
            ->setParameter('empire', $empire)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Conversation à deux déjà ouverte entre deux empires (tous deux encore présents), s'il y en a une */
    public function findDirectBetween(Empire $a, Empire $b): ?Conversation
    {
        /** @var Conversation|null */
        return $this->createQueryBuilder('c')
            ->join('c.participants', 'pa', 'WITH', 'pa.empire = :a AND pa.leftAt IS NULL')
            ->join('c.participants', 'pb', 'WITH', 'pb.empire = :b AND pb.leftAt IS NULL')
            ->andWhere('c.group = false')
            ->andWhere('c.system = false')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
