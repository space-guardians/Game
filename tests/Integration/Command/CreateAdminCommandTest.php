<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\AdminUser;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Repository\AdminUserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

final class CreateAdminCommandTest extends KernelTestCase
{
    use Factories;

    private const string PASSWORD = 'Balise-Sentinelle-Orion-42';

    public function testCreatesSuperAdminByDefaultWithHashedPassword(): void
    {
        $tester = $this->execute(['email' => 'Admin@Space-Guardians.local'], [self::PASSWORD, self::PASSWORD]);

        $tester->assertCommandIsSuccessful();
        $admin = $this->admin('admin@space-guardians.local');
        self::assertSame(AdminRole::SuperAdmin, $admin->getRole());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($admin, self::PASSWORD));
    }

    public function testCreatesAccountWithRequestedRole(): void
    {
        $this->execute(['email' => 'moderation@space-guardians.local', '--role' => 'ROLE_MODERATOR'], [self::PASSWORD, self::PASSWORD])
            ->assertCommandIsSuccessful();

        self::assertSame(AdminRole::Moderator, $this->admin('moderation@space-guardians.local')->getRole());
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'confirmation différente' => [['email' => 'a@space-guardians.local'], [self::PASSWORD, 'autre-chose-encore'], 'ne correspondent pas'];
        yield 'mot de passe trop court' => [['email' => 'a@space-guardians.local'], ['court', 'court'], 'au moins 12 caractères'];
        yield 'mot de passe trop simple' => [['email' => 'a@space-guardians.local'], ['aaaaaaaaaaaa', 'aaaaaaaaaaaa'], 'trop facile à deviner'];
        yield 'adresse invalide' => [['email' => 'pas-une-adresse'], [self::PASSWORD, self::PASSWORD], 'pas une adresse e-mail valide'];
        yield 'rôle inconnu' => [['email' => 'a@space-guardians.local', '--role' => 'ROLE_ROI'], [self::PASSWORD, self::PASSWORD], 'Rôle d\'administration inconnu'];
    }

    /**
     * @param array<string, string> $arguments
     * @param list<string>          $inputs
     */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequest(array $arguments, array $inputs, string $message): void
    {
        $tester = $this->execute($arguments, $inputs);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(0, self::getContainer()->get(AdminUserRepository::class)->count([]));
    }

    public function testRefusesExistingAccount(): void
    {
        AdminUserFactory::createOne(['email' => 'admin@space-guardians.local']);

        $tester = $this->execute(['email' => 'admin@space-guardians.local'], [self::PASSWORD, self::PASSWORD]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $arguments
     * @param list<string>          $inputs
     */
    private function execute(array $arguments, array $inputs): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:admin:create'));
        $tester->setInputs($inputs);
        $tester->execute($arguments);

        return $tester;
    }

    private function admin(string $email): AdminUser
    {
        $admin = self::getContainer()->get(AdminUserRepository::class)->findOneByEmail($email);
        self::assertInstanceOf(AdminUser::class, $admin);

        return $admin;
    }
}
