<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Pve\NpcCategory;
use App\Repository\NpcBehaviorProfileRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Profil de comportement d'une civilisation non-joueuse (§4.14), réglé dans le panneau sans redéploiement :
 * catégorie, raids (mineures seulement : activés, intervalle, portée), recalcul de la flotte (boss : fréquence et
 * force en % de la flotte militaire moyenne du top 10) et butin. Contenu de jeu (installé par migration), audité.
 */
#[ORM\Entity(repositoryClass: NpcBehaviorProfileRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'Ce code est déjà pris.')]
#[Auditable]
class NpcBehaviorProfile implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Le code ne contient que des minuscules, chiffres et « _ ».')]
    private string $code = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un nom au profil.')]
    private string $name = '';

    #[ORM\Column(length: 10, enumType: NpcCategory::class)]
    private NpcCategory $category = NpcCategory::Minor;

    /** Raids sur les joueurs à proximité (jamais pour un boss, strictement défensif) */
    #[ORM\Column]
    private bool $raidsEnabled = false;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 720)]
    private int $raidIntervalHours = 24;

    /** Distance maximale d'une cible de raid, dans le repère global */
    #[ORM\Column]
    #[Assert\Positive]
    private float $raidRange = 3000.0;

    /** Boss : recalcul de la flotte toutes les N heures */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 720)]
    private int $fleetRecalcHours = 24;

    /** Boss : force de la flotte, en % de la flotte militaire moyenne du top 10 */
    #[ORM\Column]
    #[Assert\Range(min: 10, max: 1000)]
    private int $fleetStrengthPercent = 100;

    /** Part des ressources de ses planètes emportée par qui la vainc, en % (provisoire) */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private int $lootPercent = 50;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (NpcCategory::Boss === $this->category && $this->raidsEnabled) {
            $context->buildViolation('Une civilisation « boss » est strictement défensive : pas de raids.')->atPath('raidsEnabled')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCategory(): NpcCategory
    {
        return $this->category;
    }

    public function setCategory(NpcCategory $category): void
    {
        $this->category = $category;
    }

    public function isRaidsEnabled(): bool
    {
        return $this->raidsEnabled;
    }

    public function setRaidsEnabled(bool $raidsEnabled): void
    {
        $this->raidsEnabled = $raidsEnabled;
    }

    public function getRaidIntervalHours(): int
    {
        return $this->raidIntervalHours;
    }

    public function setRaidIntervalHours(int $hours): void
    {
        $this->raidIntervalHours = $hours;
    }

    public function getRaidRange(): float
    {
        return $this->raidRange;
    }

    public function setRaidRange(float $range): void
    {
        $this->raidRange = $range;
    }

    public function getFleetRecalcHours(): int
    {
        return $this->fleetRecalcHours;
    }

    public function setFleetRecalcHours(int $hours): void
    {
        $this->fleetRecalcHours = $hours;
    }

    public function getFleetStrengthPercent(): int
    {
        return $this->fleetStrengthPercent;
    }

    public function setFleetStrengthPercent(int $percent): void
    {
        $this->fleetStrengthPercent = $percent;
    }

    public function getLootPercent(): int
    {
        return $this->lootPercent;
    }

    public function setLootPercent(int $percent): void
    {
        $this->lootPercent = $percent;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
