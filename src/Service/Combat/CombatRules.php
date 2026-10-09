<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Enum\Fleet\FormationRow;
use Random\Randomizer;

/**
 * Formules du combat (§4.7), sans état ni base. Valeurs provisoires, à reprendre lors de la passe d'équilibrage (§7) :
 * - jusqu'à 6 rounds, tirs simultanés ;
 * - chaque vaisseau tire une fois par round ; la précision (80 %) est le seul aléa ;
 * - les tirs se répartissent sur les lignes de la formation adverse dans l'ordre d'engagement : 60 % sur la première
 *   ligne occupée, 30 % sur la suivante, 10 % sur la dernière ; dans une ligne, au prorata des vaisseaux ;
 * - dégâts d'un tir = attaque × matrice des classes × protection de la ligne touchée (avant 1, milieu 0,85,
 *   arrière 0,7) ;
 * - le bouclier de chaque vaisseau touché arrête des dégâts à chaque round (il se recharge) ; un tir inférieur à
 *   1 % du bouclier ricoche ;
 * - les dégâts restants s'accumulent sur la coque : un vaisseau dont la structure tombe à 0 est détruit, sans
 *   explosion aléatoire ;
 * - technologies armement / bouclier / coque : +10 % par niveau.
 */
final readonly class CombatRules
{
    public const int MAX_ROUNDS = 6;

    public const float ACCURACY = 0.8;

    /** Bonus par niveau de technologie armement, bouclier ou coque */
    public const float TECHNOLOGY_BONUS = 0.1;

    /** Part des tirs sur chaque ligne occupée, dans l'ordre d'engagement */
    public const array ROW_FIRE_SHARES = [0.6, 0.3, 0.1];

    /** Tir inférieur à cette part du bouclier : ricoche */
    public const float BOUNCE_RATIO = 0.01;

    /** Codes des technologies qui renforcent le combat */
    public const string WEAPONS = 'weapons';

    public const string SHIELDING = 'shielding';

    public const string ARMOUR = 'armour';

    /** Au-delà, le nombre de tirs au but est tiré par approximation normale plutôt que tir par tir */
    private const int EXACT_DRAW_LIMIT = 2000;

    public function withTechnology(float $base, int $level): float
    {
        return $base * (1 + self::TECHNOLOGY_BONUS * max(0, $level));
    }

    /** Protection de la ligne touchée : multiplicateur des dégâts reçus */
    public function rowProtection(FormationRow $row): float
    {
        return match ($row) {
            FormationRow::Front => 1.0,
            FormationRow::Middle => 0.85,
            FormationRow::Back => 0.7,
        };
    }

    /**
     * Ordre d'engagement face à un adversaire de front (§4.7, règle « jamais de surprise ») ; l'angle d'attaque entre
     * flottes convergentes (#44) pourra en donner un autre.
     *
     * @return list<FormationRow>
     */
    public function frontalOrder(): array
    {
        return [FormationRow::Front, FormationRow::Middle, FormationRow::Back];
    }

    /**
     * Part des tirs reçue par chaque ligne occupée, dans l'ordre d'engagement, renormalisée sur les lignes occupées.
     *
     * @param list<FormationRow> $occupied lignes encore occupées, dans l'ordre d'engagement
     *
     * @return array<string, float> par valeur de FormationRow
     */
    public function rowShares(array $occupied): array
    {
        $weights = [];
        foreach ($occupied as $rank => $row) {
            $weights[$row->value] = self::ROW_FIRE_SHARES[$rank] ?? 0.0;
        }
        $total = array_sum($weights);

        return $total > 0 ? array_map(static fn(float $weight): float => $weight / $total, $weights) : [];
    }

    /**
     * Répartit un nombre entier selon des poids, sans perte (plus forts restes), dans l'ordre des clés à égalité.
     *
     * @param array<string, float> $weights
     *
     * @return array<string, int>
     */
    public function split(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($total <= 0 || $sum <= 0) {
            return array_map(static fn(): int => 0, $weights);
        }
        $parts = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $exact = $total * $weight / $sum;
            $parts[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $parts[$key];
        }
        $left = $total - array_sum($parts);
        arsort($remainders);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            ++$parts[$key];
            --$left;
        }

        return $parts;
    }

    /** Tirs au but parmi des tirs (loi binomiale de paramètre ACCURACY) : le seul aléa du combat */
    public function hits(int $shots, Randomizer $randomizer): int
    {
        if ($shots <= 0) {
            return 0;
        }
        if ($shots <= self::EXACT_DRAW_LIMIT) {
            $hits = 0;
            for ($i = 0; $i < $shots; ++$i) {
                if ($randomizer->nextFloat() < self::ACCURACY) {
                    ++$hits;
                }
            }

            return $hits;
        }
        // Approximation normale (Box-Muller), bornée au nombre de tirs
        $mean = $shots * self::ACCURACY;
        $deviation = sqrt($shots * self::ACCURACY * (1 - self::ACCURACY));
        $gaussian = sqrt(-2 * log(1 - $randomizer->nextFloat())) * cos(2 * M_PI * $randomizer->nextFloat());

        return max(0, min($shots, (int) round($mean + $deviation * $gaussian)));
    }

    /**
     * Dégâts portés à la coque d'un groupe en un round : tirs reçus (par paquets de même dégât unitaire), répartis
     * au plus égal sur ses vaisseaux, chaque vaisseau touché arrêtant jusqu'à son bouclier.
     *
     * @param list<array{hits: int, damage: float}> $incoming
     *
     * @return array{hull: float, absorbed: float}
     */
    public function hullDamage(array $incoming, int $ships, float $shield): array
    {
        $total = 0.0;
        $bounced = 0.0;
        $hits = 0;
        foreach ($incoming as ['hits' => $count, 'damage' => $damage]) {
            // Ricochet : un tir trop faible face au bouclier ne fait rien
            if ($damage < self::BOUNCE_RATIO * $shield) {
                $bounced += $count * $damage;

                continue;
            }
            $total += $count * $damage;
            $hits += $count;
        }
        $absorbed = min($total, $shield * min($ships, $hits));

        return ['hull' => $total - $absorbed, 'absorbed' => $absorbed + $bounced];
    }
}
