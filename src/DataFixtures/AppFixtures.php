<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Story\AppStory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Point d'entrée de « make fixtures » : les données de développement sont décrites par les stories Foundry.
 */
final class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        AppStory::load();
    }
}
