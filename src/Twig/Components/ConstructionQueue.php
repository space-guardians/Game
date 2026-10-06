<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\BuildingQueueItem;
use App\Service\Account\GameContext;
use App\Service\Economy\CancellationRefund;
use Psr\Clock\ClockInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * File de construction de la planète active (charte §8, QueueItem) : progression, temps restant et heure de fin,
 * décomptés en direct par le contrôleur Stimulus « countdown », qui rafraîchit la page à l'échéance (Turbo). Live
 * Component : les notifications de fin poussées par Mercure (#24) pourront le recharger seul.
 */
#[AsLiveComponent]
final class ConstructionQueue
{
    use DefaultActionTrait;

    /** Bouton d'annulation (écran « Bâtiments ») ou simple lien vers l'écran (vue d'ensemble) */
    public bool $cancellable = false;

    public function __construct(
        private readonly GameContext $context,
        private readonly CancellationRefund $refund,
        private readonly ClockInterface $clock,
    ) {}

    public function getItem(): ?BuildingQueueItem
    {
        return $this->context->activeConstruction();
    }

    /** Horodatage serveur en millisecondes : le décompte se cale dessus, pas sur l'horloge du navigateur */
    public function getNowMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }

    /** Part du coût rendue en cas d'annulation maintenant */
    public function getRefundShare(): float
    {
        $item = $this->getItem();

        return null === $item ? 0.0 : $this->refund->remainingShare($item->getStartedAt(), $item->getEndsAt(), $this->clock->now());
    }
}
