<?php

declare(strict_types=1);

namespace App\Universe\Generation;

use App\Entity\GlobalPosition;
use Random\Randomizer;

/**
 * Place les systèmes stellaires d'une galaxie en s'éloignant progressivement du centre.
 *
 * La galaxie est parcourue par anneaux concentriques de largeur « distance minimale ». Dans chaque
 * anneau, des candidats sont tirés uniformément ; chacun est accepté avec une probabilité égale à la
 * densité de la forme en ce point, et seulement s'il respecte la distance minimale avec tous les
 * systèmes déjà placés (échantillonnage par rejet, variante du Poisson-disc sampling). La génération
 * s'arrête dès que le quota est atteint : la galaxie n'a pas de rayon fixé à l'avance.
 *
 * Résultat déterministe pour un Randomizer initialisé avec la même graine.
 *
 * @see §2.2 du cahier des charges
 */
final readonly class SystemPlacer
{
    /**
     * Candidats tirés par surface « distance minimale² ». Au-delà, les branches saturent (plus de place
     * pour un système supplémentaire) et le contraste de densité avec l'espace inter-branches disparaît.
     */
    private const float CANDIDATES_PER_CELL = 1.0;

    /** Distance minimale par défaut entre deux systèmes : leurs orbites planétaires doivent rester en deçà de la moitié */
    public const float DEFAULT_MIN_DISTANCE = 200.0;

    /** Garde-fou contre des paramètres qui empêcheraient d'atteindre le quota */
    private const int MAX_RINGS = 10_000;

    /**
     * @return list<GlobalPosition> positions dans l'ordre de génération (du centre vers l'extérieur)
     */
    public function place(SpiralGalaxyShape $shape, int $count, float $minDistance, Randomizer $randomizer): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Il faut placer au moins un système.');
        }
        if ($minDistance <= 0) {
            throw new \InvalidArgumentException('La distance minimale entre systèmes doit être strictement positive.');
        }

        $rotation = $randomizer->getFloat(0, 2 * M_PI);
        $grid = new SpatialGrid($minDistance);
        $positions = [];

        for ($ring = 0; $ring < self::MAX_RINGS; ++$ring) {
            $inner = $ring * $minDistance;
            $outer = $inner + $minDistance;
            $area = M_PI * ($outer ** 2 - $inner ** 2);
            $candidates = (int) ceil(self::CANDIDATES_PER_CELL * $area / $minDistance ** 2);

            for ($i = 0; $i < $candidates; ++$i) {
                // Rayon tiré proportionnellement à la surface : répartition uniforme dans l'anneau
                $radius = sqrt($inner ** 2 + $randomizer->nextFloat() * ($outer ** 2 - $inner ** 2));
                $angle = $randomizer->getFloat(0, 2 * M_PI);

                if ($randomizer->nextFloat() >= $shape->density($radius, $angle, $rotation)) {
                    continue;
                }

                $position = new GlobalPosition($radius * cos($angle), $radius * sin($angle));
                if ($grid->hasNeighbourWithin($position, $minDistance)) {
                    continue;
                }

                $grid->add($position);
                $positions[] = $position;

                if (\count($positions) === $count) {
                    return $positions;
                }
            }
        }

        throw new \RuntimeException(\sprintf('Impossible de placer %d systèmes en %d anneaux : vérifier les paramètres de forme.', $count, self::MAX_RINGS));
    }
}
