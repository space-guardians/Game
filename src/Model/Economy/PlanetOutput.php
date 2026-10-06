<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Bilan économique d'une planète : débits nets, capacités et énergie.
 */
final readonly class PlanetOutput
{
    public function __construct(
        public ResourceRates $hourlyProduction,
        public Resources $capacity,
        public float $energyProduced = 0.0,
        public float $energyConsumed = 0.0,
        /** Part de la production des mines assurée, de 0 à 1 : en dessous de 1, l'énergie manque */
        public float $productionFactor = 1.0,
    ) {}

    public function energyBalance(): float
    {
        return $this->energyProduced - $this->energyConsumed;
    }
}
