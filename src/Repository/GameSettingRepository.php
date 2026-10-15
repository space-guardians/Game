<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GameSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSetting>
 */
final class GameSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSetting::class);
    }

    /** Valeur d'un paramètre, ou la valeur donnée s'il n'est pas (encore) en base */
    public function valueOf(string $code, float $default): float
    {
        return $this->findOneBy(['code' => $code])?->getValue() ?? $default;
    }
}
