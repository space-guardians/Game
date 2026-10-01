<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Admin\AdminRole;
use App\Entity\AdminUser;
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

        $admin->changeRole(AdminRole::SuperAdmin);

        self::assertSame(['ROLE_SUPER_ADMIN'], $admin->getRoles());
    }

    public function testDoesNotStorePasswordHashInSession(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', AdminRole::Admin, new \DateTimeImmutable());
        $admin->setPassword('$2y$13$hachage-complet-du-mot-de-passe');

        self::assertStringNotContainsString('hachage-complet-du-mot-de-passe', serialize($admin));
    }
}
