<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\AdminAudit;
use App\Admin\AdminRole;
use App\Admin\AuditAction;
use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\Galaxy;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Repository\AdminAuditLogRepository;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

final class AdminAuditTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-02 21:00:00');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testWritesWithoutAdminAreNotLogged(): void
    {
        $this->entityManager->persist(new Galaxy(7, 'Orion'));
        $this->entityManager->flush();

        self::assertSame(0, $this->logs()->count([]));
    }

    public function testLogsCreationWithAuthorAndValues(): void
    {
        $admin = $this->loginAs('createur@space-guardians.local');
        $galaxy = new Galaxy(7, 'Orion');

        $this->entityManager->persist($galaxy);
        $this->entityManager->flush();

        $entry = $this->onlyEntryFor($galaxy);
        self::assertSame(AuditAction::Create, $entry->getAction());
        self::assertSame($admin->getId(), $entry->getActorId());
        self::assertSame('createur@space-guardians.local', $entry->getActorEmail());
        self::assertSame('Galaxie 7 — Orion', $entry->getSubjectLabel());
        self::assertEquals(new \DateTimeImmutable('2026-10-02 21:00:00'), $entry->getOccurredAt());
        self::assertSame([null, 'Orion'], $entry->getChanges()['name']);
        self::assertSame([null, 7], $entry->getChanges()['number']);
    }

    public function testLogsOnlyChangedFieldsOnUpdate(): void
    {
        $galaxy = GalaxyFactory::createOne(['name' => 'Orion']);
        $this->loginAs();

        $galaxy->setName('Andromède');
        $this->entityManager->flush();

        $entry = $this->onlyEntryFor($galaxy);
        self::assertSame(AuditAction::Update, $entry->getAction());
        self::assertSame(['name' => ['Orion', 'Andromède']], $entry->getChanges());
    }

    public function testKeepsValuesOfDeletedEntity(): void
    {
        $galaxy = GalaxyFactory::createOne(['number' => 3, 'name' => 'Orion']);
        $id = (string) $galaxy->getId();
        $this->loginAs();

        $this->entityManager->remove($galaxy);
        $this->entityManager->flush();

        $entries = $this->logs()->findBySubject('Galaxy', $id);
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Delete, $entries[0]->getAction());
        self::assertSame('Galaxie 3 — Orion', $entries[0]->getSubjectLabel());
        self::assertSame(['Orion', null], $entries[0]->getChanges()['name']);
    }

    public function testNeverCopiesSecrets(): void
    {
        $other = AdminUserFactory::createOne(['role' => AdminRole::Moderator]);
        $this->loginAs();

        $other->resetTwoFactor();
        $other->setPassword('$2y$13$nouvelle-empreinte');
        $this->entityManager->flush();

        $changes = $this->onlyEntryFor($other)->getChanges();
        self::assertSame(['••••••', null], $changes['totpSecret']);
        self::assertSame(['••••••', '••••••'], $changes['password']);
        self::assertSame([true, false], $changes['totpConfirmed']);
    }

    public function testRecordsActionOfBackgroundTaskForGivenAuthor(): void
    {
        $admin = AdminUserFactory::createOne(['email' => 'designer@space-guardians.local']);
        $galaxy = GalaxyFactory::createOne();
        $audit = self::getContainer()->get(AdminAudit::class);

        $entry = $audit->record(AuditAction::Generate, $galaxy, ['systems' => [null, 1000]], $admin);
        $anonymous = $audit->record(AuditAction::Generate, $galaxy);

        self::assertSame('designer@space-guardians.local', $entry->getActorEmail());
        self::assertSame(['systems' => [null, 1000]], $entry->getChanges());
        self::assertSame('système', $anonymous->getActorEmail());
        self::assertNull($anonymous->getActorId());
    }

    public function testDatabaseRefusesToAlterEntries(): void
    {
        $entry = self::getContainer()->get(AdminAudit::class)->record(AuditAction::Sanction, GalaxyFactory::createOne());

        $this->expectException(DbalException::class);
        $this->expectExceptionMessage('Le journal d\'audit n\'est pas modifiable.');

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE admin_audit_log SET actor_email = ? WHERE id = ?',
            ['quelquun@ailleurs.example', $entry->getId()],
        );
    }

    private function loginAs(string $email = 'admin@space-guardians.local'): AdminUser
    {
        $admin = AdminUserFactory::createOne(['email' => $email]);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
        // La création du compte lui-même n'a pas été faite par un administrateur connecté
        self::assertSame(0, $this->logs()->count([]));

        return $admin;
    }

    private function onlyEntryFor(object $subject): AdminAuditLog
    {
        $metadata = $this->entityManager->getClassMetadata($subject::class);
        $entries = $this->logs()->findBySubject($metadata->getReflectionClass()->getShortName(), (string) $metadata->getIdentifierValues($subject)['id']);
        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function logs(): AdminAuditLogRepository
    {
        return self::getContainer()->get(AdminAuditLogRepository::class);
    }
}
