<?php

declare(strict_types=1);

namespace App\Service\FairPlay;

use App\Entity\Empire;
use App\Enum\Fleet\FleetStatus;
use App\Exception\FairPlay\ProtectionRefused;
use App\Repository\FleetRepository;
use App\Repository\PlanetRepository;
use App\Service\Combat\IncomingAttacks;
use App\Service\Economy\PlanetResources;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Protections d'un joueur (§4.13) : bouclier débutant et mode vacances. Une planète protégée ne peut être ni attaquée
 * ni espionnée (vérifié à l'envoi par `FleetDispatch`) ; en vacances, la production est réduite et les flottes restent à
 * quai. Le mode vacances ne s'active pas avec une flotte hors de chez elle ni sous une attaque en route, et dure au
 * moins `ProtectionRules::VACATION_MIN_HOURS` heures. Les civilisations non-joueuses ne sont jamais protégées.
 */
final readonly class Protections
{
    public function __construct(
        private ProtectionRules $rules,
        private PlanetRepository $planets,
        private PlanetResources $resources,
        private FleetRepository $fleets,
        private IncomingAttacks $incoming,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** Raison pour laquelle les planètes de cet empire ne peuvent être visées par une action hostile, ou null */
    public function refusal(Empire $target): ?string
    {
        if ($target->isNpc()) {
            return null;
        }
        if ($target->isOnVacation()) {
            return \sprintf('%s est en mode vacances : ses planètes sont protégées.', $target->getName());
        }
        if ($this->isNewbie($target)) {
            return \sprintf('%s bénéficie du bouclier débutant jusqu’au %s.', $target->getName(), $this->rules->newbieShieldEnds($target->getFoundedAt())->format('d/m/Y H:i'));
        }

        return null;
    }

    public function isNewbie(Empire $empire): bool
    {
        return !$empire->isNpc() && $this->rules->isNewbie($empire->getFoundedAt(), $empire->getScore(), $this->clock->now());
    }

    public function newbieShieldEnds(Empire $empire): \DateTimeImmutable
    {
        return $this->rules->newbieShieldEnds($empire->getFoundedAt());
    }

    /** @throws ProtectionRefused */
    public function startVacation(Empire $empire): void
    {
        if ($empire->isOnVacation()) {
            throw new ProtectionRefused('Vous êtes déjà en mode vacances.');
        }
        foreach ($this->fleets->findBy(['empire' => $empire]) as $fleet) {
            if (FleetStatus::Stationed !== $fleet->getStatus() || !$fleet->isAtHome()) {
                throw new ProtectionRefused(\sprintf('La flotte « %s » n’est pas rentrée sur une de vos planètes : rappelez-la d’abord.', $fleet->getName()));
            }
        }
        if ([] !== $this->incoming->of($empire)) {
            throw new ProtectionRefused('Une attaque est en route vers vos planètes : le mode vacances ne s’active pas sous la menace.');
        }
        $now = $this->clock->now();
        $this->settle($empire, $now);
        $empire->startVacation($now);
        $this->entityManager->flush();
    }

    /** @throws ProtectionRefused */
    public function endVacation(Empire $empire): void
    {
        $since = $empire->getVacationSince();
        if (null === $since) {
            throw new ProtectionRefused('Vous n’êtes pas en mode vacances.');
        }
        $now = $this->clock->now();
        $earliest = $this->rules->vacationEndsEarliest($since);
        if ($now < $earliest) {
            throw new ProtectionRefused(\sprintf('Le mode vacances dure au moins %d heures : fin possible à partir du %s.', ProtectionRules::VACATION_MIN_HOURS, $earliest->format('d/m/Y H:i')));
        }
        // Production réduite jusqu'à maintenant, puis normale
        $this->settle($empire, $now);
        $empire->endVacation();
        $this->entityManager->flush();
    }

    public function vacationEndsEarliest(Empire $empire): ?\DateTimeImmutable
    {
        $since = $empire->getVacationSince();

        return null === $since ? null : $this->rules->vacationEndsEarliest($since);
    }

    private function settle(Empire $empire, \DateTimeImmutable $at): void
    {
        foreach ($this->planets->findOwnedBy($empire) as $planet) {
            $this->resources->settle($planet, $at);
        }
    }
}
