<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Universe\SpiralGalaxyShape;
use App\Service\Universe\GalaxyPreview;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Illustration galactique des écrans d'accueil (inscription, connexion) : une galaxie spirale produite par
 * l'algorithme de génération du jeu, avec une graine fixe, et mise en cache.
 */
#[AsTwigComponent]
final class GalaxyBackdrop
{
    private const int SYSTEMS = 700;
    private const int SEED = 7;

    public function __construct(
        private readonly GalaxyPreview $galaxyPreview,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * @return list<array{x: float, y: float, r: float, color: string}>
     */
    public function getStars(): array
    {
        return $this->cache->get(
            'galaxy_backdrop_' . self::SEED,
            fn(): array => $this->galaxyPreview->stars(new SpiralGalaxyShape(), self::SYSTEMS, self::SEED),
        );
    }
}
