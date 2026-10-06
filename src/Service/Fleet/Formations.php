<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\Formation;
use App\Entity\ShipType;
use App\Exception\Fleet\InvalidFormation;
use App\Model\Fleet\FormationCell;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Formation des flottes (§4.7) : formation par défaut à la constitution, réglage par le joueur tant que la flotte est
 * stationnée (elle se fixe à l'envoi).
 */
final readonly class Formations
{
    public function __construct(
        private FormationRules $rules,
        private EntityManagerInterface $entityManager,
    ) {}

    /** Formation de la flotte ; créée par défaut si elle n'en a pas encore (flottes antérieures aux formations) */
    public function of(Fleet $fleet): Formation
    {
        $formation = $fleet->getFormation();
        if (null === $formation) {
            $formation = $this->createDefault($fleet);
            $this->entityManager->flush();
        }

        return $formation;
    }

    /** Formation par défaut : militaires devant, civils derrière, au centre (sans flush) */
    public function createDefault(Fleet $fleet): Formation
    {
        $ships = [];
        foreach ($this->types($fleet) as $code => $type) {
            $ships[$code] = ['quantity' => $fleet->shipCount($type), 'military' => $type->isMilitary()];
        }
        $formation = new Formation($fleet);
        $formation->arrange($this->rules->defaultLayout($ships), $this->types($fleet));
        $fleet->setFormation($formation);
        $this->entityManager->persist($formation);

        return $formation;
    }

    /**
     * @param list<FormationCell> $cells
     *
     * @throws InvalidFormation
     */
    public function arrange(Fleet $fleet, array $cells): Formation
    {
        if (!$fleet->isStationed()) {
            throw new InvalidFormation(['La formation se règle quand la flotte est stationnée.']);
        }
        $types = $this->types($fleet);
        $names = array_map(static fn(ShipType $type): string => $type->getName(), $types);
        $violations = $this->rules->violations($fleet->shipCounts(), $cells, $names);
        if ([] !== $violations) {
            throw new InvalidFormation($violations);
        }

        $formation = $this->of($fleet);
        // Anciennes cases supprimées d'abord : Doctrine insère avant de supprimer, et une même case peut revenir
        $this->entityManager->wrapInTransaction(function () use ($formation, $cells, $types): void {
            $formation->arrange([], $types);
            $this->entityManager->flush();
            $formation->arrange($cells, $types);
            $this->entityManager->flush();
        });

        return $formation;
    }

    /** @return array<string, ShipType> types de la flotte, par code */
    private function types(Fleet $fleet): array
    {
        $types = [];
        foreach ($fleet->getShips() as $ships) {
            $types[$ships->getType()->getCode()] = $ships->getType();
        }

        return $types;
    }
}
