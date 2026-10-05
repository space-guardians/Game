<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\AdminUser;
use App\Enum\Admin\AdminRole;
use OTPHP\TOTP;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Comptes d'administration, tous avec le mot de passe DEFAULT_PASSWORD (haché comme en production) et la double
 * authentification activée avec le secret TOTP_SECRET : totpCode() donne le code attendu à un instant donné.
 *
 * @extends PersistentObjectFactory<AdminUser>
 */
final class AdminUserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'Balise-Sentinelle-Orion-42';
    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function __construct(
        private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return AdminUser::class;
    }

    public static function totpCode(\DateTimeImmutable $at): string
    {
        return TOTP::createFromSecret(self::TOTP_SECRET)->at($at->getTimestamp());
    }

    /** Compte qui n'a pas encore activé sa double authentification */
    public function withoutTwoFactor(): static
    {
        return $this->afterInstantiate(static fn(AdminUser $admin) => $admin->resetTwoFactor());
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'role' => AdminRole::Admin,
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeThisYear()),
            'password' => $this->passwordHasherFactory->getPasswordHasher(AdminUser::class)->hash(self::DEFAULT_PASSWORD),
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (AdminUser $admin): void {
            $admin->startTotpEnrollment(self::TOTP_SECRET);
            $admin->confirmTotp();
        });
    }
}
