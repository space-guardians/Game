<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alliance;

use App\Entity\Empire;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Factory\EmpireFactory;
use App\Repository\AlliancePactRepository;
use App\Service\Alliance\Pacts;
use App\Service\Combat\Allegiances;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Pactes de non-agression (§4.10.1) : durée négociée, non résiliables, alliés pendant leur durée.
 */
final class PactsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-12 10:00:00');
    }

    public function testConcludedPactMakesAlliesUntilItsTerm(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $pact = $this->pacts()->propose($ana, 'bob', 30);
        // Proposition seule : pas encore alliés
        self::assertFalse($this->allegiances()->allied($ana, $bob));

        $this->pacts()->accept($pact, $bob);
        self::assertTrue($this->allegiances()->allied($ana, $bob));
        self::assertTrue($this->allegiances()->allied($bob, $ana));
        self::assertEquals($this->clock->now()->modify('+30 days'), $pact->getEndsAt());
        self::assertSame([$bob], $this->allegiances()->alliesOf($ana));

        $this->clock->sleep(30 * 86_400);
        self::assertFalse($this->allegiances()->allied($ana, $bob));
        self::assertSame([], self::getContainer()->get(AlliancePactRepository::class)->findOpenFor($ana, $this->clock->now()));
    }

    public function testConcludedPactCannotBeBroken(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $pact = $this->pacts()->propose($ana, 'Bob', 365);
        $this->pacts()->accept($pact, $bob);

        foreach ([$ana, $bob] as $party) {
            try {
                $this->pacts()->dismiss($pact, $party);
                self::fail('Rupture acceptée à tort.');
            } catch (InvalidAllianceAction $exception) {
                self::assertSame('Un pacte conclu ne peut pas être rompu : il court jusqu’à son terme.', $exception->getMessage());
            }
        }
        self::assertTrue($this->allegiances()->allied($ana, $bob));
    }

    public function testProposalRules(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $pact = $this->pacts()->propose($ana, 'Bob', 10);

        foreach ([
            [fn() => $this->pacts()->propose($ana, 'Ana', 10), 'On ne conclut pas de pacte avec soi-même.'],
            [fn() => $this->pacts()->propose($bob, 'Ana', 10), 'Une proposition de pacte avec Ana attend déjà une réponse.'],
            [fn() => $this->pacts()->propose($ana, 'Bob', 366), 'Un pacte dure de 1 à 365 jours.'],
            [fn() => $this->pacts()->accept($pact, $ana), 'Seul l’empire sollicité peut accepter ce pacte.'],
        ] as [$attempt, $error]) {
            try {
                $attempt();
                self::fail('Action acceptée à tort : ' . $error);
            } catch (InvalidAllianceAction $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }

        // Retrait par celui qui propose
        $this->pacts()->dismiss($pact, $ana);
        self::assertSame([], self::getContainer()->get(AlliancePactRepository::class)->findOpenFor($bob, $this->clock->now()));
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(fn(string $name): Empire => EmpireFactory::createOne(['name' => $name, 'foundedAt' => $this->clock->now()]), array_values($names));
    }

    private function pacts(): Pacts
    {
        return self::getContainer()->get(Pacts::class);
    }

    private function allegiances(): Allegiances
    {
        return self::getContainer()->get(Allegiances::class);
    }
}
