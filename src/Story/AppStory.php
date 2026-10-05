<?php

declare(strict_types=1);

namespace App\Story;

use App\Factory\GalaxyShapeTemplateFactory;
use App\Service\Universe\GalaxyGenerator;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\Persistence\flush_after;
use function Zenstruck\Foundry\Persistence\save;

/**
 * Données de développement (make fixtures) : une galaxie générée avec une graine fixe, identique
 * d'un chargement à l'autre (même résultat que « app:galaxy:generate --seed=1 »).
 */
#[AsFixture(name: 'main')]
final class AppStory extends Story
{
    private const int SEED = 1;

    public function __construct(
        private readonly GalaxyGenerator $galaxyGenerator,
    ) {}

    public function build(): void
    {
        // Gabarits de forme d'exemple ; le premier reprend la forme par défaut, utilisée pour la galaxie
        GalaxyShapeTemplateFactory::createOne(['name' => 'Spirale classique (4 branches)']);
        GalaxyShapeTemplateFactory::createOne(['name' => 'Spirale à deux bras', 'arms' => 2, 'armTightness' => 3.5, 'armWidth' => 0.3]);
        GalaxyShapeTemplateFactory::createOne(['name' => 'Spirale floconneuse (6 branches)', 'arms' => 6, 'armWidth' => 0.4, 'interArmDensity' => 0.12]);

        $galaxy = $this->galaxyGenerator->generate(1, 'Voie des Gardiens', self::SEED);

        flush_after(static function () use ($galaxy): void {
            save($galaxy);
            foreach ($galaxy->getSystems() as $system) {
                save($system);
                foreach ($system->getPlanets() as $planet) {
                    save($planet);
                }
            }
        });
    }
}
