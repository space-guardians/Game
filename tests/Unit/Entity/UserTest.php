<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testNormalizesEmailCaseAndSpaces(): void
    {
        $user = new User('  Gardien@Exemple.FR ', new \DateTimeImmutable('2026-10-01'));

        self::assertSame('gardien@exemple.fr', $user->getEmail());
        self::assertSame('gardien@exemple.fr', $user->getUserIdentifier());
    }

    public function testEveryPlayerHasUserRole(): void
    {
        self::assertSame(['ROLE_USER'], (new User('gardien@exemple.fr', new \DateTimeImmutable()))->getRoles());
    }

    public function testDoesNotStorePasswordHashInSession(): void
    {
        $user = new User('gardien@exemple.fr', new \DateTimeImmutable());
        $user->setPassword('$2y$13$hachage-complet-du-mot-de-passe');

        self::assertStringNotContainsString('hachage-complet-du-mot-de-passe', serialize($user));
    }
}
