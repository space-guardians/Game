<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Model\Chat\ChatMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tampon circulaire des derniers messages de chaque canal, dans Redis (§5.7) : SIZE messages au plus, effacé après
 * TTL secondes sans message. Il sert seulement à retrouver le fil récent après un changement de page ou une
 * reconnexion brève ; aucune persistance en base.
 */
final readonly class ChatBuffer
{
    public const int SIZE = 50;

    public const int TTL = 3600;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private \Redis $redis,
        /** Préfixe des clés Redis (distinct en test, pour ne pas toucher au chat de développement) */
        #[Autowire('%app.chat.buffer_prefix%')]
        private string $prefix,
    ) {}

    public function push(ChatMessage $message): void
    {
        $key = $this->prefix . $message->channel;
        $this->redis->multi();
        $this->redis->lPush($key, json_encode($message, \JSON_THROW_ON_ERROR));
        $this->redis->lTrim($key, 0, self::SIZE - 1);
        $this->redis->expire($key, self::TTL);
        $this->redis->exec();
    }

    /** @return list<ChatMessage> du plus ancien au plus récent */
    public function recent(string $channel): array
    {
        $raw = $this->redis->lRange($this->prefix . $channel, 0, self::SIZE - 1);
        $messages = [];
        foreach (\is_array($raw) ? array_reverse($raw) : [] as $json) {
            $data = json_decode((string) $json, true);
            if (\is_array($data)) {
                $messages[] = ChatMessage::fromArray($data);
            }
        }

        return $messages;
    }

    /** Vide le tampon d'un canal (tests, modération) */
    public function clear(string $channel): void
    {
        $this->redis->del($this->prefix . $channel);
    }
}
