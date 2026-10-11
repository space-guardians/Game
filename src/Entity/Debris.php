<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\DebrisRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Champ de débris (§4.7) : métal et cristal laissés par les vaisseaux détruits au combat, à l'endroit de la bataille.
 * Les combats successifs au même lieu l'alimentent ; des recycleurs le récupèrent (action « Recyclage », §4.6). Un
 * champ vidé disparaît. Non audité : données de jeu volatiles.
 */
#[ORM\Entity(repositoryClass: DebrisRepository::class)]
#[ORM\Index(name: 'debris_galaxy_idx', fields: ['location.galaxyId'])]
final class Debris implements \Stringable
{
    /** En deçà, un champ est considéré comme vide */
    public const float EMPTY_BELOW = 1.0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $metal = 0.0;

    #[ORM\Column]
    private float $crystal = 0.0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Embedded(columnPrefix: 'location_')]
        private readonly SpaceLocation $location,
        #[ORM\Column(length: 80)]
        private readonly string $locationLabel,
        \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocation(): SpaceLocation
    {
        return $this->location;
    }

    public function getLocationLabel(): string
    {
        return $this->locationLabel;
    }

    public function getMetal(): float
    {
        return $this->metal;
    }

    public function getCrystal(): float
    {
        return $this->crystal;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResources(): Resources
    {
        return new Resources($this->metal, $this->crystal);
    }

    public function add(Resources $debris, \DateTimeImmutable $at): void
    {
        $this->metal += max(0.0, $debris->metal);
        $this->crystal += max(0.0, $debris->crystal);
        $this->updatedAt = $at;
    }

    /** Récupère au plus ce qui reste ; renvoie ce qui a été pris */
    public function take(Resources $wanted, \DateTimeImmutable $at): Resources
    {
        $taken = new Resources(min($this->metal, max(0.0, $wanted->metal)), min($this->crystal, max(0.0, $wanted->crystal)));
        $this->metal -= $taken->metal;
        $this->crystal -= $taken->crystal;
        $this->updatedAt = $at;

        return $taken;
    }

    public function isEmpty(): bool
    {
        return $this->metal + $this->crystal < self::EMPTY_BELOW;
    }

    public function __toString(): string
    {
        return \sprintf('Débris en %s', $this->locationLabel);
    }
}
