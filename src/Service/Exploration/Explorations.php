<?php

declare(strict_types=1);

namespace App\Service\Exploration;

use App\Entity\Empire;
use App\Entity\ExplorationEventInstance;
use App\Entity\Fleet;
use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Entity\SpaceLocation;
use App\Enum\Exploration\ExplorationEventStatus;
use App\Exception\Exploration\InvalidQuestChoice;
use App\Model\Exploration\ExplorationContext;
use App\Repository\ExplorationEventInstanceRepository;
use App\Repository\QuestTemplateRepository;
use App\Service\Fleet\FleetMovements;
use App\Service\Scheduling\EventScheduler;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Symfony\Component\Lock\LockFactory;

/**
 * Exploration (§4.6.4) : à l'arrivée d'une flotte en exploration, une quête peut apparaître (probabilité et
 * conditions de son gabarit). Une quête automatique se résout aussitôt par tirage pondéré ; une quête à choix attend
 * la décision du joueur jusqu'à son échéance, et la flotte l'attend sur place (ses ordres restants reprennent
 * ensuite). L'issue applique ses effets à la flotte puis peut enchaîner une quête suivante.
 */
final readonly class Explorations
{
    /** Quêtes enchaînées au plus d'un coup (garde-fou contre une boucle de quêtes automatiques) */
    public const int MAX_CHAIN = 10;

    /** Écart toléré pour considérer la flotte encore sur les lieux de l'événement */
    private const float ON_SITE_TOLERANCE = 1.0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuestTemplateRepository $templates,
        private ExplorationEventInstanceRepository $events,
        private QuestRules $rules,
        private Randomizer $randomizer,
        private EventScheduler $scheduler,
        private FleetMovements $movements,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {}

    /**
     * Arrivée d'une flotte en exploration (sous verrou, dans la transaction de l'arrivée). Renvoie l'événement qui
     * attend le choix du joueur — la flotte reste alors sur place —, ou null.
     */
    public function explore(Fleet $fleet, SpaceLocation $location, string $locationLabel, \DateTimeImmutable $at, int $speedPercent): ?ExplorationEventInstance
    {
        $context = $this->contextOf($fleet);
        $eligible = array_values(array_filter($this->templates->findSpontaneous(), fn(QuestTemplate $template): bool => $this->rules->isEligible($template, $context)));
        $template = $this->rules->drawQuest($eligible, $this->randomizer);
        if (null === $template) {
            return null;
        }

        return $this->start($template, $fleet, $location, $locationLabel, $at, $speedPercent, null, 0);
    }

    /**
     * Décision du joueur : l'issue choisie s'applique, puis la flotte reprend ses ordres (ou attend la quête suivante).
     *
     * @throws InvalidQuestChoice
     */
    public function choose(ExplorationEventInstance $event, QuestOutcome $outcome, Empire $empire): ExplorationEventInstance
    {
        if ($event->getEmpire() !== $empire) {
            throw new InvalidQuestChoice('Cet événement n’est pas le vôtre.');
        }
        if (null === $event->getTemplate() || $outcome->getTemplate() !== $event->getTemplate()) {
            throw new InvalidQuestChoice('Ce choix ne fait pas partie de l’événement.');
        }
        $fleet = $event->getFleet();
        // Même verrou que l'envoi d'une flotte hors planète : la flotte ne repart pas pendant la décision
        $lock = $this->lockFactory->createLock('fleet-' . ($fleet?->getId() ?? 0), ttl: 30.0);
        $lock->acquire(true);

        try {
            $gone = $this->entityManager->wrapInTransaction(function () use ($event, $outcome): bool {
                if (ExplorationEventStatus::AwaitingChoice !== $this->events->lockedStatus((int) $event->getId()) || !$event->isAwaitingChoice()) {
                    throw new InvalidQuestChoice('Cet événement est déjà réglé.');
                }
                $now = $this->clock->now();
                $fleet = $event->getFleet();
                if (null === $fleet || !$this->isOnSite($fleet, $event)) {
                    $event->expire($now);

                    return true;
                }
                $awaiting = $this->settle($event, $outcome, $fleet, $now, 0);
                $this->resume($fleet, $awaiting, $now, $event->getSpeedPercent());

                return false;
            });
        } finally {
            $lock->release();
        }
        if ($gone) {
            throw new InvalidQuestChoice('La flotte a quitté les lieux : l’occasion est perdue.');
        }

        return $event;
    }

    /** Échéance d'une quête à choix sans réponse (sous verrou, dans une transaction) : l'occasion passe */
    public function expire(int $eventId, \DateTimeImmutable $at): void
    {
        $event = $this->entityManager->find(ExplorationEventInstance::class, $eventId);
        if (!$event instanceof ExplorationEventInstance || ExplorationEventStatus::AwaitingChoice !== $this->events->lockedStatus($eventId)) {
            return;
        }
        $event->expire($at);
        $fleet = $event->getFleet();
        if (null !== $fleet && $this->isOnSite($fleet, $event)) {
            $this->resume($fleet, null, $at, $event->getSpeedPercent());
        }
    }

    /** Avant la suppression d'une flotte : ses événements restent dans le journal, sans elle */
    public function forget(Fleet $fleet): void
    {
        foreach ($this->events->findBy(['fleet' => $fleet]) as $event) {
            $event->releaseFleet();
        }
    }

    /** Ce que la flotte apporte : technologies de son empire, vaisseaux, cargaison */
    public function contextOf(Fleet $fleet): ExplorationContext
    {
        $levels = [];
        foreach ($fleet->getEmpire()->getResearches() as $research) {
            $levels[$research->getTechnology()->getCode()] = $research->getLevel();
        }

        return new ExplorationContext($levels, $fleet->shipCounts(), $fleet->getCargo(), $fleet->cargo());
    }

    /**
     * Crée l'événement d'une quête ; une quête automatique se résout aussitôt. Renvoie l'événement en attente de choix
     * au bout de la chaîne, ou null.
     */
    private function start(QuestTemplate $template, Fleet $fleet, SpaceLocation $location, string $label, \DateTimeImmutable $at, int $speedPercent, ?ExplorationEventInstance $previous, int $depth): ?ExplorationEventInstance
    {
        $event = new ExplorationEventInstance($template, $fleet->getEmpire(), $fleet, $location, $label, $at, $speedPercent, $previous);
        $this->entityManager->persist($event);
        // Enregistré aussitôt : la flotte peut disparaître dans la foulée (forget() relit ses événements)
        $this->entityManager->flush();

        if ($template->isPlayerChoice()) {
            $expiresAt = $event->getExpiresAt();
            \assert(null !== $expiresAt);
            $this->scheduler->schedule(ExplorationExpiryHandler::TYPE, $expiresAt, null, ['event' => $event->getId()]);

            return $event;
        }

        $outcome = $this->rules->drawOutcome($template, $this->randomizer);
        if (null === $outcome) {
            // Gabarit sans issue tirable (refusé par la validation du panneau) : il ne se passe rien
            $event->expire($at);

            return null;
        }

        return $this->settle($event, $outcome, $fleet, $at, $depth);
    }

    /** Applique l'issue à la flotte, puis enchaîne la quête suivante si la flotte en remplit les conditions */
    private function settle(ExplorationEventInstance $event, QuestOutcome $outcome, Fleet $fleet, \DateTimeImmutable $at, int $depth): ?ExplorationEventInstance
    {
        $report = [$outcome->getText()];
        $effects = $this->apply($fleet, $outcome);
        if ('' !== $effects) {
            $report[] = $effects;
        }

        $next = $outcome->getNextQuest();
        if ($fleet->isEmpty()) {
            $report[] = 'La flotte est perdue.';
        } elseif (null !== $next && $next->isPublished()) {
            $unmet = $this->rules->unmetConditions($next, $this->contextOf($fleet));
            if ($depth >= self::MAX_CHAIN) {
                $report[] = 'La piste s’arrête là.';
            } elseif ([] !== $unmet) {
                $report[] = \sprintf('La suite (« %s ») demandait : %s. La piste s’arrête là.', $next->getName(), implode(' ; ', $unmet));
            } else {
                $report[] = \sprintf('Suite : « %s ».', $next->getName());
                $event->resolve($outcome, implode("\n\n", $report), $at);

                return $this->start($next, $fleet, $event->getLocation(), $event->getLocationLabel(), $at, $event->getSpeedPercent(), $event, $depth + 1);
            }
        }
        $event->resolve($outcome, implode("\n\n", $report), $at);

        return null;
    }

    /** Effets d'une issue : vaisseaux perdus, cargaison gagnée ou perdue. Renvoie leur résumé, ou une chaîne vide */
    private function apply(Fleet $fleet, QuestOutcome $outcome): string
    {
        $summary = [];
        $losses = $this->rules->shipLosses($fleet->shipCounts(), $outcome->getShipLossPercent());
        $lost = [];
        foreach ($fleet->getShips()->toArray() as $ships) {
            $type = $ships->getType();
            $count = $losses[$type->getCode()] ?? 0;
            if ($count > 0) {
                $fleet->removeShips($type, $count);
                $lost[] = \sprintf('%d × %s', $count, $type->getName());
            }
        }
        if ([] !== $lost) {
            $summary[] = 'Vaisseaux perdus : ' . implode(', ', $lost) . '.';
            // Réservoirs perdus avec les vaisseaux
            $fleet->burn(max(0.0, $fleet->getFuel() - $fleet->tankCapacity()));
        }

        $before = $fleet->getCargo();
        $after = $this->rules->cargoAfter($before, $fleet->cargo(), $outcome);
        $fleet->unload();
        $fleet->load($after);
        $changes = [];
        foreach (['metal' => 'métal', 'crystal' => 'cristal', 'deuterium' => 'deutérium'] as $key => $label) {
            $delta = (int) round($after->{$key} - $before->{$key});
            if (0 !== $delta) {
                $changes[] = \sprintf('%+d %s', $delta, $label);
            }
        }
        if ([] !== $changes) {
            $summary[] = 'Cargaison : ' . implode(', ', $changes) . '.';
        }

        return implode(' ', $summary);
    }

    /** Après une décision : flotte détruite supprimée, sinon elle attend la quête suivante ou reprend ses ordres */
    private function resume(Fleet $fleet, ?ExplorationEventInstance $awaiting, \DateTimeImmutable $at, int $speedPercent): void
    {
        if ($fleet->isEmpty()) {
            $this->entityManager->flush();
            $this->forget($fleet);
            $this->entityManager->remove($fleet);
            $this->entityManager->flush();

            return;
        }
        $this->entityManager->flush();
        if (null === $awaiting) {
            $this->movements->launchNext($fleet, $at, $speedPercent);
        }
    }

    private function isOnSite(Fleet $fleet, ExplorationEventInstance $event): bool
    {
        return $fleet->isStationed()
            && $fleet->getLocation()->toPosition()->global()->distanceTo($event->getLocation()->toPosition()->global()) <= self::ON_SITE_TOLERANCE;
    }
}
