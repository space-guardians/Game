<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AdminUser;
use App\Enum\Admin\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminUserTest extends TestCase
{
    public function testHasSingleSymfonyRole(): void
    {
        $admin = new AdminUser(' Admin@Space-Guardians.local', AdminRole::GameDesigner, new \DateTimeImmutable());

        self::assertSame('admin@space-guardians.local', $admin->getUserIdentifier());
        self::assertSame(AdminRole::GameDesigner, $admin->getRole());
        self::assertSame(['ROLE_GAME_DESIGNER'], $admin->getRoles());
    }

    public function testChangesRole(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', AdminRole::Moderator, new \DateTimeImmutable());

        $admin->setRole(AdminRole::SuperAdmin);

        self::assertSame(['ROLE_SUPER_ADMIN'], $admin->getRoles());
    }

    public function testDoesNotStorePasswordHashInSession(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', AdminRole::Admin, new \DateTimeImmutable());
        $admin->setPassword('$2y$13$hachage-complet-du-mot-de-passe');

        self::assertStringNotContainsString('hachage-complet-du-mot-de-passe', serialize($admin));
    }

    public function testTwoFactorIsEnabledOnlyOnceConfirmed(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', AdminRole::Admin, new \DateTimeImmutable());
        self::assertFalse($admin->isTotpAuthenticationEnabled());
        self::assertNull($admin->getTotpAuthenticationConfiguration());

        $admin->startTotpEnrollment('JBSWY3DPEHPK3PXP');
        self::assertTrue($admin->hasPendingTotpSecret());
        self::assertFalse($admin->isTotpAuthenticationEnabled());
        self::assertSame('JBSWY3DPEHPK3PXP', $admin->getTotpAuthenticationConfiguration()?->getSecret());

        $admin->confirmTotp();
        self::assertTrue($admin->isTotpAuthenticationEnabled());
        self::assertFalse($admin->hasPendingTotpSecret());

        $admin->resetTwoFactor();
        self::assertFalse($admin->isTotpAuthenticationEnabled());
        self::assertNull($admin->getTotpAuthenticationConfiguration());
    }

    public function testDoesNotStoreTotpSecretNorTypedPasswordInSession(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', AdminRole::Admin, new \DateTimeImmutable());
        $admin->startTotpEnrollment('JBSWY3DPEHPK3PXP');
        $admin->setPlainPassword('Mot-de-passe-saisi-42');

        $serialized = serialize($admin);

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $serialized);
        self::assertStringNotContainsString('Mot-de-passe-saisi-42', $serialized);
    }
}
