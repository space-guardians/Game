<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\QuestTemplate;
use App\Enum\Exploration\QuestResolution;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Quêtes d'exploration : par défaut automatique, toujours déclenchée (100 %), avec une issue sans effet.
 *
 * @extends PersistentObjectFactory<QuestTemplate>
 */
final class QuestTemplateFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return QuestTemplate::class;
    }

    /** Quête à choix du joueur */
    public function choice(int $responseMinutes = QuestTemplate::DEFAULT_RESPONSE_MINUTES): self
    {
        return $this->with(['resolution' => QuestResolution::PlayerChoice, 'responseMinutes' => $responseMinutes]);
    }

    protected function defaults(): array
    {
        return [
            'code' => 'quete_' . self::faker()->unique()->numberBetween(1, 1_000_000),
            'name' => 'Quête ' . self::faker()->word(),
            'text' => 'Il se passe quelque chose.',
            'resolution' => QuestResolution::Automatic,
            'chance' => 100,
            'outcomes' => QuestOutcomeFactory::new()->many(1),
        ];
    }
}
