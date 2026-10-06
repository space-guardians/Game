<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * État des ressources d'une planète à un instant : de quoi afficher stock, débit, jauge et énergie, et laisser
 * l'interface poursuivre le décompte d'elle-même.
 */
final readonly class ResourceSnapshot
{
    public ResourceRates $hourlyProduction;
    public Resources $capacity;

    public function __construct(
        public Resources $amounts,
        public PlanetOutput $output,
        public \DateTimeImmutable $at,
    ) {
        $this->hourlyProduction = $output->hourlyProduction;
        $this->capacity = $output->capacity;
    }
}
