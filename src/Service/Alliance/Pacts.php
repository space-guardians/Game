<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\AlliancePact;
use App\Entity\Empire;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Repository\AlliancePactRepository;
use App\Repository\EmpireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Pactes de non-agression (§4.10.1) : proposition avec une durée (1 à 365 jours), acceptation ou refus par l'autre
 * empire, retrait par celui qui propose. Un pacte conclu ne se rompt pas : il court jusqu'à son terme.
 */
final readonly class Pacts
{
    public function __construct(
        private AlliancePactRepository $pacts,
        private EmpireRepository $empires,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidAllianceAction */
    public function propose(Empire $proposer, string $partnerName, int $days): AlliancePact
    {
        $partner = $this->empires->findOneByName($partnerName) ?? throw new InvalidAllianceAction(\sprintf('Aucun joueur ne s’appelle « %s ».', trim($partnerName)));
        if ($partner === $proposer) {
            throw new InvalidAllianceAction('On ne conclut pas de pacte avec soi-même.');
        }
        if ($days < 1 || $days > AlliancePact::MAX_DAYS) {
            throw new InvalidAllianceAction(\sprintf('Un pacte dure de 1 à %d jours.', AlliancePact::MAX_DAYS));
        }
        $now = $this->clock->now();
        $open = $this->pacts->findOpenBetween($proposer, $partner, $now);
        if (null !== $open) {
            throw new InvalidAllianceAction($open->isConcluded()
                ? \sprintf('Un pacte avec %s court déjà jusqu’au %s.', $partner->getName(), $open->getEndsAt()?->format('d/m/Y'))
                : \sprintf('Une proposition de pacte avec %s attend déjà une réponse.', $partner->getName()));
        }

        $pact = new AlliancePact($proposer, $partner, $days, $now);
        $this->entityManager->persist($pact);
        $this->entityManager->flush();

        return $pact;
    }

    /** @throws InvalidAllianceAction */
    public function accept(AlliancePact $pact, Empire $empire): void
    {
        $this->assertPending($pact);
        if ($pact->getPartner() !== $empire) {
            throw new InvalidAllianceAction('Seul l’empire sollicité peut accepter ce pacte.');
        }
        $pact->conclude($this->clock->now());
        $this->entityManager->flush();
    }

    /** Refus par l'empire sollicité, ou retrait par celui qui propose : la proposition disparaît */
    public function dismiss(AlliancePact $pact, Empire $empire): void
    {
        $this->assertPending($pact);
        if (!$pact->involves($empire)) {
            throw new InvalidAllianceAction('Cette proposition ne vous concerne pas.');
        }
        $this->entityManager->remove($pact);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    private function assertPending(AlliancePact $pact): void
    {
        if ($pact->isConcluded()) {
            throw new InvalidAllianceAction('Un pacte conclu ne peut pas être rompu : il court jusqu’à son terme.');
        }
    }
}
