<?php

declare(strict_types=1);

namespace App\Twig;

use App\Model\Scheduling\GameTopics;
use App\Service\Account\GameContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Topics Mercure privés du joueur connecté, auxquels le gabarit applicatif s'abonne (Turbo Streams, §5.2).
 */
final readonly class PlayerTopicsExtension
{
    public function __construct(
        private GameContext $context,
    ) {}

    /** @return list<string> */
    #[AsTwigFunction('player_topics')]
    public function topics(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : [GameTopics::empire($empire)];
    }
}
