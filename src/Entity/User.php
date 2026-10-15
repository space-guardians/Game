<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Ignore;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte joueur. Les comptes d'administration sont distincts (§5.6.2).
 *
 * @see §4.1 du cahier des charges
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'user_email_unique', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cette adresse e-mail.')]
#[Auditable]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** Empreinte du mot de passe (vide le temps de la hacher, juste après la création) */
    #[Ignore]
    #[ORM\Column]
    private string $password = '';

    /** Dernière activité dans le jeu, à quelques minutes près (UserRepository::recordActivity()) */
    #[Ignore]
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastActiveAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $bannedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $banEndsAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $banReason = null;

    public function __construct(
        string $email,
        /** Date d'inscription, qui vaut aussi acceptation des règles du jeu */
        #[ORM\Column]
        private readonly \DateTimeImmutable $registeredAt,
    ) {
        $this->email = self::normalizeEmail($email);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
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

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    /** Bannissement (§4.13) : en vigueur depuis `bannedAt`, jusqu'à `banEndsAt` (null : définitif) */
    public function ban(\DateTimeImmutable $at, ?\DateTimeImmutable $until, string $reason): void
    {
        $this->bannedAt = $at;
        $this->banEndsAt = $until;
        $this->banReason = mb_substr($reason, 0, 255);
    }

    public function liftBan(): void
    {
        $this->bannedAt = $this->banEndsAt = $this->banReason = null;
    }

    public function isBannedAt(\DateTimeImmutable $at): bool
    {
        return null !== $this->bannedAt && (null === $this->banEndsAt || $at < $this->banEndsAt);
    }

    public function getBannedAt(): ?\DateTimeImmutable
    {
        return $this->bannedAt;
    }

    public function getBanEndsAt(): ?\DateTimeImmutable
    {
        return $this->banEndsAt;
    }

    public function getBanReason(): ?string
    {
        return $this->banReason;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function getLastActiveAt(): ?\DateTimeImmutable
    {
        return $this->lastActiveAt;
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
