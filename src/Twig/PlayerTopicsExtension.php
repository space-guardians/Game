<?php

declare(strict_types=1);

namespace App\Twig;

use App\Model\Chat\ChatTopics;
use App\Model\Scheduling\GameTopics;
use App\Service\Account\GameContext;
use App\Service\Messaging\IgnoredPlayers;
use Twig\Attribute\AsTwigFunction;

/**
 * Topics Mercure privés du joueur connecté : ceux du jeu, auxquels le gabarit applicatif s'abonne (Turbo Streams,
 * §5.2), et ceux du chat (§5.7).
 */
final readonly class PlayerTopicsExtension
{
    public function __construct(
        private GameContext $context,
        private IgnoredPlayers $ignored,
    ) {}

    /** @return list<string> */
    #[AsTwigFunction('player_topics')]
    public function topics(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : [GameTopics::empire($empire)];
    }

    /** Empire du joueur connecté, pour distinguer ses propres messages dans le chat */
    #[AsTwigFunction('chat_author_id')]
    public function chatAuthorId(): ?int
    {
        return $this->context->empire()?->getId();
    }

    /**
     * Joueurs ignorés par le joueur connecté (§4.12.4) : le widget masque leurs messages dans les canaux partagés.
     *
     * @return list<int>
     */
    #[AsTwigFunction('chat_ignored_ids')]
    public function chatIgnoredIds(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : $this->ignored->idsOf($empire);
    }

    /**
     * Topics du chat (§5.7) : canal public et topic personnel du joueur ; le widget s'y abonne, le cookie
     * d'abonnement couvre aussi les topics du jeu.
     *
     * @return list<string>
     */
    #[AsTwigFunction('chat_topics')]
    public function chatTopics(): array
    {
        $empire = $this->context->empire();

        return null === $empire ? [] : [ChatTopics::PUBLIC, ChatTopics::user($empire->getUser())];
    }
}
