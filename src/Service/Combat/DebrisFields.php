<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Debris;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Model\Combat\CombatResult;
use App\Model\Economy\Resources;
use App\Repository\DebrisRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Champs de débris (§4.6, §4.7) : formés au lieu d'une bataille par les vaisseaux détruits, récupérés par des
 * recycleurs (action « Recyclage »).
 */
final readonly class DebrisFields
{
    public function __construct(
        private DebrisRepository $debris,
        private DebrisRules $rules,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Débris laissés par les vaisseaux détruits d'une bataille, ajoutés au champ du lieu (créé au besoin).
     *
     * @param list<Fleet> $fleets flottes engagées, avant retrait des pertes (types de vaisseaux)
     */
    public function scatter(Battle $battle, CombatResult $result, array $fleets, \DateTimeImmutable $at): Resources
    {
        $types = [];
        foreach ($fleets as $fleet) {
            foreach ($fleet->getShips() as $ships) {
                $types[$ships->getType()->getCode()] = $ships->getType();
            }
        }
        $destroyed = [];
        foreach ($result->groups as $group) {
            $type = $types[$group->typeCode] ?? null;
            $lost = $result->lossesOf($group->key);
            if ($type instanceof ShipType && $lost > 0) {
                $destroyed[] = ['cost' => $type->getCost(), 'count' => $lost];
            }
        }
        $scattered = $this->rules->fromDestroyed($destroyed);
        if ($scattered->metal + $scattered->crystal < Debris::EMPTY_BELOW) {
            return new Resources();
        }

        $field = $this->debris->findAt($battle->getLocation());
        if (null === $field) {
            $field = new Debris($battle->getLocation(), mb_substr($battle->getLocationLabel(), 0, 80), $at);
            $this->entityManager->persist($field);
        }
        $field->add($scattered, $at);

        return $scattered;
    }

    /**
     * Recyclage par une flotte arrivée sur place : ses recycleurs récupèrent le champ dans la limite de leur cargo et
     * de la place libre de la flotte. Renvoie la raison d'un échec, ou null.
     */
    public function recycle(Fleet $fleet, SpaceLocation $location, \DateTimeImmutable $at): ?string
    {
        $field = $this->debris->findAt($location);
        if (null === $field || $field->isEmpty()) {
            return 'Aucun champ de débris à cet endroit.';
        }
        $recyclers = 0;
        foreach ($fleet->getShips() as $ships) {
            if (ShipType::RECYCLER === $ships->getType()->getCode()) {
                $recyclers += $ships->getType()->getCargo() * $ships->getQuantity();
            }
        }
        $cargo = $fleet->getCargo();
        $free = $fleet->cargo() - ($cargo->metal + $cargo->crystal + $cargo->deuterium);
        $collected = $field->take($this->rules->collectable($field->getResources(), max(0.0, min($recyclers, $free))), $at);
        if ($collected->metal + $collected->crystal <= 0) {
            return 'Soutes pleines : rien n’a pu être récupéré.';
        }
        $fleet->load($collected);
        $left = $field->getResources();
        if ($left->metal + $left->crystal < Debris::EMPTY_BELOW) {
            $this->entityManager->remove($field);
        }

        return null;
    }
}
