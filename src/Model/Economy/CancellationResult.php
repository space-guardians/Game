<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * Bilan d'une annulation : ce qui est rendu au stock, et ce qui est perdu faute de place.
 */
final readonly class CancellationResult
{
    public function __construct(
        /** Part du coût remboursée, entre 0 et 1 */
        public float $share,
        public Resources $refunded,
        public Resources $lost,
    ) {}
}
