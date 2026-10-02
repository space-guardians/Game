<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\AdminAudit;
use App\Admin\AuditAction;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * Journal des actions d'administration (§5.6.2).
 */
final class AdminAuditTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-02 21:00:00');
    }

    public function testRecordsActionOfLoggedInAdmin(): void
    {
        $admin = AdminUserFactory::createOne(['email' => 'admin@space-guardians.local']);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
        $galaxy = GalaxyFactory::createOne(['number' => 2, 'name' => 'Orion']);

        $this->audit()->record(AuditAction::Generate, $galaxy, ['seed' => [null, 42]]);

        $entries = self::getContainer()->get(AdminAuditLogRepository::class)->findBySubject('Galaxy', (string) $galaxy->getId());
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Generate, $entries[0]->getAction());
        self::assertSame($admin->getId(), $entries[0]->getActorId());
        self::assertSame('admin@space-guardians.local', $entries[0]->getActorEmail());
        self::assertSame('Galaxie 2 — Orion', $entries[0]->getSubjectLabel());
        self::assertEquals(new \DateTimeImmutable('2026-10-02 21:00:00'), $entries[0]->getOccurredAt());
        self::assertSame(['seed' => [null, 42]], $entries[0]->getChanges());
    }

    public function testRecordsActionOfBackgroundTaskForGivenAuthor(): void
    {
        $admin = AdminUserFactory::createOne(['email' => 'designer@space-guardians.local']);
        $galaxy = GalaxyFactory::createOne();

        $entry = $this->audit()->record(AuditAction::Generate, $galaxy, ['systems' => [null, 1000]], $admin);
        $anonymous = $this->audit()->record(AuditAction::Generate, $galaxy);

        self::assertSame('designer@space-guardians.local', $entry->getActorEmail());
        self::assertSame(['systems' => [null, 1000]], $entry->getChanges());
        self::assertSame('système', $anonymous->getActorEmail());
        self::assertNull($anonymous->getActorId());
    }

    public function testNeverCopiesSecrets(): void
    {
        $entry = $this->audit()->record(AuditAction::Sanction, GalaxyFactory::createOne(), [
            'password' => ['ancien', 'nouveau'],
            'totpSecret' => ['SECRET', null],
        ]);

        self::assertSame(['password' => ['••••••', '••••••'], 'totpSecret' => ['••••••', null]], $entry->getChanges());
    }

    public function testDatabaseRefusesToAlterEntries(): void
    {
        $entry = $this->audit()->record(AuditAction::Sanction, GalaxyFactory::createOne());

        $this->expectException(DbalException::class);
        $this->expectExceptionMessage('Le journal d\'audit n\'est pas modifiable.');

        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE admin_audit_log SET actor_email = ? WHERE id = ?',
            ['quelquun@ailleurs.example', $entry->getId()],
        );
    }

    private function audit(): AdminAudit
    {
        return self::getContainer()->get(AdminAudit::class);
    }
}
