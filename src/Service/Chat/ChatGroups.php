<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRecipientOffline;
use App\Exception\Chat\ChatRefused;
use App\Model\Chat\ChatGroup;
use App\Model\Chat\ChatTopics;
use App\Repository\EmpireRepository;
use App\Service\Messaging\IgnoredPlayers;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Groupes de chat (§4.12.1, §5.7), éphémères : composition gardée dans Redis, effacée après INACTIVITY secondes sans
 * activité ou au départ du dernier participant. Le créateur ajoute des joueurs connectés et peut en retirer ; chacun
 * peut partir (un créateur qui part passe la main). Chaque changement est annoncé aux participants sur leur topic
 * personnel.
 */
final readonly class ChatGroups
{
    public const int INACTIVITY = 7200;

    public const int NAME_MAX_LENGTH = 40;

    public const int MAX_MEMBERS = 20;

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private \Redis $redis,
        #[Autowire('%app.chat.groups_prefix%')]
        private string $prefix,
        private EmpireRepository $empires,
        private ChatPresence $presence,
        private HubInterface $hub,
        private IgnoredPlayers $ignored,
    ) {}

    /** @throws ChatRefused */
    public function create(Empire $owner, string $name): ChatGroup
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new ChatRefused(\sprintf('Le nom du groupe compte de 1 à %d caractères.', self::NAME_MAX_LENGTH));
        }
        $id = bin2hex(random_bytes(6));
        $this->redis->hMSet($this->key($id), ['name' => $name, 'owner' => (string) $owner->getId()]);
        $this->join($id, (int) $owner->getId());
        $group = $this->get($id) ?? throw new ChatRefused('Groupe introuvable.');
        $this->announce($group);

        return $group;
    }

    /**
     * Le créateur ajoute un joueur connecté, désigné par le nom de son empire.
     *
     * @throws ChatRefused
     * @throws ChatRecipientOffline
     */
    public function add(Empire $by, string $groupId, string $name): ChatGroup
    {
        $group = $this->owned($by, $groupId);
        $empire = $this->empires->findOneByName(trim($name)) ?? throw new ChatRefused(\sprintf('Aucun empire nommé « %s ».', trim($name)));
        if ($group->has((int) $empire->getId())) {
            throw new ChatRefused(\sprintf('%s participe déjà au groupe.', $empire->getName()));
        }
        if (\count($group->members) >= self::MAX_MEMBERS) {
            throw new ChatRefused(\sprintf('Un groupe compte au plus %d participants.', self::MAX_MEMBERS));
        }
        if ($this->ignored->blocks($empire, $by)) {
            // Un joueur ignoré ne peut ajouter celui qui l'ignore (§4.12.4)
            throw new ChatRefused(\sprintf('%s n’accepte pas vos invitations.', $empire->getName()));
        }
        if (!$this->presence->isOnline($empire)) {
            throw new ChatRecipientOffline($empire);
        }
        $this->join($groupId, (int) $empire->getId());
        $group = $this->get($groupId) ?? throw new ChatRefused('Groupe introuvable.');
        $this->announce($group);

        return $group;
    }

    /** @throws ChatRefused */
    public function remove(Empire $by, string $groupId, int $empireId): ChatGroup
    {
        $group = $this->owned($by, $groupId);
        if ($empireId === (int) $by->getId()) {
            throw new ChatRefused('Pour sortir du groupe, quittez-le.');
        }
        if (!$group->has($empireId)) {
            throw new ChatRefused('Ce joueur ne participe pas au groupe.');
        }

        return $this->drop($group, $empireId) ?? throw new ChatRefused('Groupe introuvable.');
    }

    /**
     * Départ volontaire ; renvoie le groupe restant, ou null s'il a disparu avec son dernier participant.
     *
     * @throws ChatRefused
     */
    public function leave(Empire $empire, string $groupId): ?ChatGroup
    {
        return $this->drop($this->memberOf($empire, $groupId), (int) $empire->getId());
    }

    /**
     * Groupe dont le joueur fait partie.
     *
     * @throws ChatRefused
     */
    public function memberOf(Empire $empire, string $groupId): ChatGroup
    {
        $group = $this->get($groupId);
        if (null === $group || !$group->has((int) $empire->getId())) {
            throw new ChatRefused('Ce groupe n’existe plus ou vous n’en faites pas partie.');
        }

        return $group;
    }

    /** @return list<ChatGroup> groupes du joueur encore actifs */
    public function of(Empire $empire): array
    {
        $index = $this->memberKey((int) $empire->getId());
        $groups = [];
        foreach ($this->members($index) as $groupId) {
            $group = $this->get((string) $groupId);
            if (null === $group || !$group->has((int) $empire->getId())) {
                // Groupe expiré, ou joueur retiré : index nettoyé au passage
                $this->redis->sRem($index, (string) $groupId);
                continue;
            }
            $groups[] = $group;
        }

        return $groups;
    }

    public function get(string $groupId): ?ChatGroup
    {
        $data = $this->redis->hGetAll($this->key($groupId));
        if (!\is_array($data) || !isset($data['name'], $data['owner'])) {
            return null;
        }
        $ids = array_map('intval', $this->members($this->key($groupId) . ':members'));
        $members = [];
        foreach ([] === $ids ? [] : $this->empires->findBy(['id' => $ids], ['name' => 'ASC']) as $member) {
            $members[(int) $member->getId()] = $member->getName();
        }

        return new ChatGroup($groupId, (string) $data['name'], (int) $data['owner'], $members);
    }

    /** Activité dans le groupe (message, changement) : son expiration repart */
    public function touch(string $groupId): void
    {
        $this->redis->expire($this->key($groupId), self::INACTIVITY);
        $this->redis->expire($this->key($groupId) . ':members', self::INACTIVITY);
    }

    /**
     * Topics personnels des participants (fan-out des messages du groupe), hormis ceux des empires exclus.
     *
     * @param list<int> $except
     *
     * @return list<string>
     */
    public function topics(ChatGroup $group, array $except = []): array
    {
        $topics = [];
        $ids = array_values(array_diff(array_keys($group->members), $except));
        foreach ([] === $ids ? [] : $this->empires->findBy(['id' => $ids]) as $member) {
            $topics[] = ChatTopics::user($member->getUser());
        }

        return $topics;
    }

    /** Efface un groupe (tests) */
    public function delete(string $groupId): void
    {
        $this->redis->del($this->key($groupId), $this->key($groupId) . ':members');
    }

    private function join(string $groupId, int $empireId): void
    {
        $this->redis->sAdd($this->key($groupId) . ':members', (string) $empireId);
        $this->redis->sAdd($this->memberKey($empireId), $groupId);
        $this->redis->expire($this->memberKey($empireId), self::INACTIVITY);
        $this->touch($groupId);
    }

    /** Retire un participant et prévient chacun ; le groupe disparaît avec son dernier participant */
    private function drop(ChatGroup $group, int $empireId): ?ChatGroup
    {
        $this->redis->sRem($this->key($group->id) . ':members', (string) $empireId);
        $this->redis->sRem($this->memberKey($empireId), $group->id);
        $gone = $this->empires->find($empireId);
        if (null !== $gone) {
            $this->publish([ChatTopics::user($gone->getUser())], ['type' => 'group_left', 'id' => $group->id, 'channel' => $group->channel()]);
        }
        $remaining = array_diff(array_keys($group->members), [$empireId]);
        if ([] === $remaining) {
            $this->delete($group->id);

            return null;
        }
        if ($empireId === $group->ownerId) {
            // Le créateur part : un participant restant prend la main
            $this->redis->hSet($this->key($group->id), 'owner', (string) reset($remaining));
        }
        $this->touch($group->id);
        $updated = $this->get($group->id);
        if (null !== $updated) {
            $this->announce($updated);
        }

        return $updated;
    }

    /** @throws ChatRefused */
    private function owned(Empire $by, string $groupId): ChatGroup
    {
        $group = $this->memberOf($by, $groupId);
        if ($group->ownerId !== (int) $by->getId()) {
            throw new ChatRefused('Seul le créateur du groupe gère ses participants.');
        }

        return $group;
    }

    /** Composition du groupe envoyée à chaque participant (création ou mise à jour de son onglet) */
    private function announce(ChatGroup $group): void
    {
        $this->publish($this->topics($group), ['type' => 'group', 'group' => $group]);
    }

    /**
     * @param list<string>         $topics
     * @param array<string, mixed> $payload
     */
    private function publish(array $topics, array $payload): void
    {
        if ([] !== $topics) {
            $this->hub->publish(new Update($topics, json_encode($payload, \JSON_THROW_ON_ERROR), private: true));
        }
    }

    /** @return list<string> */
    private function members(string $key): array
    {
        $members = $this->redis->sMembers($key);

        return \is_array($members) ? array_map('strval', $members) : [];
    }

    private function key(string $groupId): string
    {
        return $this->prefix . 'group:' . $groupId;
    }

    private function memberKey(int $empireId): string
    {
        return $this->prefix . 'member:' . $empireId;
    }
}
