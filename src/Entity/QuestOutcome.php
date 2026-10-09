<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Issue d'une quête d'exploration (§4.6.4) : un choix proposé au joueur, ou l'un des dénouements tirés au sort
 * (selon son poids). Elle porte ses récompenses et ses risques — ressources gagnées ou perdues par la cargaison,
 * part des vaisseaux perdus — et peut enchaîner une quête suivante.
 */
#[ORM\Entity]
#[Auditable]
final class QuestOutcome implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'outcomes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    // @phpstan-ignore doctrine.associationType (issue créée vide par le formulaire du panneau, rattachée par QuestTemplate::addOutcome() avant enregistrement)
    private ?QuestTemplate $template = null;

    /** Libellé du choix (« Enquêter ») ou nom du dénouement */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Donnez un libellé à l’issue.')]
    private string $label = '';

    /** Dénouement raconté au joueur */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Rédigez le dénouement de l’issue.')]
    private string $text = '';

    /** Poids dans le tirage d'une quête automatique */
    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le poids est positif ou nul.')]
    private int $weight = 1;

    /** Ressources gagnées (positif, dans la limite du cargo libre) ou perdues (négatif) par la cargaison */
    #[ORM\Column]
    private int $metal = 0;

    #[ORM\Column]
    private int $crystal = 0;

    #[ORM\Column]
    private int $deuterium = 0;

    /** Part de chaque type de vaisseau perdue, en % (arrondi à l'unité inférieure) */
    #[ORM\Column]
    #[Assert\Range(notInRangeMessage: 'La part de vaisseaux perdus va de 0 à 100 %.', min: 0, max: 100)]
    private int $shipLossPercent = 0;

    /** Quête déclenchée ensuite (chaînage) */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?QuestTemplate $nextQuest = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?QuestTemplate
    {
        return $this->template;
    }

    public function setTemplate(?QuestTemplate $template): void
    {
        $this->template = $template;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = trim((string) $label);
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = trim((string) $text);
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): void
    {
        $this->weight = $weight;
    }

    public function getMetal(): int
    {
        return $this->metal;
    }

    public function setMetal(int $metal): void
    {
        $this->metal = $metal;
    }

    public function getCrystal(): int
    {
        return $this->crystal;
    }

    public function setCrystal(int $crystal): void
    {
        $this->crystal = $crystal;
    }

    public function getDeuterium(): int
    {
        return $this->deuterium;
    }

    public function setDeuterium(int $deuterium): void
    {
        $this->deuterium = $deuterium;
    }

    public function getShipLossPercent(): int
    {
        return $this->shipLossPercent;
    }

    public function setShipLossPercent(int $shipLossPercent): void
    {
        $this->shipLossPercent = $shipLossPercent;
    }

    public function getNextQuest(): ?QuestTemplate
    {
        return $this->nextQuest;
    }

    public function setNextQuest(?QuestTemplate $nextQuest): void
    {
        $this->nextQuest = $nextQuest;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
