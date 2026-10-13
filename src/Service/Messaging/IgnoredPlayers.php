<?php

declare(strict_types=1);

namespace App\Service\Messaging;

use App\Entity\Empire;
use App\Entity\PlayerIgnore;
use App\Exception\Messaging\InvalidMessage;
use App\Model\Chat\ChatTopics;
use App\Repository\EmpireRepository;
use App\Repository\PlayerIgnoreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Liste des joueurs ignorés (§4.12.4), commune au chat et à la messagerie. Chaque changement est envoyé au widget de
 * chat du joueur (topic personnel), qui masque aussitôt les messages des joueurs ignorés dans les canaux partagés.
 */
final readonly class IgnoredPlayers
{
    /** Joueurs ignorés au plus par empire (provisoire) */
    public const int MAX = 200;

    public function __construct(
        private PlayerIgnoreRepository $ignores,
        private EmpireRepository $empires,
        private EntityManagerInterface $entityManager,
        private HubInterface $hub,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidMessage */
    public function ignore(Empire $empire, string $name): Empire
    {
        $name = trim($name);
        $other = $this->empires->findOneByName($name) ?? throw new InvalidMessage(\sprintf('Aucun joueur ne s’appelle « %s ».', $name));
        if ($other === $empire) {
            throw new InvalidMessage('Vous ne pouvez pas vous ignorer vous-même.');
        }
        if ($this->ignores->ignores($empire, $other)) {
            throw new InvalidMessage(\sprintf('Vous ignorez déjà %s.', $other->getName()));
        }
        if (\count($this->ignores->ignoredIdsOf($empire)) >= self::MAX) {
            throw new InvalidMessage(\sprintf('Vous ignorez déjà %d joueurs, le maximum.', self::MAX));
        }
        $this->entityManager->persist(new PlayerIgnore($empire, $other, $this->clock->now()));
        $this->entityManager->flush();
        $this->announce($empire);

        return $other;
    }

    /** @throws InvalidMessage */
    public function unignore(Empire $empire, int $ignoredId): Empire
    {
        $ignore = $this->ignores->findOneBy(['empire' => $empire, 'ignored' => $ignoredId]) ?? throw new InvalidMessage('Vous n’ignorez pas ce joueur.');
        $this->entityManager->remove($ignore);
        $this->entityManager->flush();
        $this->announce($empire);

        return $ignore->getIgnored();
    }

    /** Le destinataire ignore-t-il l'auteur ? */
    public function blocks(Empire $recipient, Empire $author): bool
    {
        return $this->ignores->ignores($recipient, $author);
    }

    /**
     * Parmi ces empires, ceux qui ignorent l'auteur (et ne doivent pas recevoir ses messages).
     *
     * @param list<int> $empireIds
     *
     * @return list<int>
     */
    public function ignoring(Empire $author, array $empireIds): array
    {
        return $this->ignores->ignoringAmong($author, $empireIds);
    }

    /** @return list<int> */
    public function idsOf(Empire $empire): array
    {
        return $this->ignores->ignoredIdsOf($empire);
    }

    /** Liste à jour envoyée au widget de chat du joueur */
    private function announce(Empire $empire): void
    {
        $this->hub->publish(new Update(
            ChatTopics::user($empire->getUser()),
            json_encode(['type' => 'ignored', 'ids' => $this->idsOf($empire)], \JSON_THROW_ON_ERROR),
            private: true,
        ));
    }
}
