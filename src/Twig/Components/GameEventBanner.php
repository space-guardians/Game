<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\GameEvent;
use App\Service\Account\GameContext;
use App\Service\Event\GameEvents;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Bannière des événements de jeu en cours (§4.16) qui s'appliquent à la planète active, avec leur fin en compte à
 * rebours.
 */
#[AsTwigComponent]
final class GameEventBanner
{
    public function __construct(
        private readonly GameContext $context,
        private readonly GameEvents $events,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<GameEvent> */
    public function getEvents(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : $this->events->covering($empire->getActivePlanet());
    }

    public function getNow(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
