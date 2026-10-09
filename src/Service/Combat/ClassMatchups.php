<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\ClassMatchup;
use App\Exception\Combat\InvalidMatchupMatrix;
use App\Repository\ClassMatchupRepository;
use App\Repository\ShipClassRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Édition de la matrice des classes (§4.7) depuis le panneau : la grille complète est enregistrée d'un coup ; une
 * case à ×1 (ou vide) supprime la paire, qui redevient neutre.
 */
final readonly class ClassMatchups
{
    public function __construct(
        private ShipClassRepository $classes,
        private ClassMatchupRepository $matchups,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $grid [code attaquante][code visée] => multiplicateur saisi (virgule ou point décimal)
     *
     * @return int cases modifiées
     *
     * @throws InvalidMatchupMatrix
     */
    public function save(array $grid): int
    {
        $classes = $this->classes->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        $wanted = [];
        $violations = [];
        foreach ($classes as $attacker) {
            foreach ($classes as $defender) {
                $raw = trim(str_replace(',', '.', (string) ($grid[$attacker->getCode()][$defender->getCode()] ?? '')));
                if ('' === $raw) {
                    continue;
                }
                if (!is_numeric($raw) || (float) $raw < ClassMatchup::MIN || (float) $raw > ClassMatchup::MAX) {
                    $violations[] = \sprintf('%s contre %s : un multiplicateur va de %s à %s.', $attacker->getName(), $defender->getName(), number_format(ClassMatchup::MIN, 1, ',', ''), number_format(ClassMatchup::MAX, 0, ',', ''));

                    continue;
                }
                $wanted[$attacker->getCode()][$defender->getCode()] = round((float) $raw, 2);
            }
        }
        if ([] !== $violations) {
            throw new InvalidMatchupMatrix($violations);
        }

        $byCode = [];
        foreach ($classes as $class) {
            $byCode[$class->getCode()] = $class;
        }
        $changed = 0;
        $existing = [];
        foreach ($this->matchups->findAllWithClasses() as $matchup) {
            $value = $wanted[$matchup->getAttacker()->getCode()][$matchup->getDefender()->getCode()] ?? 1.0;
            $existing[$matchup->getAttacker()->getCode()][$matchup->getDefender()->getCode()] = true;
            if (1.0 === $value) {
                $this->entityManager->remove($matchup);
                ++$changed;
            } elseif ($value !== $matchup->getMultiplier()) {
                $matchup->setMultiplier($value);
                ++$changed;
            }
        }
        foreach ($wanted as $attacker => $row) {
            foreach ($row as $defender => $value) {
                if (1.0 !== $value && !isset($existing[$attacker][$defender])) {
                    $this->entityManager->persist(new ClassMatchup($byCode[$attacker], $byCode[$defender], $value));
                    ++$changed;
                }
            }
        }
        $this->entityManager->flush();

        return $changed;
    }
}
