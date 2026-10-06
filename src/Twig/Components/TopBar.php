<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Empire;
use App\Service\Account\GameContext;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Barre globale des écrans connectés (charte §8) : planète active et empire. Les ressources et les badges de
 * notifications s'y ajouteront avec l'économie et la messagerie (Live Component mis à jour via Mercure).
 */
#[AsTwigComponent]
final class TopBar
{
    public function __construct(
        private readonly GameContext $context,
    ) {}

    public function getEmpire(): ?Empire
    {
        return $this->context->empire();
    }
}
