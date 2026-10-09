<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Service\Exploration\Explorations;
use App\Service\Exploration\QuestChains;
use PHPUnit\Framework\TestCase;

final class QuestChainsTest extends TestCase
{
    public function testTreeFollowsEachOutcome(): void
    {
        $second = $this->quest('Deuxième');
        $first = $this->quest('Première', $second, null);

        $tree = new QuestChains()->tree($first);

        self::assertSame($first, $tree->template);
        self::assertCount(2, $tree->branches);
        self::assertSame($second, $tree->branches[0]['next']?->template);
        // La deuxième quête termine la chaîne
        self::assertNull($tree->branches[0]['next']->branches[0]['next']);
        self::assertNull($tree->branches[1]['next']);
    }

    public function testLoopIsDetectedInsteadOfRecursingForever(): void
    {
        $a = $this->quest('A');
        $b = $this->quest('B', $a);
        $a->getOutcomes()[0]?->setNextQuest($b);

        $tree = new QuestChains()->tree($a);

        $backToA = $tree->branches[0]['next']?->branches[0]['next'];
        self::assertSame($a, $backToA?->template);
        self::assertTrue($backToA->loop);
        self::assertSame([], $backToA->branches);
    }

    public function testDepthIsBoundedLikeInGame(): void
    {
        $quest = $this->quest('Fin');
        for ($i = 0; $i < Explorations::MAX_CHAIN + 3; ++$i) {
            $quest = $this->quest('Étape ' . $i, $quest);
        }

        $node = new QuestChains()->tree($quest);
        for ($depth = 0; $depth < Explorations::MAX_CHAIN; ++$depth) {
            $next = $node->branches[0]['next'] ?? null;
            self::assertNotNull($next);
            $node = $next;
        }

        self::assertTrue($node->truncated);
    }

    /** Quête dont chaque issue mène à la quête donnée (null : fin) */
    private function quest(string $name, ?QuestTemplate ...$next): QuestTemplate
    {
        $template = new QuestTemplate();
        $template->setName($name);
        foreach ([] === $next ? [null] : $next as $following) {
            $outcome = new QuestOutcome();
            $outcome->setLabel('vers ' . ($following?->getName() ?? 'la fin'));
            $outcome->setNextQuest($following);
            $template->addOutcome($outcome);
        }

        return $template;
    }
}
