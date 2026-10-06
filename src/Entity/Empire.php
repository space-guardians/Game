<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Account\StartingOrientation;
use App\Repository\EmpireRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Empire d'un joueur : un compte = un empire (§4.1). Il naît à l'inscription avec sa planète mère, placée selon
 * l'orientation choisie (§2.4). L'alliance arrivera avec les alliances (phase Social).
 *
 * @see §4.1 du cahier des charges
 */
#[ORM\Entity(repositoryClass: EmpireRepository::class)]
#[ORM\UniqueConstraint(name: 'empire_name_unique', fields: ['name'])]
#[Auditable]
final class Empire
{
    public const int NAME_MIN_LENGTH = 3;
    public const int NAME_MAX_LENGTH = 20;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $score = 0;

    /** Planète affichée et pilotée par défaut ; la planète mère au départ */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Planet $activePlanet;

    public function __construct(
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
        private readonly User $user,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        private string $name,
        #[ORM\Column(length: 20, enumType: StartingOrientation::class)]
        private readonly StartingOrientation $orientation,
        /** Une galaxie qui porte des planètes mères ne peut pas être supprimée (clé étrangère restrictive) */
        #[ORM\OneToOne]
        #[ORM\JoinColumn(nullable: false, unique: true)]
        private readonly Planet $homePlanet,
        #[ORM\Column]
        private readonly \DateTimeImmutable $foundedAt,
    ) {
        $this->name = self::normalizeName($name);
        $homePlanet->assignTo($this);
        $this->activePlanet = $homePlanet;
    }

    /** Espaces superflus retirés ; la casse est conservée mais ne distingue pas deux empires */
    public static function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($name));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrientation(): StartingOrientation
    {
        return $this->orientation;
    }

    public function getHomePlanet(): Planet
    {
        return $this->homePlanet;
    }

    public function getActivePlanet(): Planet
    {
        return $this->activePlanet;
    }

    /** Planète pilotée par défaut (sélecteur de planète) : uniquement une planète de l'empire */
    public function switchTo(Planet $planet): void
    {
        if ($planet->getOwner() !== $this) {
            throw new \DomainException(\sprintf('La planète %s n\'appartient pas à l\'empire « %s ».', $planet, $this->name));
        }
        $this->activePlanet = $planet;
    }

    public function isHomePlanet(Planet $planet): bool
    {
        return $planet === $this->homePlanet;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getFoundedAt(): \DateTimeImmutable
    {
        return $this->foundedAt;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
