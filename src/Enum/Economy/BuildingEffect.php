<?php

declare(strict_types=1);

namespace App\Enum\Economy;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Effet d'un type de bâtiment : il choisit la formule appliquée (BuildingRules), dont les paramètres sont réglables
 * dans le panneau d'administration (§4.3, §5.6.1).
 */
enum BuildingEffect: string implements TranslatableInterface
{
    case MetalProduction = 'metal_production';
    case CrystalProduction = 'crystal_production';
    case DeuteriumProduction = 'deuterium_production';
    case SolarEnergy = 'solar_energy';
    case FusionEnergy = 'fusion_energy';
    case MetalStorage = 'metal_storage';
    case CrystalStorage = 'crystal_storage';
    case DeuteriumStorage = 'deuterium_storage';
    case RobotFactory = 'robot_factory';
    case NaniteFactory = 'nanite_factory';

    public function label(): string
    {
        return match ($this) {
            self::MetalProduction => 'Production de métal',
            self::CrystalProduction => 'Production de cristal',
            self::DeuteriumProduction => 'Production de deutérium',
            self::SolarEnergy => 'Énergie solaire',
            self::FusionEnergy => 'Énergie de fusion',
            self::MetalStorage => 'Stockage de métal',
            self::CrystalStorage => 'Stockage de cristal',
            self::DeuteriumStorage => 'Stockage de deutérium',
            self::RobotFactory => 'Vitesse de construction (robots)',
            self::NaniteFactory => 'Vitesse de construction (nanites)',
        };
    }

    /** Ressource produite ou stockée (« metal », « crystal », « deuterium ») ; null pour l'énergie */
    public function resource(): ?string
    {
        return match ($this) {
            self::MetalProduction, self::MetalStorage => 'metal',
            self::CrystalProduction, self::CrystalStorage => 'crystal',
            self::DeuteriumProduction, self::DeuteriumStorage => 'deuterium',
            self::SolarEnergy, self::FusionEnergy, self::RobotFactory, self::NaniteFactory => null,
        };
    }

    public function isProduction(): bool
    {
        return \in_array($this, [self::MetalProduction, self::CrystalProduction, self::DeuteriumProduction], true);
    }

    public function isEnergy(): bool
    {
        return self::SolarEnergy === $this || self::FusionEnergy === $this;
    }

    public function isStorage(): bool
    {
        return \in_array($this, [self::MetalStorage, self::CrystalStorage, self::DeuteriumStorage], true);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->label();
    }
}
