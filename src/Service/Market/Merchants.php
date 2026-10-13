<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Galaxy;
use App\Entity\Merchant;
use App\Entity\StarSystem;
use App\Repository\MerchantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Marchands d'une galaxie (§4.15) : placés une fois pour toutes à sa génération (`GalaxyCreator`), ou après coup pour
 * une galaxie qui n'en a pas encore (`app:merchant:place`), selon `MerchantRules`.
 */
final readonly class Merchants
{
    /** Enseignes, attribuées à tour de rôle */
    private const array SIGNS = ['Comptoir', 'Relais marchand', 'Bazar orbital', 'Entrepôt franc'];

    public function __construct(
        private MerchantRules $rules,
        private MerchantRepository $merchants,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Place les marchands d'une galaxie qui n'en a pas ; l'appelant enregistre (flush).
     *
     * @return list<Merchant>
     *
     * @throws \LogicException si la galaxie a déjà ses marchands
     */
    public function place(Galaxy $galaxy, Randomizer $randomizer): array
    {
        if (null !== $galaxy->getId() && $this->merchants->countInGalaxy($galaxy) > 0) {
            throw new \LogicException(\sprintf('La galaxie %d a déjà ses marchands.', $galaxy->getNumber()));
        }
        /** @var list<StarSystem> $systems */
        $systems = array_values($galaxy->getSystems()->toArray());
        $chosen = $this->rules->pick(
            array_map(static fn(StarSystem $system) => $system->getPosition(), $systems),
            $this->rules->count(\count($systems)),
            $randomizer,
        );
        $placed = [];
        foreach ($chosen as $rank => $index) {
            $system = $systems[$index];
            [$x, $y] = $this->rules->station($randomizer);
            $merchant = new Merchant($system, \sprintf('%s %d-%d', self::SIGNS[$rank % \count(self::SIGNS)], $galaxy->getNumber(), $system->getNumber()), $x, $y);
            $this->entityManager->persist($merchant);
            $placed[] = $merchant;
        }

        return $placed;
    }
}
