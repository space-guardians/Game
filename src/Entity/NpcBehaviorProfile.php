<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Pve\NpcCategory;
use App\Model\Economy\Resources;
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

    /** Guilde marchande (§4.15) : intervalle entre deux convois à escorter */
    #[ORM\Column(options: ['default' => 6])]
    #[Assert\Positive]
    private int $convoyIntervalHours = 6;

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

    /** @var array<string, int> flotte de départ, ou de base d'un boss (codes de types de vaisseaux) */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $fleetComposition = [];

    /** @var array<string, int> défenses au sol à la fondation */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $defenseComposition = [];

    /** Récompense de victoire versée à l'empire qui l'a vaincue sur sa planète (§4.14, en plus du pillage) */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $victoryMetal = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $victoryCrystal = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $victoryDeuterium = 0;

    /** Récompenses spéciales (§4.14), réservées aux « boss » en pratique : antimatière et plan d'un vaisseau réservé */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $victoryAntimatter = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ShipType $victoryBlueprint = null;

    /** Saisie de composition illisible, signalée à la validation */
    private ?string $invalidComposition = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (null !== $this->invalidComposition) {
            $context->buildViolation(\sprintf('Composition illisible : « %s ». Écrivez « code:quantité », séparés par des virgules.', $this->invalidComposition))->atPath('fleetCompositionText')->addViolation();
        }
        if (null !== $this->victoryBlueprint && !$this->victoryBlueprint->requiresBlueprint()) {
            $context->buildViolation(\sprintf('%s se construit sans plan : choisissez un type de vaisseau réservé.', $this->victoryBlueprint->getName()))->atPath('victoryBlueprint')->addViolation();
        }
        if (NpcCategory::Trader === $this->category && $this->raidsEnabled) {
            $context->buildViolation('Une guilde marchande ne lance pas de raids : elle fait escorter ses convois.')->atPath('raidsEnabled')->addViolation();
        }
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

    public function getConvoyIntervalHours(): int
    {
        return $this->convoyIntervalHours;
    }

    public function setConvoyIntervalHours(int $hours): void
    {
        $this->convoyIntervalHours = $hours;
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

    /** @return array<string, int> */
    public function getFleetComposition(): array
    {
        return $this->fleetComposition;
    }

    /** @return array<string, int> */
    public function getDefenseComposition(): array
    {
        return $this->defenseComposition;
    }

    public function getFleetCompositionText(): string
    {
        return self::compositionToText($this->fleetComposition);
    }

    public function setFleetCompositionText(?string $text): void
    {
        $this->fleetComposition = $this->parseComposition((string) $text);
    }

    public function getDefenseCompositionText(): string
    {
        return self::compositionToText($this->defenseComposition);
    }

    public function setDefenseCompositionText(?string $text): void
    {
        $this->defenseComposition = $this->parseComposition((string) $text);
    }

    public function getVictoryReward(): Resources
    {
        return new Resources($this->victoryMetal, $this->victoryCrystal, $this->victoryDeuterium);
    }

    public function getVictoryMetal(): int
    {
        return $this->victoryMetal;
    }

    public function setVictoryMetal(int $amount): void
    {
        $this->victoryMetal = $amount;
    }

    public function getVictoryCrystal(): int
    {
        return $this->victoryCrystal;
    }

    public function setVictoryCrystal(int $amount): void
    {
        $this->victoryCrystal = $amount;
    }

    public function getVictoryDeuterium(): int
    {
        return $this->victoryDeuterium;
    }

    public function setVictoryDeuterium(int $amount): void
    {
        $this->victoryDeuterium = $amount;
    }

    public function getVictoryAntimatter(): int
    {
        return $this->victoryAntimatter;
    }

    public function setVictoryAntimatter(int $amount): void
    {
        $this->victoryAntimatter = $amount;
    }

    public function getVictoryBlueprint(): ?ShipType
    {
        return $this->victoryBlueprint;
    }

    public function setVictoryBlueprint(?ShipType $type): void
    {
        $this->victoryBlueprint = $type;
    }

    /** @param array<string, int> $composition */
    private static function compositionToText(array $composition): string
    {
        return implode(', ', array_map(static fn(string $code, int $quantity): string => $code . ':' . $quantity, array_keys($composition), $composition));
    }

    /** @return array<string, int> « light_fighter:15, cruiser:3 » */
    private function parseComposition(string $text): array
    {
        $composition = [];
        foreach (array_filter(array_map('trim', explode(',', $text)), static fn(string $part): bool => '' !== $part) as $part) {
            if (1 !== preg_match('/^([a-z0-9_]+)\s*:\s*(\d+)$/', $part, $match) || (int) $match[2] < 1) {
                $this->invalidComposition = $part;

                continue;
            }
            $composition[$match[1]] = (int) $match[2];
        }

        return $composition;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
