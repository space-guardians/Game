<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Alliance;
use App\Entity\Empire;
use App\Exception\Chat\ChatRecipientOffline;
use App\Exception\Chat\ChatRefused;
use App\Model\Chat\ChatMessage;
use App\Model\Chat\ChatTopics;
use App\Model\Chat\PrivateChat;
use App\Repository\AllianceMemberRepository;
use App\Repository\EmpireRepository;
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

    public const string GROUP_PREFIX = 'group:';

    public const string ALLIANCE_PREFIX = 'alliance:';

    public function __construct(
        private ChatBuffer $buffer,
        private ChatPresence $presence,
        private HubInterface $hub,
        #[Autowire(service: 'limiter.chat_message')]
        private RateLimiterFactoryInterface $limiter,
        private ClockInterface $clock,
        private EmpireRepository $empires,
        private ChatGroups $groups,
        private AllianceMemberRepository $members,
    ) {}

    /**
     * @throws ChatRefused
     * @throws ChatRecipientOffline
     */
    public function post(Empire $author, string $channel, string $body): ChatMessage
    {
        $group = str_starts_with($channel, self::GROUP_PREFIX) ? $this->groups->memberOf($author, substr($channel, \strlen(self::GROUP_PREFIX))) : null;
        $alliance = str_starts_with($channel, self::ALLIANCE_PREFIX) ? $this->allianceOf($author, $channel) : null;
        $recipient = self::PUBLIC_CHANNEL === $channel || null !== $group || null !== $alliance ? null : $this->privatePeer($author, $channel);
        if (null !== $recipient && !$this->presence->isOnline($recipient)) {
            throw new ChatRecipientOffline($recipient);
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

        $message = new ChatMessage(bin2hex(random_bytes(8)), $channel, (int) $author->getId(), $author->getName(), $body, $this->clock->now(), $recipient?->getId(), $recipient?->getName());
        $this->buffer->push($message);
        // Écrire, c'est être là
        $this->presence->heartbeat($author);
        $data = json_encode($message, \JSON_THROW_ON_ERROR);
        if (null !== $alliance) {
            // Alliance : sur le topic personnel de chaque membre actuel (fan-out serveur, §5.7) ; un joueur parti ou
            // exclu ne reçoit plus rien
            $topics = array_map(static fn($member): string => ChatTopics::user($member->getEmpire()->getUser()), $alliance->getMembers()->toArray());
            $this->hub->publish(new Update(array_values($topics), $data, private: true));
        } elseif (null !== $group) {
            // Groupe : sur le topic personnel de chaque participant (fan-out serveur, §5.7) ; il reste actif
            $this->groups->touch($group->id);
            $this->hub->publish(new Update($this->groups->topics($group), $data, private: true));
        } elseif (null === $recipient) {
            $this->hub->publish(new Update(ChatTopics::PUBLIC, $data, private: true));
        } else {
            // Message privé : sur le topic personnel des deux joueurs (fan-out serveur, §5.7)
            $this->hub->publish(new Update([ChatTopics::user($author->getUser()), ChatTopics::user($recipient->getUser())], $data, private: true));
        }

        return $message;
    }

    /**
     * Ouvre une discussion privée avec un joueur connecté, désigné par le nom de son empire.
     *
     * @throws ChatRefused
     * @throws ChatRecipientOffline
     */
    public function openPrivate(Empire $author, string $name): PrivateChat
    {
        $other = $this->empires->findOneByName(trim($name)) ?? throw new ChatRefused(\sprintf('Aucun empire nommé « %s ».', trim($name)));
        if ($other === $author) {
            throw new ChatRefused('On ne discute pas avec soi-même.');
        }
        if (!$this->presence->isOnline($other)) {
            throw new ChatRecipientOffline($other);
        }

        return new PrivateChat(self::privateChannel($author, $other), (int) $other->getId(), $other->getName());
    }

    /** Canal de l'alliance du joueur, ou null s'il n'en a pas */
    public function allianceChannel(Empire $empire): ?string
    {
        $alliance = $this->members->findOneByEmpire($empire)?->getAlliance();

        return null === $alliance ? null : self::ALLIANCE_PREFIX . $alliance->getId();
    }

    /**
     * Alliance du canal, dont le joueur doit être membre au moment même (§4.10).
     *
     * @throws ChatRefused
     */
    private function allianceOf(Empire $empire, string $channel): Alliance
    {
        $alliance = $this->members->findOneByEmpire($empire)?->getAlliance();
        if (null === $alliance || self::ALLIANCE_PREFIX . $alliance->getId() !== $channel) {
            throw new ChatRefused('Vous ne faites pas partie de cette alliance.');
        }

        return $alliance;
    }

    /** Canal d'une discussion privée : le même pour les deux joueurs */
    public static function privateChannel(Empire $a, Empire $b): string
    {
        $ids = [(int) $a->getId(), (int) $b->getId()];
        sort($ids);

        return \sprintf('private:%d-%d', ...$ids);
    }

    /**
     * Interlocuteur d'une discussion privée dont le joueur fait partie.
     *
     * @throws ChatRefused
     */
    private function privatePeer(Empire $viewer, string $channel): Empire
    {
        if (1 !== preg_match('/^private:(\d+)-(\d+)$/', $channel, $ids) || $ids[1] >= $ids[2]) {
            throw new ChatRefused('Canal de discussion inconnu.');
        }
        $viewerId = (int) $viewer->getId();
        $otherId = match ($viewerId) {
            (int) $ids[1] => (int) $ids[2],
            (int) $ids[2] => (int) $ids[1],
            default => throw new ChatRefused('Canal de discussion inconnu.'),
        };

        return $this->empires->find($otherId) ?? throw new ChatRefused('Ce joueur n’existe plus.');
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
        if (str_starts_with($channel, self::GROUP_PREFIX)) {
            // Groupe : seulement pour ses participants
            $this->groups->memberOf($viewer, substr($channel, \strlen(self::GROUP_PREFIX)));
        } elseif (str_starts_with($channel, self::ALLIANCE_PREFIX)) {
            // Alliance : seulement pour ses membres actuels
            $this->allianceOf($viewer, $channel);
        } elseif (self::PUBLIC_CHANNEL !== $channel) {
            // Discussion privée : seulement si le joueur en fait partie
            $this->privatePeer($viewer, $channel);
        }

        return $this->buffer->recent($channel);
    }
}
