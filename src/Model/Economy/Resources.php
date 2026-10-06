<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Quantités des trois ressources stockables (§4.2). Les quantités restent fractionnaires pour que la production
 * s'accumule sans perte ; l'interface les affiche arrondies à l'unité inférieure.
 */
final readonly class Resources
{
    public function __construct(
        public float $metal = 0.0,
        public float $crystal = 0.0,
        public float $deuterium = 0.0,
    ) {
        if ($metal < 0 || $crystal < 0 || $deuterium < 0) {
            throw new \InvalidArgumentException('Une quantité de ressources ne peut pas être négative.');
        }
    }

    public static function zero(): self
    {
        return new self();
    }

    public function plus(self $other): self
    {
        return new self($this->metal + $other->metal, $this->crystal + $other->crystal, $this->deuterium + $other->deuterium);
    }

    /** @throws \InvalidArgumentException si une ressource manque */
    public function minus(self $other): self
    {
        return new self($this->metal - $other->metal, $this->crystal - $other->crystal, $this->deuterium - $other->deuterium);
    }

    public function covers(self $cost): bool
    {
        return $this->metal >= $cost->metal && $this->crystal >= $cost->crystal && $this->deuterium >= $cost->deuterium;
    }

    /** Ce qui manque pour payer le coût (0 pour une ressource suffisante) */
    public function shortfall(self $cost): self
    {
        return new self(max(0.0, $cost->metal - $this->metal), max(0.0, $cost->crystal - $this->crystal), max(0.0, $cost->deuterium - $this->deuterium));
    }

    public function times(float $factor): self
    {
        return new self($this->metal * $factor, $this->crystal * $factor, $this->deuterium * $factor);
    }

    /** Ressource par ressource, la plus petite des deux quantités */
    public function min(self $other): self
    {
        return new self(min($this->metal, $other->metal), min($this->crystal, $other->crystal), min($this->deuterium, $other->deuterium));
    }

    /** Ressource par ressource, la plus grande des deux quantités */
    public function max(self $other): self
    {
        return new self(max($this->metal, $other->metal), max($this->crystal, $other->crystal), max($this->deuterium, $other->deuterium));
    }

    /** @return array{metal: float, crystal: float, deuterium: float} */
    public function toArray(): array
    {
        return ['metal' => $this->metal, 'crystal' => $this->crystal, 'deuterium' => $this->deuterium];
    }
}
