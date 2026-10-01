<?php

declare(strict_types=1);

namespace App\Entity;

use App\Admin\AdminRole;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte d'administration, distinct des comptes joueurs : un administrateur qui joue utilise un autre compte.
 *
 * @see §5.6.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\UniqueConstraint(name: 'admin_user_email_unique', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte d\'administration existe déjà avec cette adresse e-mail.')]
final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** Empreinte du mot de passe (vide le temps de la hacher, juste après la création) */
    #[ORM\Column]
    private string $password = '';

    public function __construct(
        string $email,
        /** Rôle unique ; il inclut les droits des rôles inférieurs */
        #[ORM\Column(length: 30, enumType: AdminRole::class)]
        private AdminRole $role,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->email = User::normalizeEmail($email);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRole(): AdminRole
    {
        return $this->role;
    }

    public function changeRole(AdminRole $role): void
    {
        $this->role = $role;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Ne garde qu'un hachage tronqué du mot de passe dans la session (recommandation Symfony).
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);

        return $data;
    }
}
