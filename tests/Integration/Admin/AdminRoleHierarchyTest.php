<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\AdminRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * La hiérarchie déclarée dans security.yaml (role_hierarchy) doit suivre exactement l'ordre de l'enum AdminRole.
 */
final class AdminRoleHierarchyTest extends KernelTestCase
{
    public function testSecurityRoleHierarchyMatchesEnumOrder(): void
    {
        $hierarchy = self::getContainer()->get(RoleHierarchyInterface::class);

        foreach (AdminRole::cases() as $role) {
            $reachable = $hierarchy->getReachableRoleNames([$role->value]);

            foreach (AdminRole::cases() as $other) {
                self::assertSame(
                    $role->includes($other),
                    \in_array($other->value, $reachable, true),
                    \sprintf('%s → %s', $role->value, $other->value),
                );
            }
        }
    }
}
