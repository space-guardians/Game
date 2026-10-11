<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\EspionageReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EspionageReport>
 */
final class EspionageReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EspionageReport::class);
    }

    /** @return list<EspionageReport> rapports d'un empire, les plus récents d'abord */
    public function findForEmpire(Empire $empire, int $limit = 50): array
    {
        return $this->findBy(['empire' => $empire], ['spiedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
