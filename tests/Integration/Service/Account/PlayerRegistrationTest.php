<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Account;

use App\Entity\Empire;
use App\Enum\Account\StartingOrientation;
use App\Exception\Account\EmpireNameTaken;
use App\Exception\Universe\NoFreePlanet;
use App\Factory\UserFactory;
use App\Model\Account\Registration;
use App\Model\Economy\Resources;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\EmpireRepository;
use App\Service\Account\PlayerRegistration;
use App\Service\Universe\GalaxyCreator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inscription d'un joueur : empire et planète mère placée selon l'orientation (§2.4, §4.1).
 */
final class PlayerRegistrationTest extends KernelTestCase
{
    use Factories;

    public function testAggressiveEmpiresStartCloserToCenterThanProducers(): void
    {
        self::bootKernel();
        $this->generateGalaxy(200);

        $aggressive = $producer = [];
        for ($i = 1; $i <= 6; ++$i) {
            $aggressive[] = $this->distance($this->register("agressif$i", StartingOrientation::Aggressive));
            $producer[] = $this->distance($this->register("producteur$i", StartingOrientation::Producer));
        }

        self::assertLessThan(min($producer), max($aggressive));
    }

    public function testHomePlanetStartsWithStartingResources(): void
    {
        self::bootKernel();
        $this->generateGalaxy(20);

        $planet = $this->register('orion', StartingOrientation::Producer)->getHomePlanet();

        self::assertEquals(new Resources(500, 500, 0), $planet->getResources());
        self::assertNotNull($planet->getResourcesUpdatedAt());
    }

    public function testNoTwoEmpiresShareAPlanetUntilGalaxyIsFull(): void
    {
        self::bootKernel();
        $this->generateGalaxy(10);
        $planets = [];

        try {
            for ($i = 1; $i <= 500; ++$i) {
                $planets[] = $this->register("gardien$i", StartingOrientation::Aggressive)->getHomePlanet()->getId();
            }
            self::fail('La galaxie aurait dû finir par être pleine.');
        } catch (NoFreePlanet) {
            self::assertSame($planets, array_unique($planets));
            self::assertGreaterThanOrEqual(30, \count($planets), 'Une galaxie de 10 systèmes compte au moins 3 planètes par système');
        }
    }

    public function testRefusesTakenNameWithoutCreatingAccount(): void
    {
        self::bootKernel();
        $this->generateGalaxy(20);
        $this->register('orion', StartingOrientation::Producer, 'Ordre d’Orion');

        try {
            $this->register('copie', StartingOrientation::Producer, 'ordre  d’orion');
            self::fail('Le nom aurait dû être refusé.');
        } catch (EmpireNameTaken) {
            self::assertSame(1, self::getContainer()->get(EmpireRepository::class)->count([]));
            self::assertNull(UserFactory::repository()->findOneBy(['email' => 'copie@exemple.fr']));
        }
    }

    private function register(string $login, StartingOrientation $orientation, ?string $empireName = null): Empire
    {
        $registration = new Registration();
        $registration->email = $login . '@exemple.fr';
        $registration->plainPassword = UserFactory::DEFAULT_PASSWORD;
        $registration->empireName = $empireName ?? 'Empire ' . $login;
        $registration->orientation = $orientation;
        $registration->acceptRules = true;

        $user = self::getContainer()->get(PlayerRegistration::class)->register($registration);
        $empire = self::getContainer()->get(EmpireRepository::class)->findOneByUser($user);
        \assert($empire instanceof Empire);

        return $empire;
    }

    private function distance(Empire $empire): float
    {
        return $empire->getHomePlanet()->getSystem()->getPosition()->distanceFromCenter();
    }

    private function generateGalaxy(int $systems): void
    {
        self::getContainer()->get(GalaxyCreator::class)->create(1, 'Voie des Gardiens', 7, new SpiralGalaxyShape(), $systems);
    }
}
