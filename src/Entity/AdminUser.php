<?php

declare(strict_types=1);

namespace App\Entity;

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
    /** Rôles du panneau et leur libellé, du plus restreint au plus étendu ; chacun hérite du précédent (security.yaml) */
    public const array ROLES = [
        'ROLE_MODERATOR' => 'Modération',
        'ROLE_GAME_DESIGNER' => 'Game design',
        'ROLE_ADMIN' => 'Administration',
        'ROLE_SUPER_ADMIN' => 'Super administration',
    ];

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
        /** Rôle unique, parmi ROLES */
        #[ORM\Column(length: 30)]
        private string $role,
        #[ORM\Column]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->email = User::normalizeEmail($email);
        $this->changeRole($role);
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

    public function getRole(): string
    {
        return $this->role;
    }

    public function changeRole(string $role): void
    {
        if (!\array_key_exists($role, self::ROLES)) {
            throw new \InvalidArgumentException(\sprintf('Rôle d\'administration inconnu : « %s » (attendu : %s).', $role, implode(', ', array_keys(self::ROLES))));
        }

        $this->role = $role;
    }

    public function getRoleLabel(): string
    {
        return self::ROLES[$this->role];
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role];
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
