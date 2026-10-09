<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Exploration\QuestResolution;
use App\Model\Economy\Resources;
use App\Repository\QuestTemplateRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Gabarit de quête ou d'événement d'exploration (contenu de jeu, réglable dans le panneau) : texte, probabilité
 * d'apparition à l'arrivée d'une flotte en exploration, conditions de déclenchement (technologie de l'empire,
 * vaisseaux envoyés, cargaison), et issues possibles — tirées au sort ou proposées au joueur. Une issue peut
 * enchaîner une quête suivante ; une quête de probabilité nulle n'apparaît qu'ainsi.
 *
 * @see §4.6.4 du cahier des charges
 */
#[ORM\Entity(repositoryClass: QuestTemplateRepository::class)]
#[UniqueEntity('code', message: 'Ce code est déjà utilisé.')]
#[Auditable]
final class QuestTemplate implements \Stringable
{
    /** Délai de réponse par défaut d'une quête à choix, en minutes */
    public const int DEFAULT_RESPONSE_MINUTES = 24 * 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank(message: 'Donnez un code.')]
    #[Assert\Regex('/^[a-z0-9_]+$/', message: 'Le code ne contient que des minuscules, des chiffres et « _ ».')]
    private string $code = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un titre.')]
    private string $name = '';

    /** Récit présenté au joueur à l'apparition */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Rédigez le texte de la quête.')]
    private string $text = '';

    /** Seules les quêtes actives apparaissent ou s'enchaînent */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 20, enumType: QuestResolution::class)]
    private QuestResolution $resolution = QuestResolution::Automatic;

    /** Probabilité d'apparition à l'arrivée d'une exploration, en % ; 0 : seulement en suite d'une autre quête */
    #[ORM\Column]
    #[Assert\Range(notInRangeMessage: 'La probabilité va de 0 à 100 %.', min: 0, max: 100)]
    private int $chance = 0;

    /** Délai laissé au joueur pour choisir (quête à choix), en minutes ; passé ce délai, l'occasion est perdue */
    #[ORM\Column]
    #[Assert\Positive(message: 'Le délai de réponse est d’au moins une minute.')]
    private int $responseMinutes = self::DEFAULT_RESPONSE_MINUTES;

    /** Condition : technologie de l'empire… */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Technology $requiredTechnology = null;

    /** … à ce niveau au moins */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $requiredTechnologyLevel = 0;

    /** Condition : type de vaisseau présent dans la flotte… */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ShipType $requiredShipType = null;

    /** … en ce nombre au moins */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $requiredShipCount = 0;

    /** Condition : cargaison minimale de la flotte */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $requiredCargoMetal = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $requiredCargoCrystal = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $requiredCargoDeuterium = 0;

    /** @var Collection<int, QuestOutcome> */
    #[ORM\OneToMany(targetEntity: QuestOutcome::class, mappedBy: 'template', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[Assert\Valid]
    #[Assert\Count(min: 1, minMessage: 'Une quête a au moins une issue.')]
    private Collection $outcomes;

    public function __construct()
    {
        $this->outcomes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = trim((string) $code);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = trim((string) $text);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getResolution(): QuestResolution
    {
        return $this->resolution;
    }

    public function setResolution(QuestResolution $resolution): void
    {
        $this->resolution = $resolution;
    }

    public function isPlayerChoice(): bool
    {
        return QuestResolution::PlayerChoice === $this->resolution;
    }

    public function getChance(): int
    {
        return $this->chance;
    }

    public function setChance(int $chance): void
    {
        $this->chance = $chance;
    }

    public function getResponseMinutes(): int
    {
        return $this->responseMinutes;
    }

    public function setResponseMinutes(int $responseMinutes): void
    {
        $this->responseMinutes = $responseMinutes;
    }

    public function getRequiredTechnology(): ?Technology
    {
        return $this->requiredTechnology;
    }

    public function setRequiredTechnology(?Technology $requiredTechnology): void
    {
        $this->requiredTechnology = $requiredTechnology;
    }

    public function getRequiredTechnologyLevel(): int
    {
        return $this->requiredTechnologyLevel;
    }

    public function setRequiredTechnologyLevel(int $requiredTechnologyLevel): void
    {
        $this->requiredTechnologyLevel = $requiredTechnologyLevel;
    }

    public function getRequiredShipType(): ?ShipType
    {
        return $this->requiredShipType;
    }

    public function setRequiredShipType(?ShipType $requiredShipType): void
    {
        $this->requiredShipType = $requiredShipType;
    }

    public function getRequiredShipCount(): int
    {
        return $this->requiredShipCount;
    }

    public function setRequiredShipCount(int $requiredShipCount): void
    {
        $this->requiredShipCount = $requiredShipCount;
    }

    public function getRequiredCargoMetal(): int
    {
        return $this->requiredCargoMetal;
    }

    public function setRequiredCargoMetal(int $requiredCargoMetal): void
    {
        $this->requiredCargoMetal = $requiredCargoMetal;
    }

    public function getRequiredCargoCrystal(): int
    {
        return $this->requiredCargoCrystal;
    }

    public function setRequiredCargoCrystal(int $requiredCargoCrystal): void
    {
        $this->requiredCargoCrystal = $requiredCargoCrystal;
    }

    public function getRequiredCargoDeuterium(): int
    {
        return $this->requiredCargoDeuterium;
    }

    public function setRequiredCargoDeuterium(int $requiredCargoDeuterium): void
    {
        $this->requiredCargoDeuterium = $requiredCargoDeuterium;
    }

    public function requiredCargo(): Resources
    {
        return new Resources($this->requiredCargoMetal, $this->requiredCargoCrystal, $this->requiredCargoDeuterium);
    }

    /** @return Collection<int, QuestOutcome> */
    public function getOutcomes(): Collection
    {
        return $this->outcomes;
    }

    public function addOutcome(QuestOutcome $outcome): void
    {
        if (!$this->outcomes->contains($outcome)) {
            $outcome->setTemplate($this);
            $this->outcomes->add($outcome);
        }
    }

    public function removeOutcome(QuestOutcome $outcome): void
    {
        $this->outcomes->removeElement($outcome);
    }

    /** Une quête automatique doit pouvoir tirer une issue : au moins un poids positif */
    #[Assert\Callback]
    public function validateOutcomes(ExecutionContextInterface $context): void
    {
        if (QuestResolution::Automatic !== $this->resolution || $this->outcomes->isEmpty()) {
            return;
        }
        foreach ($this->outcomes as $outcome) {
            if ($outcome->getWeight() > 0) {
                return;
            }
        }
        $context->buildViolation('Une quête automatique a au moins une issue de poids positif.')->atPath('outcomes')->addViolation();
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
