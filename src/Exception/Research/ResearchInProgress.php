<?php

declare(strict_types=1);

namespace App\Exception\Research;

use App\Entity\ResearchQueueItem;

/**
 * L'empire a déjà une recherche en cours : une seule à la fois (§4.4).
 */
final class ResearchInProgress extends \DomainException
{
    public function __construct(public readonly ResearchQueueItem $current)
    {
        parent::__construct(\sprintf('Une recherche est déjà en cours : %s.', $current->getTechnology()->getName()));
    }
}
