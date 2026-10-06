<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Réglages de l'économie (services.yaml, paramètres app.economy.*) : production naturelle d'une planète habitée,
 * dotation de départ, bonus d'orbite des mines (§2.2) et vitesse d'univers, qui multiplie toute production.
 * Les bâtiments (types réglables dans le panneau) ajoutent leur production, leur énergie et leur stockage.
 */
final readonly class EconomySettings
{
    public Resources $baseHourlyProduction;
    public Resources $startingResources;

    /**
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $baseHourlyProduction
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $startingResources
     * @param array<string, array<int, float>>                                  $orbitBonuses        ressource => orbite => bonus (0,35 = +35 %)
     */
    public function __construct(
        array $baseHourlyProduction,
        array $startingResources,
        public array $orbitBonuses,
        public float $universeSpeed,
    ) {
        if ($universeSpeed <= 0) {
            throw new \InvalidArgumentException('La vitesse d\'univers doit être strictement positive.');
        }
        $this->baseHourlyProduction = self::resources($baseHourlyProduction);
        $this->startingResources = self::resources($startingResources);
    }

    /** @param array{metal: float|int, crystal: float|int, deuterium: float|int} $amounts */
    private static function resources(array $amounts): Resources
    {
        return new Resources((float) $amounts['metal'], (float) $amounts['crystal'], (float) $amounts['deuterium']);
    }
}
