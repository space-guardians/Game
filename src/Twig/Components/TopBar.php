<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Empire;
use App\Model\Economy\ResourceSnapshot;
use App\Service\Account\GameContext;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Barre globale des écrans connectés (charte §8) : planète active, ses ressources (décompte en direct côté
 * navigateur) et empire. L'énergie et les badges de notifications s'y ajouteront avec les bâtiments et la messagerie.
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

    public function getResources(): ?ResourceSnapshot
    {
        return $this->context->activeResources();
    }
}
