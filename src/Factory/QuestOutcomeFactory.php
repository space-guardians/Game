<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\QuestOutcome;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Issues de quête : par défaut sans effet, de poids 1. Créées avec leur gabarit (QuestTemplateFactory, « outcomes »).
 *
 * @extends PersistentObjectFactory<QuestOutcome>
 */
final class QuestOutcomeFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return QuestOutcome::class;
    }

    protected function defaults(): array
    {
        return [
            'label' => 'Issue ' . self::faker()->word(),
            'text' => 'Rien de notable.',
            'weight' => 1,
        ];
    }
}
