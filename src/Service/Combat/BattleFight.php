<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\BattleParticipant;
use App\Entity\Fleet;
use App\Enum\Combat\AttackAngle;
use App\Enum\Combat\CombatSide;
use App\Model\Combat\CombatResult;
use App\Model\Combat\SideMotion;
use App\Repository\ClassMatchupRepository;
use App\Service\Exploration\Explorations;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Combat d'une bataille entre deux camps déjà constitués (§4.7) : flottes inscrites dans leur camp, moteur de combat,
 * bataille résolue, champ de débris formé, rapport de combat rédigé, pertes retirées des flottes ; une flotte anéantie disparaît (sa
 * participation reste). Partagé par
 * les batailles programmées et les interceptions au niveau d'un système.
 */
final readonly class BattleFight
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClassMatchupRepository $matchups,
        private CombatGroups $groups,
        private CombatEngine $engine,
        private EngagementRules $engagement,
        private Explorations $explorations,
        private Randomizer $randomizer,
        private CombatReports $reports,
        private DebrisFields $debris,
    ) {}

    /**
     * @param list<Fleet> $attackers
     * @param list<Fleet> $defenders
     */
    public function fight(Battle $battle, array $attackers, array $defenders, \DateTimeImmutable $at): CombatResult
    {
        foreach ([CombatSide::Attacker->value => $attackers, CombatSide::Defender->value => $defenders] as $side => $fleets) {
            foreach ($fleets as $fleet) {
                $battle->join($fleet, CombatSide::from($side), $at)->assignSide(CombatSide::from($side));
            }
        }

        $angles = $this->engagement->angles(SideMotion::ofFleets($attackers), SideMotion::ofFleets($defenders));
        $result = $this->engine->resolve(
            [...$this->groups->of(CombatSide::Attacker, $attackers), ...$this->groups->of(CombatSide::Defender, $defenders)],
            $this->matchups->matrix(),
            $this->randomizer,
            array_map(static fn(AttackAngle $angle): array => $angle->rowOrder(), $angles),
        );
        $battle->resolve($result->winner, \count($result->rounds), [
            CombatSide::Attacker->value => $result->lossesBySide(CombatSide::Attacker),
            CombatSide::Defender->value => $result->lossesBySide(CombatSide::Defender),
        ], $at);
        // Débris et rapport, avant que les pertes ne vident les flottes (types, noms, effectifs du début)
        $debris = $this->debris->scatter($battle, $result, [...$attackers, ...$defenders], $at);
        $this->reports->write($battle, $result, [...$attackers, ...$defenders], $angles, $debris);

        $fighting = [...$attackers, ...$defenders];
        foreach ($battle->getParticipants() as $participant) {
            $fleet = $participant->getFleet();
            if (null === $fleet || !\in_array($fleet, $fighting, true)) {
                continue;
            }
            $losses = $result->lossesOfFleet((int) $fleet->getId());
            $participant->recordLosses($losses);
            $this->removeLosses($fleet, $losses);
            if ($fleet->isEmpty()) {
                $this->destroy($fleet, $participant);
            }
        }
        $this->entityManager->flush();

        return $result;
    }

    /** @param array<string, int> $losses par code de type */
    private function removeLosses(Fleet $fleet, array $losses): void
    {
        foreach ($fleet->getShips()->toArray() as $ships) {
            $lost = min($losses[$ships->getType()->getCode()] ?? 0, $ships->getQuantity());
            if ($lost > 0) {
                $fleet->removeShips($ships->getType(), $lost);
            }
        }
    }

    /** Flotte anéantie : elle disparaît ; sa participation et ses événements d'exploration restent */
    private function destroy(Fleet $fleet, BattleParticipant $participant): void
    {
        $participant->releaseFleet();
        $this->entityManager->flush();
        $this->explorations->forget($fleet);
        $this->entityManager->remove($fleet);
    }
}
