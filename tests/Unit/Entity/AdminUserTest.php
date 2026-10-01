<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminUserTest extends TestCase
{
    public function testHasSingleRoleWithLabel(): void
    {
        $admin = new AdminUser(' Admin@Space-Guardians.local', 'ROLE_GAME_DESIGNER', new \DateTimeImmutable());

        self::assertSame('admin@space-guardians.local', $admin->getUserIdentifier());
        self::assertSame(['ROLE_GAME_DESIGNER'], $admin->getRoles());
        self::assertSame('Game design', $admin->getRoleLabel());
    }

    public function testRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminUser('admin@space-guardians.local', 'ROLE_USER', new \DateTimeImmutable());
    }

    public function testDoesNotStorePasswordHashInSession(): void
    {
        $admin = new AdminUser('admin@space-guardians.local', 'ROLE_ADMIN', new \DateTimeImmutable());
        $admin->setPassword('$2y$13$hachage-complet-du-mot-de-passe');

        self::assertStringNotContainsString('hachage-complet-du-mot-de-passe', serialize($admin));
    }
}
