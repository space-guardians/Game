<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alliance;

use App\Entity\Alliance;
use App\Entity\Empire;
use App\Enum\Alliance\AllianceRole;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Factory\EmpireFactory;
use App\Repository\AllianceMemberRepository;
use App\Repository\AllianceRepository;
use App\Service\Alliance\Alliances;
use App\Service\Combat\Allegiances;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Alliances (§4.10) : fondation, invitations, rôles, départs ; alliés entre membres (§4.10.1).
 */
final class AlliancesTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testFoundingInvitingAndJoiningMakesAllies(): void
    {
        [$ana, $bob, $cyd] = $this->empires('Ana', 'Bob', 'Cyd');
        $alliance = $this->service()->found($ana, '  Ligue   Stellaire ', 'lgs', 'Entraide');
        self::assertSame('Ligue Stellaire', $alliance->getName());
        self::assertSame('LGS', $alliance->getTag());
        self::assertSame(AllianceRole::Founder, $alliance->memberOf($ana)?->getRole());

        $invitation = $this->service()->invite($ana, 'bob');
        // Une autre invitation de Bob disparaîtra quand il rejoindra la Ligue
        $other = $this->service()->found($cyd, 'Autre', 'AUT');
        $this->service()->invite($cyd, 'Bob');
        self::assertCount(2, $this->repository()->findInvitationsFor($bob));

        $this->service()->accept($invitation, $bob);

        self::assertSame(AllianceRole::Member, $alliance->memberOf($bob)?->getRole());
        self::assertSame([], $this->repository()->findInvitationsFor($bob));
        self::assertTrue($this->allegiances()->allied($ana, $bob));
        self::assertFalse($this->allegiances()->allied($ana, $cyd));
        self::assertNull($other->memberOf($bob));
    }

    public function testNamesAndTagsAreUniqueAndValidated(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $this->service()->found($ana, 'Ligue', 'LGS');

        foreach ([
            [['Ligue', 'NEW'], 'Ce nom ou ce tag est déjà pris.'],
            [['Nouvelle', 'lgs'], 'Ce nom ou ce tag est déjà pris.'],
            [['No', 'AB'], 'Le nom compte de 3 à 30 caractères.'],
            [['Nouvelle', 'A-B'], 'Le tag compte de 2 à 5 lettres ou chiffres.'],
        ] as [[$name, $tag], $error]) {
            try {
                $this->service()->found($bob, $name, $tag);
                self::fail('Fondation acceptée à tort.');
            } catch (InvalidAllianceAction $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }

        $this->expectExceptionObject(new InvalidAllianceAction('Vous appartenez déjà à une alliance : quittez-la d’abord.'));
        $this->service()->found($ana, 'Seconde', 'SEC');
    }

    public function testRolesGovernWhatMembersCanDo(): void
    {
        [$ana, $bob, $cyd, $dan] = $this->empires('Ana', 'Bob', 'Cyd', 'Dan');
        $alliance = $this->service()->found($ana, 'Ligue', 'LGS');
        $this->join($alliance, $bob);
        $this->join($alliance, $cyd);

        // Un simple membre ne recrute pas ; un diplomate, si
        try {
            $this->service()->invite($bob, 'Dan');
            self::fail('Invitation acceptée à tort.');
        } catch (InvalidAllianceAction $exception) {
            self::assertSame('Seuls le fondateur et les diplomates invitent de nouveaux membres.', $exception->getMessage());
        }
        $bobs = $alliance->memberOf($bob);
        \assert(null !== $bobs);
        $this->service()->setRole($ana, $bobs, AllianceRole::Diplomat);
        self::assertSame('Dan', $this->service()->invite($bob, 'Dan')->getEmpire()->getName());

        // Seul le fondateur gère les membres
        $cyds = $alliance->memberOf($cyd);
        \assert(null !== $cyds);
        try {
            $this->service()->expel($bob, $cyds);
            self::fail('Exclusion acceptée à tort.');
        } catch (InvalidAllianceAction $exception) {
            self::assertSame('Seul le fondateur gère les membres.', $exception->getMessage());
        }
        $this->service()->expel($ana, $cyds);
        self::assertNull($alliance->memberOf($cyd));

        // Transmission : Bob devient fondateur, Ana diplomate
        $this->service()->transferLeadership($ana, $bobs);
        self::assertSame(AllianceRole::Founder, $bobs->getRole());
        self::assertSame(AllianceRole::Diplomat, $alliance->memberOf($ana)?->getRole());
    }

    public function testFounderLeavesOnlyByHandingOverOrDissolving(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $alliance = $this->service()->found($ana, 'Ligue', 'LGS');
        $this->join($alliance, $bob);

        try {
            $this->service()->leave($ana);
            self::fail('Départ du fondateur accepté à tort.');
        } catch (InvalidAllianceAction $exception) {
            self::assertSame('Le fondateur transmet son rôle avant de partir, ou dissout l’alliance.', $exception->getMessage());
        }
        $this->service()->leave($bob);
        self::assertFalse($this->allegiances()->allied($ana, $bob));

        // Seul, le fondateur qui part dissout l'alliance
        $this->service()->leave($ana);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Alliance::class)->count([]));
        self::assertNull(self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($ana));
    }

    public function testDecliningAnInvitation(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $this->service()->found($ana, 'Ligue', 'LGS');
        $invitation = $this->service()->invite($ana, 'Bob');

        $this->service()->decline($invitation, $bob);

        self::assertSame([], $this->repository()->findInvitationsFor($bob));
        self::assertNull(self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($bob));
    }

    private function join(Alliance $alliance, Empire $empire): void
    {
        $founder = $alliance->getFounder()?->getEmpire();
        \assert(null !== $founder);
        $this->service()->accept($this->service()->invite($founder, $empire->getName()), $empire);
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }

    private function service(): Alliances
    {
        return self::getContainer()->get(Alliances::class);
    }

    private function repository(): AllianceRepository
    {
        return self::getContainer()->get(AllianceRepository::class);
    }

    private function allegiances(): Allegiances
    {
        return self::getContainer()->get(Allegiances::class);
    }
}
