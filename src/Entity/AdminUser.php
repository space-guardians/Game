<?php

declare(strict_types=1);

namespace App\Entity;

use App\Admin\AdminRole;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte d'administration, distinct des comptes joueurs : un administrateur qui joue utilise un autre compte.
 * La double authentification (TOTP) est obligatoire : tant qu'elle n'est pas activée, le panneau impose de le faire.
 *
 * @see §5.6.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\UniqueConstraint(name: 'admin_user_email_unique', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Un compte d\'administration existe déjà avec cette adresse e-mail.')]
final class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'Saisissez l\'adresse e-mail du compte.')]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    private string $email;

    /** Empreinte du mot de passe (vide le temps de la hacher, juste après la création) */
    #[ORM\Column]
    private string $password = '';

    /** Mot de passe saisi à la création depuis le panneau, haché avant enregistrement ; jamais persisté */
    #[Assert\NotBlank(message: 'Choisissez un mot de passe.', groups: ['creation'])]
    #[Assert\Length(min: 12, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', groups: ['creation'])]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_STRONG, message: 'Ce mot de passe est trop facile à deviner : allongez-le ou variez les caractères.', groups: ['creation'])]
    private ?string $plainPassword = null;

    /** Secret TOTP ; tant que totpConfirmed est faux, l'activation est en cours */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $totpSecret = null;

    /** Valeur par défaut en base : les comptes existants devront activer la double authentification */
    #[ORM\Column(options: ['default' => false])]
    private bool $totpConfirmed = false;

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

    public function setEmail(string $email): void
    {
        $this->email = User::normalizeEmail($email);
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

    public function setRole(AdminRole $role): void
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

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): void
    {
        $this->plainPassword = $plainPassword;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // —— Double authentification (TOTP) ——

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecret && $this->totpConfirmed;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    /** Disponible dès le début de l'activation, pour vérifier le premier code */
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return null === $this->totpSecret ? null : new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function hasPendingTotpSecret(): bool
    {
        return null !== $this->totpSecret && !$this->totpConfirmed;
    }

    public function startTotpEnrollment(string $secret): void
    {
        $this->totpSecret = $secret;
        $this->totpConfirmed = false;
    }

    public function confirmTotp(): void
    {
        \assert(null !== $this->totpSecret);
        $this->totpConfirmed = true;
    }

    /** Appareil perdu ou changé : le compte devra réactiver la double authentification */
    public function resetTwoFactor(): void
    {
        $this->totpSecret = null;
        $this->totpConfirmed = false;
    }

    /**
     * La session ne garde ni le mot de passe (hachage tronqué), ni le secret TOTP, ni le mot de passe saisi.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);
        $data["\0" . self::class . "\0totpSecret"] = null;
        $data["\0" . self::class . "\0plainPassword"] = null;

        return $data;
    }
}
