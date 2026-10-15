<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameSettingRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètre global du jeu réglable dans le panneau (§5.6.1 « paramètres globaux ») : vitesse d'univers… Contenu de
 * départ installé par migration ; seule la valeur se modifie. Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: GameSettingRepository::class)]
#[Auditable]
class GameSetting implements \Stringable
{
    public const string UNIVERSE_SPEED = 'universe_speed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 40, unique: true)]
        private string $code,
        #[ORM\Column(length: 120)]
        private string $label,
        #[ORM\Column]
        #[Assert\Positive(message: 'La valeur doit être strictement positive.')]
        #[Assert\LessThanOrEqual(100, message: 'Au plus 100.')]
        private float $value,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getValue(): float
    {
        return $this->value;
    }

    public function setValue(float $value): void
    {
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
