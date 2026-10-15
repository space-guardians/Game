<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Combat\AttackAngle;
use App\Service\Balance\CombatSimulator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:balance:combat', description: 'Simule des combats entre deux compositions (passe d’équilibrage)')]
final readonly class SimulateCombatCommand
{
    public function __construct(
        private CombatSimulator $simulator,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Assaillants, ex. « light_fighter:100,cruiser:10 »')]
        string $attackers,
        #[Argument('Défenseurs, même format')]
        string $defenders,
        #[Option('Nombre de combats simulés')]
        int $runs = 100,
        #[Option('Angle sous lequel le défenseur est pris : front, flank ou rear')]
        string $angle = 'front',
        #[Option('Niveau des technologies d’armes, de bouclier et de blindage des deux camps')]
        int $technology = 0,
        #[Option('Graine du tirage (précision des tirs)')]
        int $seed = 1,
    ): int {
        $attackAngle = AttackAngle::tryFrom($angle);
        if (null === $attackAngle) {
            $io->error('Angle attendu : front, flank ou rear.');

            return Command::INVALID;
        }
        try {
            $result = $this->simulator->simulate(self::composition($attackers), self::composition($defenders), $runs, $attackAngle, $technology, $seed);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }
        $io->table(['Combats', 'Victoires assaillants', 'Victoires défenseurs', 'Nuls', 'Rounds (moyenne)', 'Pertes assaillants', 'Pertes défenseurs'], [[
            $result->runs,
            \sprintf('%d (%.0f %%)', $result->attackerWins, 100 * $result->attackerWins / $result->runs),
            \sprintf('%d (%.0f %%)', $result->defenderWins, 100 * $result->defenderWins / $result->runs),
            $result->draws(),
            \sprintf('%.1f', $result->averageRounds),
            \sprintf('%.0f %%', 100 * $result->attackerLosses),
            \sprintf('%.0f %%', 100 * $result->defenderLosses),
        ]]);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     *
     * @throws \InvalidArgumentException
     */
    public static function composition(string $text): array
    {
        $composition = [];
        foreach (array_filter(array_map('trim', explode(',', $text))) as $part) {
            if (1 !== preg_match('/^([a-z0-9_]+)\s*:\s*(\d+)$/', $part, $match) || (int) $match[2] < 1) {
                throw new \InvalidArgumentException(\sprintf('Composition illisible : « %s » (attendu « code:quantité »).', $part));
            }
            $composition[$match[1]] = ($composition[$match[1]] ?? 0) + (int) $match[2];
        }
        if ([] === $composition) {
            throw new \InvalidArgumentException('Composition vide.');
        }

        return $composition;
    }
}
