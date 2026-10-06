<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Model\Fleet\FormationCell;

/**
 * Règles de la formation (§4.7), sans accès aux données : répartition par défaut, et contrôle qu'une répartition place
 * exactement chaque vaisseau de la flotte, une seule fois.
 */
final readonly class FormationRules
{
    /**
     * Formation par défaut : vaisseaux militaires en ligne avant, civils en ligne arrière, au centre.
     *
     * @param array<string, array{quantity: int, military: bool}> $ships vaisseaux de la flotte, par code
     *
     * @return list<FormationCell>
     */
    public function defaultLayout(array $ships): array
    {
        $cells = [];
        foreach ($ships as $code => ['quantity' => $quantity, 'military' => $military]) {
            if ($quantity > 0) {
                $cells[] = new FormationCell($military ? FormationRow::Front : FormationRow::Back, FormationColumn::Center, $code, $quantity);
            }
        }

        return $cells;
    }

    /**
     * Écarts entre la répartition et la flotte (vide si la répartition est valide).
     *
     * @param array<string, int>    $fleet vaisseaux de la flotte, par code
     * @param list<FormationCell>   $cells
     * @param array<string, string> $names noms des types, par code (le code à défaut)
     *
     * @return list<string> messages, un par type mal réparti
     */
    public function violations(array $fleet, array $cells, array $names = []): array
    {
        $placed = [];
        $violations = [];
        foreach ($cells as $cell) {
            if ($cell->quantity < 0) {
                $violations[] = \sprintf('Nombre négatif en %s : %d.', $cell->key(), $cell->quantity);
            }
            $placed[$cell->ship] = ($placed[$cell->ship] ?? 0) + $cell->quantity;
        }
        foreach ($placed as $ship => $count) {
            if (!isset($fleet[$ship]) && $count > 0) {
                $violations[] = \sprintf('« %s » ne fait pas partie de la flotte.', $names[$ship] ?? $ship);
            }
        }
        foreach ($fleet as $ship => $count) {
            $inLayout = $placed[$ship] ?? 0;
            if ($inLayout !== $count) {
                $violations[] = \sprintf('%s : %d placé(s) sur %d.', $names[$ship] ?? $ship, $inLayout, $count);
            }
        }

        return $violations;
    }
}
