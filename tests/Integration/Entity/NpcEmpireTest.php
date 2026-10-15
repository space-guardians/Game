<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\Empire;
use App\Entity\NpcBehaviorProfile;
use App\Enum\Pve\NpcCategory;
use App\Factory\PlanetFactory;
use App\Repository\EmpireRepository;
use App\Repository\NpcBehaviorProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Civilisations non-joueuses (§4.14) : empires sans compte, rattachés à un profil de comportement installé comme
 * contenu de départ.
 */
final class NpcEmpireTest extends KernelTestCase
{
    use Factories;

    public function testANpcEmpireIsStoredWithoutAccount(): void
    {
        self::bootKernel();
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider');
        self::assertNotNull($profile);
        self::assertSame(NpcCategory::Minor, $profile->getCategory());
        self::assertTrue($profile->isRaidsEnabled());

        $npc = Empire::npc('Pillards', PlanetFactory::createOne(), $profile, new \DateTimeImmutable('2026-10-15 10:00'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($npc);
        $entityManager->flush();
        $entityManager->clear();

        $stored = self::getContainer()->get(EmpireRepository::class)->find($npc->getId());
        self::assertNotNull($stored);
        self::assertTrue($stored->isNpc());
        self::assertSame('minor_raider', $stored->getNpcProfile()?->getCode());
    }

    public function testABossNeverRaids(): void
    {
        self::bootKernel();
        $boss = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        self::assertInstanceOf(NpcBehaviorProfile::class, $boss);
        self::assertFalse($boss->isRaidsEnabled());

        $boss->setRaidsEnabled(true);
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($boss);

        self::assertCount(1, $violations);
        self::assertSame('Une civilisation « boss » est strictement défensive : pas de raids.', $violations[0]?->getMessage());
    }
}
