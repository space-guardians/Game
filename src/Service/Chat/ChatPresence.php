<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Empire;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Présence des joueurs (§4.12.1, §5.7) : battement de cœur du widget de chat, gardé dans Redis (ensemble trié par
 * dernier signe de vie). Un joueur est en ligne tant que son dernier battement date de moins de TTL secondes.
 */
final readonly class ChatPresence
{
    /** Intervalle des battements envoyés par le widget */
    public const int HEARTBEAT_SECONDS = 30;

    /** Absence de battement au-delà de laquelle le joueur est hors ligne */
    public const int TTL = 90;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private \Redis $redis,
        #[Autowire('%app.chat.presence_key%')]
        private string $key,
        private ClockInterface $clock,
    ) {}

    public function heartbeat(Empire $empire): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->redis->zAdd($this->key, $now, (string) $empire->getId());
        // Ménage des absents, au passage
        $this->redis->zRemRangeByScore($this->key, '-inf', (string) ($now - self::TTL));
    }

    /** Le joueur quitte le jeu (déconnexion) : hors ligne aussitôt */
    public function leave(Empire $empire): void
    {
        $this->redis->zRem($this->key, (string) $empire->getId());
    }

    public function isOnline(Empire|int $empire): bool
    {
        $score = $this->redis->zScore($this->key, (string) ($empire instanceof Empire ? $empire->getId() : $empire));

        return false !== $score && $score > $this->clock->now()->getTimestamp() - self::TTL;
    }

    /**
     * Identifiants des empires en ligne.
     *
     * @return list<int>
     */
    public function onlineIds(): array
    {
        $ids = $this->redis->zRangeByScore($this->key, (string) ($this->clock->now()->getTimestamp() - self::TTL + 1), '+inf');

        return array_map('intval', \is_array($ids) ? $ids : []);
    }

    /**
     * En ligne ou non, pour chacun des empires donnés.
     *
     * @param list<int> $ids
     *
     * @return array<int, bool>
     */
    public function statuses(array $ids): array
    {
        $online = array_flip($this->onlineIds());
        $statuses = [];
        foreach ($ids as $id) {
            $statuses[$id] = isset($online[$id]);
        }

        return $statuses;
    }

    /** Vide la présence (tests) */
    public function clear(): void
    {
        $this->redis->del($this->key);
    }
}
