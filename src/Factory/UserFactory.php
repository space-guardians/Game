<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Comptes joueurs, tous avec le mot de passe DEFAULT_PASSWORD (haché comme en production).
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'Orion-Sentinelle-2026!';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'registeredAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'password' => $this->passwordHasherFactory->getPasswordHasher(User::class)->hash(self::DEFAULT_PASSWORD),
        ];
    }
}
