<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\Galaxy;
use App\Exception\Universe\GalaxyNumberTaken;
use App\Model\Universe\CreatedGalaxy;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère une galaxie et l'ajoute à l'univers, sans toucher aux galaxies existantes (§2.1). Partagé par la
 * commande app:galaxy:generate et la génération depuis le panneau d'administration (§5.5).
 */
final readonly class GalaxyCreator
{
    public function __construct(
        private GalaxyGenerator $generator,
        private GalaxyRepository $galaxies,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param int|null    $number numéro de la galaxie, par défaut le suivant
     * @param string|null $name   nom, par défaut « Galaxie <numéro> »
     *
     * @throws GalaxyNumberTaken
     * @throws \InvalidArgumentException paramètres de génération invalides
     */
    public function create(?int $number, ?string $name, int $seed, SpiralGalaxyShape $shape, int $systems): CreatedGalaxy
    {
        $number ??= $this->galaxies->nextNumber();
        if ($this->galaxies->numberExists($number)) {
            throw new GalaxyNumberTaken($number);
        }

        $galaxy = $this->generator->generate(
            $number,
            $name ?? \sprintf('Galaxie %d', $number),
            $seed,
            $shape,
            $systems,
            SystemPlacer::DEFAULT_MIN_DISTANCE,
        );

        return new CreatedGalaxy($galaxy, $galaxy->getSystems()->count(), $this->persist($galaxy), $this->radius($galaxy));
    }

    /** @return int nombre de planètes enregistrées */
    private function persist(Galaxy $galaxy): int
    {
        $planets = 0;
        $this->entityManager->persist($galaxy);
        foreach ($galaxy->getSystems() as $system) {
            $this->entityManager->persist($system);
            foreach ($system->getPlanets() as $planet) {
                $this->entityManager->persist($planet);
                ++$planets;
            }
        }
        $this->entityManager->flush();

        return $planets;
    }

    private function radius(Galaxy $galaxy): float
    {
        $radius = 0.0;
        foreach ($galaxy->getSystems() as $system) {
            $radius = max($radius, $system->getPosition()->distanceFromCenter());
        }

        return $radius;
    }
}
