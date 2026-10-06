<?php

declare(strict_types=1);

namespace App\Exception\Economy;

use App\Model\Economy\Resources;

/**
 * Pas assez de ressources sur la planète pour payer le coût.
 */
final class InsufficientResources extends \DomainException
{
    public function __construct(public readonly Resources $missing)
    {
        parent::__construct('Ressources insuffisantes.');
    }
}
