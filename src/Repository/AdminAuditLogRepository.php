<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminAuditLog>
 */
final class AdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /**
     * Historique d'un objet, du plus ancien au plus récent.
     *
     * @return list<AdminAuditLog>
     */
    public function findBySubject(string $subjectType, string $subjectId): array
    {
        /** @var list<AdminAuditLog> */
        return $this->findBy(['subjectType' => $subjectType, 'subjectId' => $subjectId], ['id' => 'ASC']);
    }
}
