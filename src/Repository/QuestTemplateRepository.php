<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\QuestTemplate;
use App\Enum\Exploration\QuestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuestTemplate>
 */
final class QuestTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuestTemplate::class);
    }

    public function findOneByCode(string $code): ?QuestTemplate
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Quêtes publiées pouvant apparaître d'elles-mêmes en exploration (probabilité non nulle), dans un ordre stable
     * pour le tirage.
     *
     * @return list<QuestTemplate>
     */
    public function findSpontaneous(): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.status = :published')
            ->setParameter('published', QuestStatus::Published)
            ->andWhere('q.chance > 0')
            ->orderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
