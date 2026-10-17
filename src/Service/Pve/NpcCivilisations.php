<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\NpcBehaviorProfile;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Fleet\ShipCategory;
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

    /** Composition par défaut si le profil n'en fixe pas : défenses au sol, puis flotte (codes de types, provisoire) */
    public const array DEFENSES = [
        'minor' => ['rocket_launcher' => 20],
        'boss' => ['rocket_launcher' => 200],
        'trader' => ['rocket_launcher' => 30],
    ];

    public const array FLEET = [
        'minor' => ['light_fighter' => 15, 'cruiser' => 3, 'small_cargo' => 5],
        'boss' => ['light_fighter' => 150, 'cruiser' => 60],
        // Guilde marchande : composition de chaque convoi, aucune flotte stationnée à la fondation
        'trader' => ['small_cargo' => 10, 'large_cargo' => 2],
    ];

    public function __construct(
        private ShipTypeRepository $shipTypes,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /**
     * Renforce la flotte stationnée sur la planète mère de la civilisation, ou en crée une (§4.14 « Administration »).
     *
     * @throws \DomainException
     */
    public function reinforce(Empire $npc, ShipType $type, int $quantity): Fleet
    {
        if (!$npc->isNpc()) {
            throw new \DomainException('Seule une civilisation non-joueuse se renforce depuis le panneau.');
        }
        if ($quantity < 1 || ShipCategory::Defense === $type->getCategory()) {
            throw new \DomainException('Choisissez un type de vaisseau et une quantité positive.');
        }
        $fleet = null;
        foreach ($this->entityManager->getRepository(Fleet::class)->findBy(['empire' => $npc], ['id' => 'ASC']) as $candidate) {
            if (FleetStatus::Stationed === $candidate->getStatus() && $candidate->isAtHome()) {
                $fleet = $candidate;
                break;
            }
        }
        if (null === $fleet) {
            $fleet = new Fleet($npc, \sprintf('Flotte de %s', $npc->getName()), $npc->getHomePlanet(), $this->clock->now());
            $this->entityManager->persist($fleet);
        }
        $fleet->addShips($type, $quantity);
        $fleet->refuel(max(0.0, $fleet->tankCapacity() - $fleet->getFuel()));
        $this->entityManager->flush();

        return $fleet;
    }

    /**
     * Flotte de la civilisation stationnée sur la planète, réservoirs pleins : ses missions reposent sur les mêmes règles
     * de carburant que celles des joueurs.
     *
     * @param array<string, int> $composition
     */
    public function fleet(Empire $npc, string $name, Planet $planet, array $composition): Fleet
    {
        $fleet = new Fleet($npc, $name, $planet, $this->clock->now());
        foreach ($composition as $code => $quantity) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \LogicException(\sprintf('Type de vaisseau « %s » introuvable.', $code));
            $fleet->addShips($type, $quantity);
        }
        $fleet->refuel($fleet->tankCapacity());

        return $fleet;
    }

    /** @throws \DomainException planète déjà occupée */
    public function found(NpcBehaviorProfile $profile, string $name, Planet $planet): Empire
    {
        if ($planet->isClaimed()) {
            throw new \DomainException(\sprintf('La planète %s est déjà occupée.', $planet));
        }
        $now = $this->clock->now();
        $npc = Empire::npc($name, $planet, $profile, $now);
        $planet->storeResources(new Resources(self::STARTING_STOCK, self::STARTING_STOCK, self::STARTING_STOCK), $now);
        $category = $profile->getCategory()->value;
        foreach ($profile->getDefenseComposition() ?: self::DEFENSES[$category] as $code => $quantity) {
            $type = $this->shipTypes->findOneByCode($code) ?? throw new \LogicException(\sprintf('Type de défense « %s » introuvable.', $code));
            $planet->addShips($type, $quantity);
        }
        $this->entityManager->persist($npc);
        // Une guilde marchande n'a pas de flotte stationnée : sa composition est celle de ses convois (NpcConvoys)
        if (NpcCategory::Trader !== $profile->getCategory()) {
            $this->entityManager->persist($this->fleet($npc, \sprintf('Flotte de %s', $name), $planet, $profile->getFleetComposition() ?: self::FLEET[$category]));
        }
        $this->entityManager->flush();

        return $npc;
    }
}
