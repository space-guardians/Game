<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Model\Combat\IncomingAttack;
use App\Service\Account\GameContext;
use App\Service\Combat\IncomingAttacks;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Bandeau « vous êtes attaqué » (§4.12) sur tous les écrans connectés : une alerte par attaque entrante, avec compte à
 * rebours jusqu'à l'arrivée ; remplacé en direct quand une nouvelle attaque est détectée.
 */
#[AsTwigComponent]
final class IncomingAttackBanner
{
    public function __construct(
        private readonly GameContext $context,
        private readonly IncomingAttacks $attacks,
        private readonly ClockInterface $clock,
    ) {}

    /** @return list<IncomingAttack> */
    public function getAttacks(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : $this->attacks->of($empire);
    }

    public function getNow(): \DateTimeImmutable
    {
        return $this->clock->now();
    }
}
