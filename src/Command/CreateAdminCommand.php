<?php

declare(strict_types=1);

namespace App\Command;

use App\Admin\AdminAccounts;
use App\Entity\AdminUser;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée un compte d'administration ; le mot de passe est demandé en saisie masquée, jamais en argument.
 */
#[AsCommand(name: 'app:admin:create', description: 'Crée un compte d\'administration')]
final readonly class CreateAdminCommand
{
    public function __construct(
        private AdminAccounts $adminAccounts,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Adresse e-mail du compte')]
        string $email,
        #[Option('Rôle : ROLE_MODERATOR, ROLE_GAME_DESIGNER, ROLE_ADMIN ou ROLE_SUPER_ADMIN')]
        string $role = 'ROLE_SUPER_ADMIN',
    ): int {
        $password = (string) $io->askHidden('Mot de passe (12 caractères minimum, difficile à deviner)');
        if ($password !== (string) $io->askHidden('Confirmation du mot de passe')) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::INVALID;
        }

        try {
            $admin = $this->adminAccounts->create($email, $role, $password);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->success(\sprintf('Compte d\'administration « %s » créé (%s).', $admin->getEmail(), AdminUser::ROLES[$admin->getRole()]));

        return Command::SUCCESS;
    }
}
