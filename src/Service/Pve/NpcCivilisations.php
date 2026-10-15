<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\NpcBehaviorProfile;
use App\Entity\Planet;
use App\Enum\Pve\NpcCategory;
use App\Model\Economy\Resources;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Fondation d'une civilisation non-joueuse (§4.14) sur une planète libre : empire sans compte, stock de départ, défenses
 * au sol et une flotte stationnée sur sa planète mère, dont la composition dépend de la catégorie (valeurs
 * provisoires). Elle utilise ensuite les mêmes flottes, trajets et combats que les joueurs.
 */
final readonly class NpcCivilisations
{
    /** Stock de départ de chaque ressource (provisoire) */
    public const float STARTING_STOCK = 20_000.0;

    /** Composition de départ par catégorie : défenses au sol, puis flotte (codes de types, provisoire) */
    public const array DEFENSES = [
        'minor' => ['rocket_launcher' => 20],
        'boss' => ['rocket_launcher' => 200],
    ];

    public const array FLEET = [
        'minor' => ['light_fighter' => 15, 'cruiser' => 3, 'small_cargo' => 5],
        'boss' => ['light_fighter' => 150, 'cruiser' => 60],
    ];

    public function __construct(
        private ShipTypeRepository $shipTypes,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws \DomainException planète déjà occupée */
    public function found(NpcBehaviorProfile $profile, string $name, Planet $planet): Empire
    {
        if ($planet->isClaimed()) {
            throw new \DomainException(\sprintf('La planète %s est déjà occupée.', $planet));
        }
        $now = $this->clock->now();
        $npc = Empire::npc($name, $planet, $profile, $now);
        $planet->storeResources(new Resources(self::STARTING_STOCK, self::STARTING_STOCK, self::STARTING_STOCK), $now);
        $category = NpcCategory::Boss === $profile->getCategory() ? 'boss' : 'minor';
        foreach (self::DEFENSES[$category] as $code => $quantity) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \LogicException(\sprintf('Type de défense « %s » introuvable.', $code));
            $planet->addShips($type, $quantity);
        }
        $fleet = new Fleet($npc, \sprintf('Flotte de %s', $name), $planet, $now);
        foreach (self::FLEET[$category] as $code => $quantity) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \LogicException(\sprintf('Type de vaisseau « %s » introuvable.', $code));
            $fleet->addShips($type, $quantity);
        }
        // Réservoirs pleins : ses missions reposent sur les mêmes règles de carburant que celles des joueurs
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager->persist($npc);
        $this->entityManager->persist($fleet);
        $this->entityManager->flush();

        return $npc;
    }
}
