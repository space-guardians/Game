<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRefused;
use App\Model\Chat\ChatMessage;
use App\Model\Chat\ChatTopics;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Chat en ligne (§4.12.1, §5.7) : le navigateur ne publie jamais sur le hub ; chaque message passe par ce service, qui
 * vérifie le canal, la longueur et le rythme d'envoi (RateLimiter), le garde dans le tampon du canal puis le diffuse.
 * Aucune persistance en base.
 */
final readonly class Chat
{
    public const string PUBLIC_CHANNEL = 'public';

    public const int MAX_LENGTH = 500;

    public function __construct(
        private ChatBuffer $buffer,
        private ChatPresence $presence,
        private HubInterface $hub,
        #[Autowire(service: 'limiter.chat_message')]
        private RateLimiterFactoryInterface $limiter,
        private ClockInterface $clock,
    ) {}

    /** @throws ChatRefused */
    public function post(Empire $author, string $channel, string $body): ChatMessage
    {
        if (self::PUBLIC_CHANNEL !== $channel) {
            throw new ChatRefused('Canal de discussion inconnu.');
        }
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ('' === $body) {
            throw new ChatRefused('Le message est vide.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new ChatRefused(\sprintf('Un message de chat compte au plus %d caractères.', self::MAX_LENGTH));
        }
        if (!$this->limiter->create('empire-' . $author->getId())->consume()->isAccepted()) {
            throw new ChatRefused('Vous écrivez trop vite : patientez quelques secondes.');
        }

        $message = new ChatMessage(bin2hex(random_bytes(8)), $channel, (int) $author->getId(), $author->getName(), $body, $this->clock->now());
        $this->buffer->push($message);
        // Écrire, c'est être là
        $this->presence->heartbeat($author);
        $this->hub->publish(new Update(ChatTopics::PUBLIC, json_encode($message, \JSON_THROW_ON_ERROR), private: true));

        return $message;
    }

    /**
     * Fil récent d'un canal accessible au joueur.
     *
     * @return list<ChatMessage>
     *
     * @throws ChatRefused
     */
    public function recent(Empire $viewer, string $channel): array
    {
        if (self::PUBLIC_CHANNEL !== $channel) {
            throw new ChatRefused('Canal de discussion inconnu.');
        }

        return $this->buffer->recent($channel);
    }
}
