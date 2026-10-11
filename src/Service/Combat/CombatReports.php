<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\CombatReport;
use App\Entity\Fleet;
use App\Enum\Combat\AttackAngle;
use App\Model\Combat\CombatResult;
use App\Model\Scheduling\GameTopics;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Environment;

/**
 * Rapports de combat (§4.7) : rédigés à la résolution d'une bataille, à partir du résultat du moteur, puis notifiés en
 * temps réel à chaque empire engagé — une fois la résolution validée (flush()), jamais pour un combat annulé par un
 * échec de transaction.
 */
final class CombatReports implements ResetInterface
{
    /** @var list<CombatReport> rapports rédigés, en attente de notification */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HubInterface $hub,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<Fleet>                $fleets flottes engagées, pour les noms de flotte et d'empire
     * @param array<string, AttackAngle> $angles par camp
     */
    public function write(Battle $battle, CombatResult $result, array $fleets, array $angles): CombatReport
    {
        $byId = [];
        foreach ($fleets as $fleet) {
            $byId[(int) $fleet->getId()] = $fleet;
        }
        $groups = [];
        foreach ($result->groups as $group) {
            $fleet = null === $group->fleetId ? null : ($byId[$group->fleetId] ?? null);
            $groups[] = [
                'side' => $group->side->value,
                'fleet' => $fleet?->getName() ?? '—',
                'empire' => $fleet?->getEmpire()->getName() ?? '—',
                'type' => $group->typeName,
                'class' => $group->classCode,
                'row' => $group->row->value,
                'column' => $group->column->value,
                'count' => $group->count,
                'lost' => $result->lossesOf($group->key),
                'dealt' => round($result->dealtBy($group->key), 1),
                'received' => round($result->receivedBy($group->key), 1),
            ];
        }
        $rounds = [];
        foreach ($result->rounds as $round) {
            $rounds[] = [
                'number' => $round->number,
                'shots' => $round->shots,
                'hits' => $round->hits,
                'absorbed' => array_map(static fn(float $value): float => round($value, 1), $round->absorbed),
                'damage' => array_map(static fn(float $value): float => round($value, 1), $round->damage),
                'losses' => array_sum($round->losses),
            ];
        }

        $report = new CombatReport(
            $battle,
            $battle->getResolvedAt() ?? $battle->getResolvesAt(),
            mb_substr($battle->getLocationLabel(), 0, 80),
            $result->winner,
            array_map(static fn(AttackAngle $angle): string => $angle->value, $angles),
            $groups,
            $rounds,
        );
        $this->entityManager->persist($report);
        $this->pending[] = $report;

        return $report;
    }

    /** Résolution validée : chaque empire engagé reçoit une notification par rapport */
    public function notify(): void
    {
        $reports = $this->pending;
        $this->pending = [];
        foreach ($reports as $report) {
            $notified = [];
            foreach ($report->getBattle()->getParticipants() as $participant) {
                $empire = $participant->getEmpire();
                if (isset($notified[(int) $empire->getId()])) {
                    continue;
                }
                $notified[(int) $empire->getId()] = true;
                $side = $report->sideOf($empire);
                try {
                    $this->hub->publish(new Update(
                        GameTopics::empire($empire),
                        $this->twig->render('streams/combat_report.stream.html.twig', [
                            'report' => $report,
                            'outcome' => null === $report->getWinner() ? 'draw' : ($report->getWinner() === $side ? 'victory' : 'defeat'),
                        ]),
                        private: true,
                    ));
                } catch (\Throwable $exception) {
                    // Le rapport reste consultable : seule la notification en direct est perdue
                    $this->logger->warning('Notification de rapport de combat impossible (rapport #{id}).', ['id' => $report->getId(), 'exception' => $exception]);
                }
            }
        }
    }

    /** Résolution annulée (échec) : les rapports rédigés n'existent plus */
    public function discard(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
