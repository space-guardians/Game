<?php

declare(strict_types=1);

namespace App\Exception\Research;

use App\Entity\Prerequisite;

/**
 * Bâtiment ou technologie encore verrouillé : des prérequis ne sont pas remplis (§4.4).
 */
final class MissingPrerequisites extends \DomainException
{
    /** @param non-empty-list<Prerequisite> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Prérequis non remplis : ' . self::describe($missing) . '.');
    }

    /**
     * « Énergie niveau 3, Synthétiseur de deutérium niveau 5 ».
     *
     * @param list<Prerequisite> $missing
     */
    public static function describe(array $missing): string
    {
        return implode(', ', array_map(
            static fn(Prerequisite $prerequisite): string => \sprintf('%s niveau %d', $prerequisite->getRequired(), $prerequisite->getLevel()),
            $missing,
        ));
    }
}
