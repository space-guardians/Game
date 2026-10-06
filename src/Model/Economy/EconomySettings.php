<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Réglages de l'économie (services.yaml, paramètres app.economy.*). Les bâtiments (#20) ajoutent leur production
 * et leur stockage à ces valeurs de base ; la vitesse d'univers multiplie toute production.
 */
final readonly class EconomySettings
{
    public Resources $baseHourlyProduction;
    public Resources $baseCapacity;
    public Resources $startingResources;

    /**
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $baseHourlyProduction
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $baseCapacity
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $startingResources
     */
    public function __construct(
        array $baseHourlyProduction,
        array $baseCapacity,
        array $startingResources,
        public float $universeSpeed,
    ) {
        if ($universeSpeed <= 0) {
            throw new \InvalidArgumentException('La vitesse d\'univers doit être strictement positive.');
        }
        $this->baseHourlyProduction = self::resources($baseHourlyProduction);
        $this->baseCapacity = self::resources($baseCapacity);
        $this->startingResources = self::resources($startingResources);
    }

    /** @param array{metal: float|int, crystal: float|int, deuterium: float|int} $amounts */
    private static function resources(array $amounts): Resources
    {
        return new Resources((float) $amounts['metal'], (float) $amounts['crystal'], (float) $amounts['deuterium']);
    }
}
