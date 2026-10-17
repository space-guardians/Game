<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Empire;
use App\Entity\EscortAssignment;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\FleetOrder;
use App\Entity\Planet;
use App\Entity\PlayerQuest;
use App\Entity\SpaceLocation;
use App\Enum\Fleet\FleetAction;
use App\Enum\Market\PlayerQuestStatus;
use App\Enum\Messaging\MessageCategory;
use App\Exception\Fleet\InvalidFleetMission;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Repository\PlayerQuestRepository;
use App\Service\Alliance\AllianceScanner;
use App\Service\Combat\Battles;
use App\Service\Economy\PlanetResources;
use App\Service\Fleet\FleetDispatch;
use App\Service\Fleet\FleetTravel;
use App\Service\Messaging\GameReports;
use App\Service\Scheduling\EventScheduler;
use App\Service\Universe\PlanetLocks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Quêtes d'escorte (§4.15). Le posteur publie un contrat pour l'une de ses flottes stationnée chez lui : départ public
 * (position, heure), destination secrète, récompense prélevée aussitôt sur la planète. D'autres joueurs y attachent une
 * flotte stationnée au point de départ. À l'heure dite, le convoi part (cargaison chargée, réservoirs complétés) et
 * les escortes qui le peuvent partent avec lui : même trajectoire, même heure d'arrivée, déplacements portés par le
 * sien (FleetMovement::carriedBy). À l'arrivée, ils franchissent ensemble les gardes du système ; si le convoi arrive
 * avec tous ses vaisseaux, la récompense est partagée entre les escortes au prorata des vaisseaux engagés, sinon elle
 * revient au posteur.
 */
final readonly class Escorts
{
    /** Préavis minimal et maximal entre la publication et le départ (provisoire) */
    public const int MIN_NOTICE_MINUTES = 30;
    public const int MAX_NOTICE_DAYS = 7;

    /** Contrats ouverts ou en route au plus par posteur (provisoire) */
    public const int MAX_ACTIVE = 5;

    public function __construct(
        private PlayerQuestRepository $quests,
        private FleetDispatch $dispatch,
        private FleetTravel $travel,
        private PlanetResources $resources,
        private PlanetLocks $planetLocks,
        private EventScheduler $scheduler,
        private AllianceScanner $scanner,
        private GameReports $reports,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidMarketAction */
    public function post(Empire $poster, Fleet $convoy, MissionStep $step, \DateTimeImmutable $departsAt, Resources $reward, Resources $cargo = new Resources()): PlayerQuest
    {
        $now = $this->clock->now();
        $planet = $convoy->getPlanet();
        if ($convoy->getEmpire() !== $poster || null === $planet || !$convoy->isAtHome()) {
            throw new InvalidMarketAction('Le convoi est une flotte stationnée sur l’une de vos planètes.');
        }
        if (!\in_array($step->action, [FleetAction::Transport, FleetAction::Station], true)) {
            throw new InvalidMarketAction('Un convoi transporte des ressources ou va stationner.');
        }
        if (null !== $this->quests->findActiveForConvoy($convoy) || null !== $this->quests->findActiveForEscort($convoy)) {
            throw new InvalidMarketAction(\sprintf('La flotte « %s » est déjà engagée dans un contrat d’escorte.', $convoy->getName()));
        }
        if ($departsAt < $now->modify(\sprintf('+%d minutes', self::MIN_NOTICE_MINUTES)) || $departsAt > $now->modify(\sprintf('+%d days', self::MAX_NOTICE_DAYS))) {
            throw new InvalidMarketAction(\sprintf('Le départ se fixe entre %d minutes et %d jours à l’avance : les escortes doivent pouvoir venir.', self::MIN_NOTICE_MINUTES, self::MAX_NOTICE_DAYS));
        }
        if ($reward->isZero()) {
            throw new InvalidMarketAction('Indiquez la récompense promise aux escortes.');
        }
        if (\count(array_filter($this->quests->findInvolving($poster), static fn(PlayerQuest $quest): bool => $quest->getPoster() === $poster && !$quest->getStatus()->isClosed())) >= self::MAX_ACTIVE) {
            throw new InvalidMarketAction(\sprintf('Vous avez déjà %d contrats en cours.', self::MAX_ACTIVE));
        }

        $lock = $this->planetLocks->acquire($planet);
        try {
            $snapshot = $this->resources->settle($planet);
            if (!$snapshot->amounts->covers($reward)) {
                throw new InvalidMarketAction('La planète du convoi n’a pas de quoi bloquer la récompense.');
            }

            return $this->entityManager->wrapInTransaction(function () use ($poster, $convoy, $planet, $step, $departsAt, $reward, $cargo, $snapshot, $now): PlayerQuest {
                $planet->storeResources($snapshot->amounts->minus($reward), $snapshot->at);
                $quest = new PlayerQuest($poster, $convoy, $convoy->getLocation(), (string) $planet->getAddress(), $departsAt, SpaceLocation::of($step->destination), mb_substr($step->label, 0, 60), $step->action, $reward, ['metal' => $cargo->metal, 'crystal' => $cargo->crystal, 'deuterium' => $cargo->deuterium], $now);
                $this->entityManager->persist($quest);
                $this->entityManager->flush();
                // Sans planète : le départ prend lui-même le verrou de la planète du convoi (FleetDispatch)
                $quest->attachDepartureEvent($this->scheduler->schedule(EscortDepartureHandler::TYPE, $departsAt, null, ['quest' => $quest->getId()]));
                $this->entityManager->flush();

                return $quest;
            });
        } finally {
            $lock->release();
        }
    }

    /** Le posteur annule avant le départ : la récompense lui revient, les escortes sont libérées */
    public function cancel(PlayerQuest $quest, Empire $poster): void
    {
        if ($quest->getPoster() !== $poster) {
            throw new InvalidMarketAction('Seul le posteur annule son contrat.');
        }
        if (PlayerQuestStatus::Open !== $quest->getStatus()) {
            throw new InvalidMarketAction('Le convoi est déjà parti.');
        }
        $now = $this->clock->now();
        $quest->getDepartureEvent()?->cancel($now);
        $quest->close(PlayerQuestStatus::Cancelled, $now);
        $this->refund($quest, $now);
        $this->tell($this->escortEmpires($quest), 'Contrat d’escorte annulé', \sprintf('%s a annulé son contrat d’escorte au départ de %s.', $poster->getName(), $quest->getDepartureLabel()));
        $this->entityManager->flush();
    }

    /** @throws InvalidMarketAction */
    public function accept(PlayerQuest $quest, Empire $empire, Fleet $fleet): EscortAssignment
    {
        if (PlayerQuestStatus::Open !== $quest->getStatus()) {
            throw new InvalidMarketAction('Ce contrat n’accepte plus d’escorte.');
        }
        if ($quest->getPoster() === $empire) {
            throw new InvalidMarketAction('Vous n’escortez pas votre propre convoi.');
        }
        if ($fleet->getEmpire() !== $empire || !$fleet->isStationed() || !Battles::samePlace($fleet->getLocation(), $quest->getDeparture())) {
            throw new InvalidMarketAction(\sprintf('Une escorte attend stationnée au point de départ, %s.', $quest->getDepartureLabel()));
        }
        if (null !== $this->quests->findActiveForEscort($fleet) || null !== $this->quests->findActiveForConvoy($fleet)) {
            throw new InvalidMarketAction(\sprintf('La flotte « %s » est déjà engagée dans un contrat d’escorte.', $fleet->getName()));
        }
        if ($quest->getEscorts()->count() >= PlayerQuest::MAX_ESCORTS) {
            throw new InvalidMarketAction(\sprintf('Ce convoi a déjà %d escortes.', PlayerQuest::MAX_ESCORTS));
        }
        $escort = new EscortAssignment($quest, $empire, $fleet, $this->clock->now());
        $quest->join($escort);
        $this->entityManager->persist($escort);
        $this->entityManager->flush();
        $this->tell($quest->getPoster(), 'Escorte engagée', \sprintf('%s escorte votre convoi avec la flotte « %s » (%d vaisseaux).', $empire->getName(), $fleet->getName(), $fleet->shipCount()));

        return $escort;
    }

    /** Une escorte se retire avant le départ */
    public function leave(PlayerQuest $quest, Empire $empire): void
    {
        if (PlayerQuestStatus::Open !== $quest->getStatus()) {
            throw new InvalidMarketAction('Le convoi est déjà parti.');
        }
        foreach ($quest->getEscorts()->toArray() as $escort) {
            if ($escort->getEmpire() === $empire) {
                $quest->leave($escort);
                $this->entityManager->remove($escort);
            }
        }
        $this->entityManager->flush();
    }

    /**
     * Départ du convoi à l'heure dite, avec les escortes présentes, assez rapides et assez pourvues en carburant pour le
     * suivre ; sinon le contrat échoue et la récompense revient au posteur.
     */
    public function depart(PlayerQuest $quest, \DateTimeImmutable $at): void
    {
        if (PlayerQuestStatus::Open !== $quest->getStatus()) {
            return;
        }
        $convoy = $quest->getConvoy();
        if (null === $convoy || !$convoy->isStationed() || !Battles::samePlace($convoy->getLocation(), $quest->getDeparture())) {
            $this->fail($quest, $at, 'Le convoi n’était pas au point de départ à l’heure dite.');

            return;
        }
        $destination = $quest->getDestination()->toPosition();
        $plan = $this->travel->plan($convoy, $destination);
        $needed = $this->travel->fuelFor($convoy, $plan);
        $fuel = max(0.0, min($convoy->tankCapacity() - $convoy->getFuel(), $needed - $convoy->getFuel()));
        if ($convoy->getFuel() + $fuel + 1e-6 < $needed) {
            $this->fail($quest, $at, 'Les réservoirs du convoi ne suffisent pas au trajet.');

            return;
        }

        // Escortes qui suivent : présentes, pas plus lentes, assez de carburant
        $following = [];
        foreach ($quest->getEscorts()->toArray() as $escort) {
            $fleet = $escort->getFleet();
            $reason = null === $fleet ? 'flotte disparue' : $this->unableToFollow($fleet, $quest, $plan->durationSeconds);
            if (null !== $reason || null === $fleet) {
                $quest->leave($escort);
                $this->entityManager->remove($escort);
                $this->tell($escort->getEmpire(), 'Escorte restée à quai', \sprintf('Votre escorte n’a pas suivi le convoi de %s : %s.', $quest->getPoster()->getName(), $reason ?? 'flotte disparue'));

                continue;
            }
            $following[] = [$escort, $fleet];
        }

        // En route avant le départ effectif : le convoi et ses escortes sont secrets dès le premier scanner (§4.15)
        $quest->setOff($convoy->shipCount());
        $this->entityManager->flush();
        try {
            $movement = $this->dispatch->dispatch($convoy, [new MissionStep($destination, $quest->getAction(), $quest->getDestinationLabel())], 100, $quest->getCargo(), $fuel);
        } catch (InvalidFleetMission $exception) {
            $this->fail($quest, $at, $exception->getMessage());

            return;
        }
        $quest->scheduleArrival($movement->getArrivesAt());
        foreach ($following as [$escort, $fleet]) {
            $escort->setOff($fleet->shipCount());
            $this->carry($fleet, $quest, $movement, $at);
        }
        $this->entityManager->flush();

        $this->tell($quest->getPoster(), 'Convoi parti', \sprintf('Votre convoi « %s » est parti vers %s avec %d escorte(s), arrivée à %s.', $convoy->getName(), $quest->getDestinationLabel(), \count($following), $movement->getArrivesAt()->format('d/m H:i')));
        $this->tell(array_map(static fn(array $pair): Empire => $pair[0]->getEmpire(), $following), 'Escorte en route', \sprintf('Votre escorte accompagne le convoi de %s vers %s, arrivée à %s.', $quest->getPoster()->getName(), $quest->getDestinationLabel(), $movement->getArrivesAt()->format('d/m H:i')));
    }

    /**
     * Arrivée du convoi, après le franchissement des gardes : réussite s'il a gardé tous ses vaisseaux, échec sinon.
     */
    public function arrived(PlayerQuest $quest, \DateTimeImmutable $at): void
    {
        if (PlayerQuestStatus::Underway !== $quest->getStatus()) {
            return;
        }
        // Flottes anéanties au combat, déjà supprimées : le contrat n'y renvoie plus
        $convoy = $quest->getConvoy();
        if (null !== $convoy && !$this->entityManager->contains($convoy)) {
            $quest->forgetConvoy();
            $convoy = null;
        }
        foreach ($quest->getEscorts() as $escort) {
            $fleet = $escort->getFleet();
            if (null !== $fleet && !$this->entityManager->contains($fleet)) {
                $escort->forgetFleet();
            }
        }
        $intact = null !== $convoy && !$convoy->isEmpty() && $convoy->shipCount() >= (int) $quest->getConvoyShips()
            && Battles::samePlace($convoy->getLocation(), $quest->getDestination());
        if (!$intact) {
            $this->fail($quest, $at, 'Le convoi n’est pas arrivé sans perte.');

            return;
        }
        $quest->close(PlayerQuestStatus::Succeeded, $at);
        $escorts = array_values(array_filter($quest->getEscorts()->toArray(), static fn(EscortAssignment $escort): bool => (int) $escort->getShips() > 0));
        $total = array_sum(array_map(static fn(EscortAssignment $escort): int => (int) $escort->getShips(), $escorts));
        if (0 === $total) {
            // Arrivé seul : rien à partager, la récompense revient au posteur
            $this->refund($quest, $at);
            $this->tell($quest->getPoster(), 'Convoi arrivé', \sprintf('Votre convoi est arrivé sans perte à %s, sans escorte : la récompense vous revient.', $quest->getDestinationLabel()));

            return;
        }
        foreach ($escorts as $escort) {
            $share = $quest->getReward()->times((int) $escort->getShips() / $total);
            $this->credit($escort->getEmpire()->getHomePlanet(), $share, $at);
            $this->tell($escort->getEmpire(), 'Escorte réussie', \sprintf('Le convoi de %s est arrivé sans perte : %s vous sont versés sur %s.', $quest->getPoster()->getName(), self::amounts($share), $escort->getEmpire()->getHomePlanet()));
        }
        $this->tell($quest->getPoster(), 'Convoi arrivé', \sprintf('Votre convoi est arrivé sans perte à %s : la récompense est versée à ses escortes.', $quest->getDestinationLabel()));
    }

    /** Raison pour laquelle une escorte ne peut pas suivre le convoi, ou null */
    private function unableToFollow(Fleet $fleet, PlayerQuest $quest, int $convoySeconds): ?string
    {
        if ($fleet->isEmpty()) {
            return 'flotte disparue';
        }
        if (!$fleet->isStationed() || !Battles::samePlace($fleet->getLocation(), $quest->getDeparture())) {
            return 'absente du point de départ';
        }
        $plan = $this->travel->plan($fleet, $quest->getDestination()->toPosition());
        if ($plan->durationSeconds > $convoySeconds) {
            return 'trop lente pour suivre le convoi';
        }
        // Elle ne fait pas le plein chez autrui : le carburant du trajet doit être à bord
        if ($fleet->getFuel() + 1e-6 < $this->travel->fuelFor($fleet, $plan)) {
            return 'carburant insuffisant pour le trajet';
        }

        return null;
    }

    private function carry(Fleet $fleet, PlayerQuest $quest, FleetMovement $convoy, \DateTimeImmutable $at): void
    {
        $plan = $this->travel->plan($fleet, $quest->getDestination()->toPosition());
        $fleet->burn($this->travel->fuelFor($fleet, $plan));
        $fleet->replaceOrders();
        $this->entityManager->flush();
        $order = new FleetOrder($fleet, 1, $quest->getDestination(), FleetAction::Station, mb_substr('Escorte : ' . $quest->getDestinationLabel(), 0, 60));
        $fleet->addOrder($order);
        $movement = new FleetMovement($fleet, $order, $fleet->getLocation(), 100, $at, $convoy->getArrivesAt());
        $movement->carriedBy($convoy);
        $order->start();
        $fleet->depart();
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
        $this->scanner->watch($movement);
    }

    private function fail(PlayerQuest $quest, \DateTimeImmutable $at, string $reason): void
    {
        $quest->close(PlayerQuestStatus::Failed, $at);
        $this->refund($quest, $at);
        $this->tell($quest->getPoster(), 'Contrat d’escorte échoué', \sprintf('%s La récompense vous revient.', $reason));
        $this->tell($this->escortEmpires($quest), 'Contrat d’escorte échoué', \sprintf('Le contrat d’escorte de %s a échoué : %s', $quest->getPoster()->getName(), $reason));
        $this->entityManager->flush();
    }

    private function refund(PlayerQuest $quest, \DateTimeImmutable $at): void
    {
        $this->credit($quest->getPoster()->getHomePlanet(), $quest->getReward(), $at);
    }

    private function credit(Planet $planet, Resources $amount, \DateTimeImmutable $at): void
    {
        $stock = $this->resources->settle($planet, $at)->amounts;
        $planet->storeResources($stock->plus($amount), $at);
    }

    /** @return list<Empire> */
    private function escortEmpires(PlayerQuest $quest): array
    {
        $empires = [];
        foreach ($quest->getEscorts() as $escort) {
            $empires[spl_object_id($escort->getEmpire())] = $escort->getEmpire();
        }

        return array_values($empires);
    }

    /** @param Empire|list<Empire> $empires */
    private function tell(Empire|array $empires, string $subject, string $body): void
    {
        $this->reports->report($empires, MessageCategory::Transport, $subject, $body, 'app_escorts');
    }

    private static function amounts(Resources $resources): string
    {
        return \sprintf('%s métal, %s cristal, %s deutérium', number_format($resources->metal, 0, ',', ' '), number_format($resources->crystal, 0, ',', ' '), number_format($resources->deuterium, 0, ',', ' '));
    }
}
