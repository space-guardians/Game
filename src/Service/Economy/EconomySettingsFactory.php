<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\GameSetting;
use App\Model\Economy\EconomySettings;
use App\Repository\GameSettingRepository;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Réglages de l'économie : valeurs de configuration, sauf la vitesse d'univers, réglée dans le panneau
 * (`GameSetting::UNIVERSE_SPEED`, la configuration en repli). Lue à la construction du service : à chaque requête web,
 * au redémarrage pour le worker.
 */
final readonly class EconomySettingsFactory
{
    public function __construct(
        private GameSettingRepository $settings,
    ) {}

    /**
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $baseHourlyProduction
     * @param array{metal: float|int, crystal: float|int, deuterium: float|int} $startingResources
     * @param array<string, array<int, float>>                                  $orbitBonuses
     */
    public function create(array $baseHourlyProduction, array $startingResources, array $orbitBonuses, float $universeSpeed): EconomySettings
    {
        try {
            $universeSpeed = $this->settings->valueOf(GameSetting::UNIVERSE_SPEED, $universeSpeed);
        } catch (DBALException) {
            // Base pas encore migrée (installation, commandes de maintenance) : la configuration fait foi
        }

        return new EconomySettings($baseHourlyProduction, $startingResources, $orbitBonuses, $universeSpeed);
    }
}
