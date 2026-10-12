<?php

declare(strict_types=1);

namespace App\Model\Alliance;

/**
 * Flotte d'un autre empire repérée par le scanner d'une planète d'alliance (§4.10).
 */
final readonly class ScanContact
{
    public function __construct(
        public string $empire,
        public string $fleet,
        public int $ships,
        /** Distance à la planète, dans le repère global */
        public int $distance,
        public bool $inFlight,
        /** Destination de l'ordre en cours (flotte en vol) */
        public ?string $destination = null,
        public ?\DateTimeImmutable $arrivesAt = null,
    ) {}
}
