<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\AdminUser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Comptes d'administration, tous avec le mot de passe DEFAULT_PASSWORD (haché comme en production).
 *
 * @extends PersistentObjectFactory<AdminUser>
 */
final class AdminUserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'Balise-Sentinelle-Orion-42';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return AdminUser::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'role' => 'ROLE_ADMIN',
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'password' => $this->passwordHasherFactory->getPasswordHasher(AdminUser::class)->hash(self::DEFAULT_PASSWORD),
        ];
    }
}
