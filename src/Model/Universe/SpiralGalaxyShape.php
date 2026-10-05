<?php

declare(strict_types=1);

namespace App\Model\Universe;

/**
 * Forme procédurale d'une galaxie spirale à n branches, centrée sur (0 ; 0).
 *
 * Elle définit une fonction de densité, plus forte près du centre (bulbe) et le long des branches
 * (spirales logarithmiques), qui décroît en s'éloignant du centre.
 *
 * @see §2.2 du cahier des charges
 */
final readonly class SpiralGalaxyShape
{
    /** Densité minimale partout : garantit que le placement finit par atteindre son quota */
    public const float MIN_DENSITY = 0.005;

    public function __construct(
        /** Nombre de branches */
        public int $arms = 4,
        /** Enroulement des branches : angle ajouté (en radians) par unité de ln(1 + r / rayon du bulbe) */
        public float $armTightness = 2.5,
        /** Largeur angulaire d'une branche (écart-type, en radians) */
        public float $armWidth = 0.25,
        /** Rayon du bulbe central, très dense */
        public float $coreRadius = 1_500.0,
        /** Distance caractéristique de décroissance de la densité du disque */
        public float $diskScale = 5_000.0,
        /** Densité relative entre les branches, de 0 (vide) à 1 (aussi dense que les branches) */
        public float $interArmDensity = 0.03,
    ) {
        if ($arms < 1 || $arms > 12) {
            throw new \InvalidArgumentException('Une galaxie spirale a entre 1 et 12 branches.');
        }
        if ($armWidth <= 0 || $coreRadius <= 0 || $diskScale <= 0) {
            throw new \InvalidArgumentException('La largeur des branches, le rayon du bulbe et l\'échelle du disque doivent être strictement positifs.');
        }
        if ($interArmDensity < 0 || $interArmDensity > 1) {
            throw new \InvalidArgumentException('La densité entre les branches doit être comprise entre 0 et 1.');
        }
    }

    /**
     * Densité relative, entre MIN_DENSITY et 1, au point de coordonnées polaires (rayon, angle).
     *
     * @param float $rotation orientation de la galaxie (angle de départ des branches), en radians
     */
    public function density(float $radius, float $angle, float $rotation = 0.0): float
    {
        $core = exp(-($radius / $this->coreRadius) ** 2);
        $disk = exp(-$radius / $this->diskScale);
        $arm = $this->armProximity($radius, $angle, $rotation);

        $density = $core + $disk * ($this->interArmDensity + (1 - $this->interArmDensity) * $arm);

        return max(self::MIN_DENSITY, min(1.0, $density));
    }

    /**
     * Proximité de la branche la plus proche, de 0 (loin de toute branche) à 1 (sur une branche).
     */
    public function armProximity(float $radius, float $angle, float $rotation = 0.0): float
    {
        $winding = $this->armTightness * log(1 + $radius / $this->coreRadius);
        $closest = M_PI;

        for ($arm = 0; $arm < $this->arms; ++$arm) {
            $armAngle = $rotation + $arm * 2 * M_PI / $this->arms + $winding;
            $closest = min($closest, abs(self::wrapAngle($angle - $armAngle)));
        }

        return exp(-($closest ** 2) / (2 * $this->armWidth ** 2));
    }

    /** Ramène un angle dans ]-π ; π] */
    private static function wrapAngle(float $angle): float
    {
        $angle = fmod($angle + M_PI, 2 * M_PI);

        return ($angle <= 0 ? $angle + 2 * M_PI : $angle) - M_PI;
    }
}
