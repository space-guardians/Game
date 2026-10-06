<?php

declare(strict_types=1);

namespace App\Model\Economy;

/**
 * État des ressources d'une planète à un instant : de quoi afficher stock, débit et jauge, et laisser
 * l'interface poursuivre le décompte d'elle-même.
 */
final readonly class ResourceSnapshot
{
    public function __construct(
        public Resources $amounts,
        public Resources $hourlyProduction,
        public Resources $capacity,
        public \DateTimeImmutable $at,
    ) {}
}
