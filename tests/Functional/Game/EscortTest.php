<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\PlayerQuest;
use App\Entity\ShipType;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Repository\PlayerQuestRepository;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Escortes » (§4.15) : publication d'un contrat, tableau public (départ seulement), engagement d'une escorte.
 */
final class EscortTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-17 10:00:00');
    }

    public function testContractIsPublishedThenEscortedWithoutRevealingTheDestinationToOthers(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $ana->getHomePlanet()->storeResources(new Resources(10_000, 10_000, 10_000), new \DateTimeImmutable('2026-10-17 10:00:00'));
        $colony = $this->colonyOf($ana);
        $this->fleet($ana, $ana->getHomePlanet(), 'Convoi Ana');
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $this->fleet($bob, $ana->getHomePlanet(), 'Garde de Bob');
        $this->client->loginUser($ana->getUser());

        $crawler = $this->client->request('GET', '/escortes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Escortes');
        $address = $colony->getAddress();
        $this->client->submit($crawler->filter('#publier-contrat')->form([
            'galaxy' => (string) $address->galaxy,
            'system' => (string) $address->system,
            'position' => (string) $address->position,
            'action' => 'transport',
            'departs_at' => '2026-10-17T11:30',
            'reward[metal]' => '1200',
        ]));
        self::assertResponseRedirects('/escortes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Contrat publié : départ de');
        self::assertSelectorTextContains('#mes-contrats', (string) $address);

        // Un autre joueur voit le départ, pas la destination, et engage la flotte stationnée au départ
        $this->client->loginUser($this->reload($bob)->getUser());
        $crawler = $this->client->request('GET', '/escortes');
        $quest = self::getContainer()->get(PlayerQuestRepository::class)->findOneBy([]);
        \assert($quest instanceof PlayerQuest);
        $row = $crawler->filter(\sprintf('#convoi-%d', $quest->getId()));
        self::assertStringContainsString('Ana', $row->text());
        self::assertStringContainsString('17/10 à 11:30', $row->text());
        self::assertMatchesRegularExpression('/1\s?200 métal/u', $row->text());
        self::assertStringNotContainsString((string) $address, $crawler->filter('#convois')->text());
        $this->client->submit($row->selectButton('Escorter')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', \sprintf('Escorte engagée : destination %s', $address));
        self::assertSelectorTextContains('#mes-contrats', 'Escorte du convoi de Ana');
    }

    private function colonyOf(Empire $empire): Planet
    {
        $home = $empire->getHomePlanet()->getSystem();
        $colony = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 1500, $home->getPosition()->y),
        ])]);
        $colony->assignTo($empire);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    private function fleet(Empire $empire, Planet $at, string $name): Fleet
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('small_cargo');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, $name, $at, new \DateTimeImmutable('2026-10-17 10:00:00'));
        $fleet->addShips($type, 2);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function reload(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }
}
