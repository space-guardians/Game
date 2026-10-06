<?php

declare(strict_types=1);

namespace App\Enum\Scheduling;

/**
 * Cycle de vie d'un événement planifié : en attente de son échéance, puis résolu, en échec ou annulé.
 */
enum ScheduledEventStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
