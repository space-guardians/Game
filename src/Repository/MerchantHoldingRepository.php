<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Empire;
use App\Entity\Merchant;
use App\Entity\MerchantHolding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MerchantHolding>
 */
final class MerchantHoldingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MerchantHolding::class);
    }

    /** Ressources gardées pour cet empire chez ce marchand (créées vides au besoin, à enregistrer par l'appelant) */
    public function forEmpire(Merchant $merchant, Empire $empire): MerchantHolding
    {
        $holding = $this->findOneBy(['merchant' => $merchant, 'empire' => $empire]);
        if (null === $holding) {
            $holding = new MerchantHolding($merchant, $empire);
            $this->getEntityManager()->persist($holding);
        }

        return $holding;
    }
}
