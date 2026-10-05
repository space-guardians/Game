<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum\Admin;

use App\Enum\Admin\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminRoleTest extends TestCase
{
    public function testEachRoleIncludesLowerRolesOnly(): void
    {
        self::assertTrue(AdminRole::SuperAdmin->includes(AdminRole::Moderator));
        self::assertTrue(AdminRole::Admin->includes(AdminRole::GameDesigner));
        self::assertTrue(AdminRole::GameDesigner->includes(AdminRole::GameDesigner));
        self::assertFalse(AdminRole::Moderator->includes(AdminRole::GameDesigner));
        self::assertFalse(AdminRole::Admin->includes(AdminRole::SuperAdmin));
    }

    public function testHasFrenchLabelsAndListsAcceptedValues(): void
    {
        self::assertSame('Game design', AdminRole::GameDesigner->label());
        self::assertSame('ROLE_MODERATOR, ROLE_GAME_DESIGNER, ROLE_ADMIN, ROLE_SUPER_ADMIN', AdminRole::valuesList());
    }
}
