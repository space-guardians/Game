<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Création des comptes d'administration (commande app:admin:create, puis écran de gestion, #104).
 */
final readonly class AdminAccounts
{
    public function __construct(
        private AdminUserRepository $admins,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws \InvalidArgumentException adresse, rôle ou mot de passe refusé, ou compte déjà existant
     */
    public function create(string $email, string $role, string $plainPassword): AdminUser
    {
        $this->assertValid($email, [new Assert\NotBlank(), new Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]);
        $this->assertValid($plainPassword, [
            new Assert\Length(min: 12, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_STRONG, message: 'Ce mot de passe est trop facile à deviner : allongez-le ou variez les caractères.'),
        ]);
        if (null !== $this->admins->findOneByEmail($email)) {
            throw new \InvalidArgumentException(\sprintf('Un compte d\'administration existe déjà pour « %s ».', $email));
        }

        $admin = new AdminUser($email, $role, $this->clock->now());
        $admin->setPassword($this->passwordHasher->hashPassword($admin, $plainPassword));

        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        return $admin;
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     */
    private function assertValid(string $value, array $constraints): void
    {
        $violations = $this->validator->validate($value, $constraints);
        if (\count($violations) > 0) {
            throw new \InvalidArgumentException((string) $violations->get(0)->getMessage());
        }
    }
}
