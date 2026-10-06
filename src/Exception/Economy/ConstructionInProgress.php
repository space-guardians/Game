<?php

declare(strict_types=1);

namespace App\Exception\Economy;

use App\Entity\BuildingQueueItem;

/**
 * Une construction est déjà en cours sur la planète : une seule à la fois (§4.3).
 */
final class ConstructionInProgress extends \DomainException
{
    public function __construct(public readonly BuildingQueueItem $current)
    {
        parent::__construct(\sprintf('Construction déjà en cours : %s.', $current));
    }
}
