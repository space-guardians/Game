<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Model\Economy\Resources;

/**
 * Remboursement d'une construction ou d'une recherche annulée (§4.3, §4.4), sans base de données :
 * - au prorata du temps restant (annulée à 10 % du temps écoulé → 90 % du coût rendu) ;
 * - dans la limite de la capacité de stockage courante de la planète : l'excédent est perdu, et un stock déjà
 *   au-delà de la capacité ne reçoit rien.
 */
final class CancellationRefund
{
    /** Part du coût rendue, entre 0 et 1 */
    public function remainingShare(\DateTimeImmutable $startedAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $cancelledAt): float
    {
        $duration = $endsAt->getTimestamp() - $startedAt->getTimestamp();
        if ($duration <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($endsAt->getTimestamp() - $cancelledAt->getTimestamp()) / $duration));
    }

    /** Stock après remboursement, plafonné ressource par ressource */
    public function credit(Resources $stock, Resources $refund, Resources $capacity): Resources
    {
        return $stock->max($stock->plus($refund)->min($capacity));
    }
}
