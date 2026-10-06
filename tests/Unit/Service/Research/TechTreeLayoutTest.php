<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Prerequisite;
use App\Entity\Technology;
use App\Enum\Economy\BuildingEffect;
use App\Model\Research\TechTreeNode;
use App\Service\Research\TechTreeLayout;
use PHPUnit\Framework\TestCase;

final class TechTreeLayoutTest extends TestCase
{
    public function testColumnsFollowTheLongestChainOfRequiredTechnologies(): void
    {
        [$energy, $computer, $shielding, $hyperspace] = $this->technologies('energy', 'computer', 'shielding', 'hyperspace_drive');
        $laboratory = new BuildingType('research_lab', 'Laboratoire', BuildingEffect::ResearchLab);

        $tree = new TechTreeLayout()->layout([$energy, $computer, $shielding, $hyperspace], [
            Prerequisite::of($energy, $laboratory, 1),
            Prerequisite::of($shielding, $energy, 3),
            Prerequisite::of($hyperspace, $energy, 5),
            Prerequisite::of($hyperspace, $shielding, 5),
        ]);

        $positions = $this->positions($tree->nodes);
        // Énergie et informatique sans technologie requise ; bouclier après énergie ; hyperespace après bouclier
        self::assertSame([0, 0, 0, 0], $positions['energy']);
        self::assertSame([0, 1, 0, 92], $positions['computer']);
        self::assertSame([1, 0, 272, 0], $positions['shielding']);
        self::assertSame([2, 0, 544, 0], $positions['hyperspace_drive']);
        self::assertSame(744, $tree->width);
        self::assertSame(164, $tree->height);

        // Les bâtiments requis ne font pas de lien
        self::assertCount(3, $tree->edges);
        $edge = $tree->edges[0];
        self::assertSame(['energy', 'shielding', 3], [$edge->from, $edge->to, $edge->level]);
        self::assertSame('M200 36 C236 36 236 36 272 36', $edge->path);
    }

    public function testCycleInContentDoesNotLoop(): void
    {
        [$a, $b] = $this->technologies('a', 'b');

        $tree = new TechTreeLayout()->layout([$a, $b], [Prerequisite::of($a, $b, 1), Prerequisite::of($b, $a, 1)]);

        self::assertCount(2, $tree->nodes);
        self::assertCount(2, $tree->edges);
    }

    public function testEmptyTree(): void
    {
        $tree = new TechTreeLayout()->layout([], []);

        self::assertSame([], $tree->nodes);
        self::assertSame(0, $tree->width);
        self::assertSame(0, $tree->height);
    }

    /** @return list<Technology> */
    private function technologies(string ...$codes): array
    {
        return array_values(array_map(static fn(string $code): Technology => new Technology($code, ucfirst($code)), $codes));
    }

    /**
     * @param list<TechTreeNode> $nodes
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}> colonne, rang, x, y
     */
    private function positions(array $nodes): array
    {
        $positions = [];
        foreach ($nodes as $node) {
            $positions[$node->technology->getCode()] = [$node->column, $node->row, $node->x, $node->y];
        }

        return $positions;
    }
}
