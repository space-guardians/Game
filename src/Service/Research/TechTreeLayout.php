<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Entity\Prerequisite;
use App\Entity\Technology;
use App\Model\Research\TechTree;
use App\Model\Research\TechTreeEdge;
use App\Model\Research\TechTreeNode;

/**
 * Disposition de l'arbre de recherche (§4.4, §5.5), sans accès aux données ni librairie graphique : une colonne par
 * profondeur (la plus longue chaîne de technologies requises), les technologies d'une colonne dans leur ordre
 * d'affichage. Les bâtiments requis ne sont pas des nœuds : ils s'affichent sur le nœud.
 */
final readonly class TechTreeLayout
{
    public const int NODE_WIDTH = 200;
    public const int NODE_HEIGHT = 72;
    public const int COLUMN_GAP = 72;
    public const int ROW_GAP = 20;

    /**
     * @param list<Technology>   $technologies dans l'ordre d'affichage
     * @param list<Prerequisite> $prerequisites tous les prérequis (seuls ceux d'une technologie vers une technologie forment des liens)
     */
    public function layout(array $technologies, array $prerequisites): TechTree
    {
        $codes = array_map(static fn(Technology $technology): string => $technology->getCode(), $technologies);
        /** @var array<string, list<array{from: string, level: int}>> $parents */
        $parents = [];
        foreach ($prerequisites as $prerequisite) {
            $target = $prerequisite->getTarget();
            $required = $prerequisite->getRequired();
            if ($target instanceof Technology && $required instanceof Technology && \in_array($required->getCode(), $codes, true)) {
                $parents[$target->getCode()][] = ['from' => $required->getCode(), 'level' => $prerequisite->getLevel()];
            }
        }

        $depths = $this->depths($codes, $parents);

        $rows = [];
        $nodes = [];
        $positions = [];
        foreach ($technologies as $technology) {
            $column = $depths[$technology->getCode()];
            $row = $rows[$column] = ($rows[$column] ?? -1) + 1;
            $x = $column * (self::NODE_WIDTH + self::COLUMN_GAP);
            $y = $row * (self::NODE_HEIGHT + self::ROW_GAP);
            $nodes[] = new TechTreeNode($technology, $column, $row, $x, $y);
            $positions[$technology->getCode()] = [$x, $y];
        }

        $edges = [];
        foreach ($parents as $to => $requirements) {
            foreach ($requirements as ['from' => $from, 'level' => $level]) {
                $edges[] = new TechTreeEdge($from, $to, $level, $this->path($positions[$from], $positions[$to]));
            }
        }

        $columns = [] === $rows ? 0 : max(array_keys($rows)) + 1;
        $maxRows = [] === $rows ? 0 : max($rows) + 1;

        return new TechTree(
            $nodes,
            $edges,
            max(0, $columns * (self::NODE_WIDTH + self::COLUMN_GAP) - self::COLUMN_GAP),
            max(0, $maxRows * (self::NODE_HEIGHT + self::ROW_GAP) - self::ROW_GAP),
        );
    }

    /**
     * Profondeur de chaque technologie : 0 sans technologie requise, sinon 1 + la plus grande profondeur de ses
     * technologies requises. Un cycle (contenu mal réglé) est coupé au lieu de boucler.
     *
     * @param list<string>                                         $codes
     * @param array<string, list<array{from: string, level: int}>> $parents
     *
     * @return array<string, int>
     */
    private function depths(array $codes, array $parents): array
    {
        $depths = [];
        $visiting = [];
        $depth = function (string $code) use (&$depth, &$depths, &$visiting, $parents): int {
            if (isset($depths[$code])) {
                return $depths[$code];
            }
            if (isset($visiting[$code])) {
                return 0;
            }
            $visiting[$code] = true;
            $value = 0;
            foreach ($parents[$code] ?? [] as ['from' => $from]) {
                $value = max($value, $depth($from) + 1);
            }
            unset($visiting[$code]);

            return $depths[$code] = $value;
        };
        foreach ($codes as $code) {
            $depth($code);
        }

        return $depths;
    }

    /**
     * @param array{0: int, 1: int} $from coin haut gauche du nœud source
     * @param array{0: int, 1: int} $to   coin haut gauche du nœud cible
     */
    private function path(array $from, array $to): string
    {
        $x1 = $from[0] + self::NODE_WIDTH;
        $y1 = $from[1] + intdiv(self::NODE_HEIGHT, 2);
        $x2 = $to[0];
        $y2 = $to[1] + intdiv(self::NODE_HEIGHT, 2);
        $bend = intdiv(self::COLUMN_GAP, 2);

        return \sprintf('M%d %d C%d %d %d %d %d %d', $x1, $y1, $x1 + $bend, $y1, $x2 - $bend, $y2, $x2, $y2);
    }
}
