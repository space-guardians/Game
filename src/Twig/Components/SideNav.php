<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Service\Account\GameContext;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Navigation des écrans connectés (charte §8) : écrans du jeu, puis planètes de l'empire, qui servent de
 * sélecteur de planète active.
 */
#[AsTwigComponent]
final class SideNav
{
    /** Nom de la route de l'écran courant, pour marquer l'élément actif */
    public string $current = '';

    public function __construct(
        private readonly GameContext $context,
    ) {}

    public function getEmpire(): ?Empire
    {
        return $this->context->empire();
    }

    /** @return list<Planet> */
    public function getPlanets(): array
    {
        return $this->context->planets();
    }
}
