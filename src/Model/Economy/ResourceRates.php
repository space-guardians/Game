<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Débits horaires des ressources, signés : la production nette de deutérium est négative quand la centrale à
 * fusion en consomme plus que les synthétiseurs n'en produisent.
 */
final readonly class ResourceRates
{
    public function __construct(
        public float $metal = 0.0,
        public float $crystal = 0.0,
        public float $deuterium = 0.0,
    ) {}

    public static function zero(): self
    {
        return new self();
    }

    public static function of(Resources $resources): self
    {
        return new self($resources->metal, $resources->crystal, $resources->deuterium);
    }

    public function plus(self $other): self
    {
        return new self($this->metal + $other->metal, $this->crystal + $other->crystal, $this->deuterium + $other->deuterium);
    }

    public function times(float $factor): self
    {
        return new self($this->metal * $factor, $this->crystal * $factor, $this->deuterium * $factor);
    }

    public function with(string $resource, float $value): self
    {
        return new self(
            'metal' === $resource ? $value : $this->metal,
            'crystal' === $resource ? $value : $this->crystal,
            'deuterium' === $resource ? $value : $this->deuterium,
        );
    }

    public function get(string $resource): float
    {
        return match ($resource) {
            'metal' => $this->metal,
            'crystal' => $this->crystal,
            'deuterium' => $this->deuterium,
            default => throw new \InvalidArgumentException(\sprintf('Ressource inconnue : %s.', $resource)),
        };
    }
}
