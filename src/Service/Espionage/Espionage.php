<?php

declare(strict_types=1);

namespace App\Service\Espionage;

use App\Entity\Empire;
use App\Entity\EspionageReport;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\SpaceLocation;
use App\Repository\FleetRepository;
use App\Service\Combat\Battles;
use App\Service\Economy\PlanetResources;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Espionnage (§4.9) : des sondes arrivées sur une planète, ou auprès d'une flotte, rédigent un rapport pour leur
 * empire. Le niveau de détail dépend des technologies d'espionnage respectives et du nombre de sondes
 * (EspionageRules). La détection des sondes par la cible relève de #53.
 */
final readonly class Espionage
{
    public function __construct(
        private EspionageRules $rules,
        private FleetRepository $fleets,
        private PlanetResources $resources,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Rapport des sondes de la flotte, arrivées au lieu donné (planète visée le cas échéant). Renvoie la raison d'un
     * échec, ou null.
     */
    public function spy(Fleet $probes, SpaceLocation $location, string $locationLabel, ?Planet $planet, \DateTimeImmutable $at): ?string
    {
        $spy = $probes->getEmpire();
        $fleets = array_values(array_filter(
            $this->fleets->findStoppedInGalaxy($location->galaxyId),
            static fn(Fleet $fleet): bool => $fleet->getEmpire() !== $spy && Battles::samePlace($fleet->getLocation(), $location),
        ));
        if (null === $planet && [] === $fleets) {
            return 'Aucune flotte à espionner à cette position.';
        }

        // Empire espionné : propriétaire de la planète, sinon le mieux protégé des propriétaires de flottes
        $target = $planet?->getOwner();
        foreach ($fleets as $fleet) {
            if (null === $target || $this->level($fleet->getEmpire()) > $this->level($target)) {
                $target = $fleet->getEmpire();
            }
        }
        $score = $this->rules->score($this->level($spy), null === $target ? 0 : $this->level($target), $probes->shipCount());

        $sections = [];
        foreach ($this->rules->revealed($score) as $section) {
            $content = $this->section($section, $planet, $fleets, $target, $at);
            if (null !== $content) {
                $sections[$section] = $content;
            }
        }

        $this->entityManager->persist(new EspionageReport($spy, mb_substr($locationLabel, 0, 80), $target?->getName(), $at, $score, $sections));

        return null;
    }

    /**
     * Contenu d'une section ; null si elle n'a pas de sens pour cette cible (ressources sans planète…).
     *
     * @param list<Fleet> $fleets flottes d'autres empires sur place
     *
     * @return array<mixed>|null
     */
    private function section(string $section, ?Planet $planet, array $fleets, ?Empire $target, \DateTimeImmutable $at): ?array
    {
        switch ($section) {
            case EspionageRules::RESOURCES:
                if (null === $planet) {
                    return null;
                }
                $amounts = $this->resources->snapshot($planet, $at)->amounts;

                return ['metal' => floor($amounts->metal), 'crystal' => floor($amounts->crystal), 'deuterium' => floor($amounts->deuterium)];
            case EspionageRules::FLEETS:
                $seen = [];
                if (null !== $planet) {
                    $hangar = $this->inventory($planet, defenses: false);
                    if ([] !== $hangar) {
                        $seen[] = ['name' => 'Hangar de la planète', 'empire' => $planet->getOwner()?->getName(), 'ships' => $hangar];
                    }
                }
                foreach ($fleets as $fleet) {
                    $ships = [];
                    foreach ($fleet->getShips() as $stock) {
                        $ships[$stock->getType()->getName()] = $stock->getQuantity();
                    }
                    $seen[] = ['name' => $fleet->getName(), 'empire' => $fleet->getEmpire()->getName(), 'ships' => $ships];
                }

                return $seen;
            case EspionageRules::DEFENSES:
                return null === $planet ? null : $this->inventory($planet, defenses: true);
            case EspionageRules::BUILDINGS:
                if (null === $planet) {
                    return null;
                }
                $buildings = [];
                foreach ($planet->getBuildings() as $building) {
                    if ($building->getLevel() > 0) {
                        $buildings[$building->getType()->getName()] = $building->getLevel();
                    }
                }

                return $buildings;
            case EspionageRules::RESEARCH:
                if (null === $target) {
                    return null;
                }
                $research = [];
                foreach ($target->getResearches() as $item) {
                    if ($item->getLevel() > 0) {
                        $research[$item->getTechnology()->getName()] = $item->getLevel();
                    }
                }

                return $research;
            default:
                return null;
        }
    }

    /** @return array<string, int> vaisseaux (ou défenses) de la planète, par nom */
    private function inventory(Planet $planet, bool $defenses): array
    {
        $inventory = [];
        foreach ($planet->getShips() as $stock) {
            if ($stock->getType()->isDefense() === $defenses && $stock->getQuantity() > 0) {
                $inventory[$stock->getType()->getName()] = $stock->getQuantity();
            }
        }

        return $inventory;
    }

    private function level(Empire $empire): int
    {
        foreach ($empire->getResearches() as $research) {
            if (EspionageRules::TECHNOLOGY === $research->getTechnology()->getCode()) {
                return $research->getLevel();
            }
        }

        return 0;
    }
}
