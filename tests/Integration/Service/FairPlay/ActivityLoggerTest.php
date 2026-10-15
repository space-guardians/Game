<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\FairPlay;

use App\Enum\FairPlay\ActivityAction;
use App\Exception\Fleet\InvalidFleetMission;
use App\Exception\Messaging\InvalidMessage;
use App\Factory\EmpireFactory;
use App\Factory\UserFactory;
use App\Repository\ActivityLogRepository;
use App\Service\FairPlay\ActionLimits;
use App\Service\FairPlay\ActivityLogger;
use App\Service\Messaging\Messaging;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Zenstruck\Foundry\Test\Factories;

/**
 * Anti-abus (§4.13) : journal d'activité avec empreinte de l'adresse, limite d'inscriptions par adresse, rythme des
 * messages et des envois de flotte.
 */
final class ActivityLoggerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-15 18:00:00');
    }

    public function testAddressesAreHashedAndRegistrationsLimitedPerAddress(): void
    {
        $this->fromAddress('203.0.113.7');
        $logger = self::getContainer()->get(ActivityLogger::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        foreach (range(1, ActivityLogger::MAX_ACCOUNTS_PER_ADDRESS) as $ignored) {
            self::assertNull($logger->registrationRefusal());
            $logger->record(UserFactory::createOne(), ActivityAction::Registration);
            $entityManager->flush();
        }

        self::assertSame('Trop de comptes ont déjà été créés depuis cette connexion : un seul compte par joueur (règles de fair-play).', $logger->registrationRefusal());
        $log = self::getContainer()->get(ActivityLogRepository::class)->findOneBy([]);
        self::assertNotNull($log);
        self::assertSame($logger->hash('203.0.113.7'), $log->getIpHash());
        self::assertStringNotContainsString('203.0.113.7', (string) $log->getIpHash());

        // Une autre adresse n'est pas concernée
        $this->fromAddress('198.51.100.1');
        self::assertNull($logger->registrationRefusal());
    }

    public function testMessagesAreRateLimitedAndLogged(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        EmpireFactory::createOne(['name' => 'Bob']);
        $messaging = self::getContainer()->get(Messaging::class);
        $conversation = $messaging->start($ana, ['Bob'], null, 'Bonjour');
        for ($i = 2; $i <= 20; ++$i) {
            $messaging->reply($conversation, $ana, 'Message ' . $i);
        }

        $this->expectExceptionObject(new InvalidMessage('Trop de messages en peu de temps : patientez quelques minutes.'));
        try {
            $messaging->reply($conversation, $ana, 'Un de trop');
        } finally {
            self::assertCount(20, self::getContainer()->get(ActivityLogRepository::class)->findBy(['user' => $ana->getUser(), 'action' => ActivityAction::MessageSent]));
        }
    }

    public function testFleetDispatchesAreRateLimited(): void
    {
        $empire = EmpireFactory::createOne();
        $limits = self::getContainer()->get(ActionLimits::class);
        foreach (range(1, 30) as $ignored) {
            $limits->fleetDispatch($empire);
        }

        $this->expectExceptionObject(new InvalidFleetMission('Trop d’envois de flotte en peu de temps : patientez quelques minutes.'));
        $limits->fleetDispatch($empire);
    }

    private function fromAddress(string $ip): void
    {
        $requests = self::getContainer()->get(RequestStack::class);
        while (null !== $requests->pop()) {
        }
        $requests->push(Request::create('/', server: ['REMOTE_ADDR' => $ip]));
    }
}
