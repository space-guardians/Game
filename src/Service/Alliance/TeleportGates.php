<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\AlliancePlanet;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\TeleportLink;
use App\Enum\Alliance\AllianceBuildingType;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePlanetRepository;
use App\Repository\TeleportLinkRepository;
use App\Service\Fleet\FleetArrivalHandler;
use App\Service\Scheduling\EventScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Portes de téléportation (§4.10) : le fondateur ou un diplomate relie deux portes de l'alliance ; une flotte d'un
 * membre qui entre par l'une ressort par l'autre après le délai de transit, intouchable et sans demi-tour possible
 * (un déplacement en vol, que rien n'engage au combat et que le scanner ne voit pas).
 */
final readonly class TeleportGates
{
    public function __construct(
        private TeleportRules $rules,
        private TeleportLinkRepository $links,
        private AlliancePlanetRepository $alliancePlanets,
        private AllianceMemberRepository $members,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidAllianceAction */
    public function link(Empire $by, AlliancePlanet $a, AlliancePlanet $b): TeleportLink
    {
        $member = $this->members->findOneByEmpire($by);
        if (null === $member || $member->getAlliance() !== $a->getAlliance() || !$member->getRole()->canRecruit()) {
            throw new InvalidAllianceAction('Seuls le fondateur et les diplomates relient les portes de l’alliance.');
        }
        if ($a === $b || $a->getAlliance() !== $b->getAlliance()) {
            throw new InvalidAllianceAction('Une porte se relie à une autre porte de la même alliance.');
        }
        $now = $this->clock->now();
        foreach ([$a, $b] as $gate) {
            if (!$gate->isBuiltAt($now) || $gate->buildingLevel(AllianceBuildingType::TeleportGate) < 1) {
                throw new InvalidAllianceAction(\sprintf('%s n’a pas de porte de téléportation en service.', $gate->getName()));
            }
            if (null !== $this->links->findFor($gate)) {
                throw new InvalidAllianceAction(\sprintf('La porte de %s est déjà reliée.', $gate->getName()));
            }
        }
        $link = new TeleportLink($a->getAlliance(), $a, $b, $now);
        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link;
    }

    /**
     * Les flottes déjà en transit ressortent quand même par la porte prévue.
     *
     * @throws InvalidAllianceAction
     */
    public function unlink(Empire $by, TeleportLink $link): void
    {
        $member = $this->members->findOneByEmpire($by);
        if (null === $member || $member->getAlliance() !== $link->getAlliance() || !$member->getRole()->canRecruit()) {
            throw new InvalidAllianceAction('Seuls le fondateur et les diplomates relient les portes de l’alliance.');
        }
        $this->entityManager->remove($link);
        $this->entityManager->flush();
    }

    /**
     * Raison pour laquelle cette flotte ne peut pas entrer par la porte de cette planète, ou null (vérifié à l'envoi
     * et à l'arrivée).
     */
    public function refusal(Planet $entry, Empire $empire, ?\DateTimeImmutable $at = null): ?string
    {
        return $this->route($entry, $empire, $at ?? $this->clock->now())[0];
    }

    /**
     * Entrée par la porte : la flotte part en transit vers la porte reliée ; renvoie la raison d'un refus, ou null.
     * L'ordre reste en cours jusqu'à la sortie (FleetArrivalHandler).
     */
    public function enter(Fleet $fleet, FleetOrder $order, Planet $entry, \DateTimeImmutable $at, int $speedPercent): ?string
    {
        [$refusal, $exit, $seconds] = $this->route($entry, $fleet->getEmpire(), $at);
        if (null !== $refusal || null === $exit) {
            return $refusal;
        }

        $movement = new FleetMovement($fleet, $order, $fleet->getLocation(), $speedPercent, $at, $at->modify(\sprintf('+%d seconds', $seconds)));
        $movement->throughGate($exit);
        $fleet->depart();
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
        $movement->attachEvent($this->scheduler->schedule(FleetArrivalHandler::TYPE, $movement->getArrivesAt(), $exit, [
            'movement' => $movement->getId(),
            'fleet' => $fleet->getId(),
            'order' => $order->getId(),
        ]));
        $this->entityManager->flush();

        return null;
    }

    /** @return array{0: ?string, 1: ?Planet, 2: int} refus, planète de sortie, délai de transit */
    private function route(Planet $entry, Empire $empire, \DateTimeImmutable $at): array
    {
        $gate = $this->alliancePlanets->findOneByPlanet($entry);
        if (null === $gate || !$gate->isBuiltAt($at) || $gate->buildingLevel(AllianceBuildingType::TeleportGate) < 1) {
            return [\sprintf('Pas de porte de téléportation en service en %s.', $entry), null, 0];
        }
        if ($this->members->findOneByEmpire($empire)?->getAlliance() !== $gate->getAlliance()) {
            return ['Seules les flottes des membres de l’alliance empruntent ses portes.', null, 0];
        }
        $link = $this->links->findFor($gate);
        if (null === $link) {
            return [\sprintf('La porte de %s n’est reliée à aucune autre.', $gate->getName()), null, 0];
        }
        $exit = $link->exitFor($gate);

        return [null, $exit->getPlanet(), $this->rules->transitSeconds(
            $gate->buildingLevel(AllianceBuildingType::TeleportGate),
            $exit->buildingLevel(AllianceBuildingType::TeleportGate),
        )];
    }
}
