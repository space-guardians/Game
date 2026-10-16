<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Entity\Empire;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Entity\Technology;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\UserFactory;
use App\Repository\ScheduledEventRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Parcours critiques dans un vrai navigateur (§5.4) : Chromium piloté par Panther, sur le serveur web intégré de PHP
 * en environnement « panther » (base à part, recréée par make test-e2e, sans transaction annulée).
 *
 * Le serveur suit l'heure réelle ; il n'y a pas de worker. Pour faire avancer le jeu, un test résout lui-même
 * l'événement planifié qu'il attend (resolveNext), en avançant l'horloge du processus PHPUnit jusqu'à son échéance,
 * puis recharge la page.
 */
abstract class E2eTestCase extends PantherTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected Client $browser;

    private int $lastEventBefore;

    protected function setUp(): void
    {
        // « localhost », comme l'URL publique du hub Mercure : le cookie d'abonnement n'est posé que sur son domaine
        $this->browser = static::createPantherClient(['hostname' => 'localhost']);
        // Un seul navigateur pour la suite : chaque parcours repart sans session
        $this->browser->getCookieJar()->clear();
        // La base garde les données des parcours précédents : resolveUntil s'en tient aux événements de celui-ci
        $this->lastEventBefore = (int) self::getContainer()->get(ScheduledEventRepository::class)->createQueryBuilder('e')
            ->select('MAX(e.id)')->getQuery()->getSingleScalarResult();
    }

    /** Connexion par le formulaire, comme un joueur */
    protected function logIn(Empire $empire): void
    {
        $this->browser->request('GET', '/connexion');
        $this->browser->submitForm('Reprendre le commandement', [
            'email' => $empire->getUser()->getUserIdentifier(),
            'password' => UserFactory::DEFAULT_PASSWORD,
        ]);
        $this->browser->waitFor('.sg-topbar');
    }

    /**
     * Fait avancer le jeu comme le worker : résout, dans l'ordre de leurs échéances, les événements planifiés pendant ce
     * test, jusqu'à ce qu'il ne reste plus d'événement de ce type en attente. Un événement en échec fait échouer le test.
     */
    protected function resolveUntil(string $type): void
    {
        for ($round = 0; $round < 10; ++$round) {
            $next = $this->pendingEvents()->setMaxResults(1)->getQuery()->getOneOrNullResult();
            self::assertInstanceOf(ScheduledEvent::class, $next, \sprintf('Aucun événement en attente avant « %s ».', $type));
            self::mockTime($next->getDueAt());

            self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $next->getId());

            $this->entityManager()->clear();
            $resolved = $this->entityManager()->find(ScheduledEvent::class, $next->getId());
            self::assertSame(ScheduledEventStatus::Done, $resolved?->getStatus(), \sprintf('%s : %s', $next->getType(), $resolved?->getError()));
            if (0 === (int) $this->pendingEvents()->select('COUNT(e.id)')->resetDQLPart('orderBy')->andWhere('e.type = :type')->setParameter('type', $type)->getQuery()->getSingleScalarResult()) {
                return;
            }
        }
        self::fail(\sprintf('« %s » toujours en attente après %d résolutions.', $type, $round));
    }

    /** Événements en attente planifiés pendant ce test, plus anciens d'abord */
    private function pendingEvents(): QueryBuilder
    {
        $this->entityManager()->clear();

        return self::getContainer()->get(ScheduledEventRepository::class)->createQueryBuilder('e')
            ->where('e.status = :pending')
            ->andWhere('e.id > :since')
            ->setParameter('pending', ScheduledEventStatus::Pending)
            ->setParameter('since', $this->lastEventBefore)
            ->orderBy('e.dueAt')
            ->addOrderBy('e.id');
    }

    protected function ship(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        self::assertInstanceOf(ShipType::class, $type);

        return $type;
    }

    protected function technology(string $code): Technology
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        self::assertInstanceOf(Technology::class, $technology);

        return $technology;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
