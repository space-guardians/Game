<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BattleRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Renforts vers une bataille programmée (§4.7) depuis l'écran « Flotte ».
 */
final class BattleTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testDefenderSeesTheBattleAndSendsReinforcements(): void
    {
        [$defender, $battle, $reserve] = $this->battleOnADefendedPlanet();
        $this->client->loginUser($defender->getUser());

        $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains('#batailles', \sprintf('Bataille en %s', $battle->getLocationLabel()));
        $this->client->clickLink('Envoyer « Réserve » en renfort');

        self::assertSelectorTextContains('#ordre-renfort', 'Rejoindre une bataille');
        $this->client->submitForm('Envoyer la flotte', ['steps[2][action]' => '']);

        self::assertResponseRedirects('/flotte');
        $battle = self::getContainer()->get(BattleRepository::class)->find($battle->getId());
        $reserve = self::getContainer()->get(EntityManagerInterface::class)->find(Fleet::class, $reserve->getId());
        \assert($reserve instanceof Fleet);
        // Hostile à l'empire qui attaque : il se range côté défense, sans l'avoir choisi
        self::assertSame(CombatSide::Defender, $battle?->participantFor($reserve)?->getSide());
    }

    public function testReinforcementArrivingTooLateIsRefused(): void
    {
        [$defender, $battle, $reserve] = $this->battleOnADefendedPlanet();
        $this->client->loginUser($defender->getUser());

        $this->client->request('GET', \sprintf('/flotte/%d/envoyer?bataille=%d', $reserve->getId(), $battle->getId()));
        // À 10 % de vitesse, la réserve arriverait bien après la bataille
        $this->client->submitForm('Envoyer la flotte', ['steps[2][action]' => '', 'speed' => '10']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-alert', 'la flotte arriverait après la bataille');
    }

    /**
     * Un empire attaque une planète où stationne la flotte d'un autre ; celui-ci a une réserve stationnée non loin.
     *
     * @return array{Empire, Battle, Fleet}
     */
    private function battleOnADefendedPlanet(): array
    {
        $attacker = EmpireFactory::createOne();
        $attacker->getHomePlanet()->storeResources(new Resources(0, 0, 20_000), new \DateTimeImmutable());
        $home = $attacker->getHomePlanet()->getSystem();
        $target = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ])]);
        $defender = EmpireFactory::createOne();
        $this->fleet($defender, $target, 'Garde');
        // Réserve du défenseur, stationnée au système de l'assaillant (même distance de la cible)
        $reserve = $this->fleet($defender, $attacker->getHomePlanet(), 'Réserve');
        $reserve->refuel($reserve->tankCapacity());
        $raid = $this->fleet($attacker, $attacker->getHomePlanet(), 'Raid');
        // L'assaillant part plus lentement : la réserve, à pleine vitesse, arrive à temps
        self::getContainer()->get(FleetDispatch::class)->dispatch($raid, [new MissionStep(SpacePosition::planet($target), FleetAction::JoinBattle, (string) $target->getAddress())], 50, new Resources(), $raid->tankCapacity());
        $battle = self::getContainer()->get(BattleRepository::class)->findScheduledFor($raid);
        \assert($battle instanceof Battle);

        return [$defender, $battle, $reserve];
    }

    private function fleet(Empire $empire, Planet $at, string $name): Fleet
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('cruiser');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, $name, $at, new \DateTimeImmutable());
        $fleet->addShips($type, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }
}
