<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\AdminUser;
use App\Entity\BuildingQueueItem;
use App\Entity\BuildingType;
use App\Entity\Empire;
use App\Entity\Galaxy;
use App\Entity\GalaxyShapeTemplate;
use App\Entity\PlanetBuilding;
use App\Entity\Prerequisite;
use App\Entity\Research;
use App\Entity\ResearchQueueItem;
use App\Entity\ShipClass;
use App\Entity\ShipType;
use App\Entity\Technology;
use App\Entity\User;
use App\Enum\Admin\AuditOrigin;
use App\Model\Admin\AuditedEntity;
use App\Model\Admin\HistoryFilters;
use DH\Auditor\Model\Entry;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lecture de l'historique des modifications enregistré par l'auditeur (§5.6.3).
 *
 * Interroge directement les tables d'audit plutôt que le Reader du bundle, qui ne sait filtrer ni par auteur
 * ni par origine (pare-feu).
 */
final readonly class EntityHistory
{
    public const int PAGE_SIZE = 50;

    /** Libellés des entités auditées ; une entité auditée sans libellé est signalée par un test */
    public const array LABELS = [
        User::class => 'Joueurs',
        AdminUser::class => 'Comptes d’administration',
        BuildingQueueItem::class => 'Constructions en cours',
        BuildingType::class => 'Types de bâtiments',
        Empire::class => 'Empires',
        Galaxy::class => 'Galaxies',
        GalaxyShapeTemplate::class => 'Gabarits de forme',
        PlanetBuilding::class => 'Bâtiments des planètes',
        Prerequisite::class => 'Prérequis',
        Research::class => 'Recherches des empires',
        ResearchQueueItem::class => 'Recherches en cours',
        ShipClass::class => 'Classes de vaisseaux',
        ShipType::class => 'Types de vaisseaux',
        Technology::class => 'Technologies',
    ];

    public const array TYPE_LABELS = [
        TransactionType::INSERT => 'Création',
        TransactionType::UPDATE => 'Modification',
        TransactionType::REMOVE => 'Suppression',
        TransactionType::ASSOCIATE => 'Ajout à une relation',
        TransactionType::DISSOCIATE => 'Retrait d’une relation',
    ];

    public function __construct(
        #[Autowire(service: 'dh_auditor.provider.doctrine')]
        private DoctrineProvider $provider,
    ) {}

    /**
     * Entités auditées, par libellé.
     *
     * @return list<AuditedEntity>
     */
    public function entities(): array
    {
        $configuration = $this->provider->getConfiguration();
        \assert($configuration instanceof Configuration);
        $reader = new Reader($this->provider);

        $entities = [];
        foreach (array_keys($configuration->getEntities()) as $class) {
            /** @var class-string $class */
            $entities[] = new AuditedEntity(
                class: $class,
                key: $reader->getEntityTableName($class),
                label: self::LABELS[$class] ?? new \ReflectionClass($class)->getShortName(),
                auditTable: $reader->getEntityAuditTableName($class),
            );
        }
        usort($entities, static fn(AuditedEntity $a, AuditedEntity $b): int => strcoll($a->label, $b->label));

        return $entities;
    }

    public function entity(string $key): ?AuditedEntity
    {
        foreach ($this->entities() as $entity) {
            if ($entity->key === $key) {
                return $entity;
            }
        }

        return null;
    }

    /** @return array{count: int, last: ?\DateTimeImmutable} */
    public function summary(AuditedEntity $entity): array
    {
        $row = $this->connection($entity)->createQueryBuilder()
            ->select('COUNT(id) AS total', 'MAX(created_at) AS last')
            ->from($entity->auditTable)
            ->executeQuery()
            ->fetchAssociative();
        \assert(false !== $row);

        return [
            'count' => (int) $row['total'],
            'last' => \is_string($row['last']) ? $this->date($row['last']) : null,
        ];
    }

    /**
     * Page de l'historique d'une entité, de la plus récente à la plus ancienne modification.
     *
     * @return array{entries: list<Entry>, total: int, pages: int, page: int}
     */
    public function search(AuditedEntity $entity, HistoryFilters $filters, int $page = 1): array
    {
        $query = $this->filtered($entity, $filters);
        $total = (int) (clone $query)->select('COUNT(id)')->executeQuery()->fetchOne();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        $rows = $query
            ->select('*')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->executeQuery()
            ->fetchAllAssociative();

        return ['entries' => array_map($this->hydrate(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    public function find(AuditedEntity $entity, int $id): ?Entry
    {
        $row = $this->connection($entity)->createQueryBuilder()
            ->select('*')
            ->from($entity->auditTable)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * Toutes les modifications d'une même transaction (même flush), toutes entités confondues.
     *
     * @return list<array{entity: AuditedEntity, entry: Entry}>
     */
    public function transaction(string $hash): array
    {
        $result = [];
        foreach ($this->entities() as $entity) {
            $rows = $this->connection($entity)->createQueryBuilder()
                ->select('*')
                ->from($entity->auditTable)
                ->where('transaction_hash = :hash')
                ->setParameter('hash', $hash)
                ->orderBy('id')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $result[] = ['entity' => $entity, 'entry' => $this->hydrate($row)];
            }
        }

        return $result;
    }

    /**
     * Dernières modifications faites par un compte, toutes entités confondues (journal d'activité d'un joueur).
     *
     * @param string $firewall pare-feu de l'auteur (AuditOrigin), l'identifiant n'étant unique que par type de compte
     *
     * @return list<array{entity: AuditedEntity, entry: Entry}>
     */
    public function byAuthor(int $authorId, string $firewall, int $limit = self::PAGE_SIZE): array
    {
        $result = [];
        foreach ($this->entities() as $entity) {
            $rows = $this->connection($entity)->createQueryBuilder()
                ->select('*')
                ->from($entity->auditTable)
                ->where('blame_id = :author')
                ->andWhere('blame_user_firewall = :firewall')
                ->setParameter('author', (string) $authorId)
                ->setParameter('firewall', $firewall)
                ->orderBy('created_at', 'DESC')
                ->addOrderBy('id', 'DESC')
                ->setMaxResults($limit)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $result[] = ['entity' => $entity, 'entry' => $this->hydrate($row)];
            }
        }
        usort($result, static fn(array $a, array $b): int => $b['entry']->createdAt <=> $a['entry']->createdAt ?: $b['entry']->id <=> $a['entry']->id);

        return \array_slice($result, 0, $limit);
    }

    private function filtered(AuditedEntity $entity, HistoryFilters $filters): QueryBuilder
    {
        $query = $this->connection($entity)->createQueryBuilder()->from($entity->auditTable);

        if (null !== $filters->type) {
            $query->andWhere('type = :type')->setParameter('type', $filters->type->value);
        }
        if (null !== $filters->objectId) {
            $query->andWhere('object_id = :object')->setParameter('object', $filters->objectId);
        }
        if (null !== $filters->author) {
            $query->andWhere('blame_user ILIKE :author')->setParameter('author', '%' . addcslashes($filters->author, '%_\\') . '%');
        }
        if (AuditOrigin::System === $filters->origin) {
            $query->andWhere('blame_user_firewall IS NULL OR blame_user_firewall NOT IN (:firewalls)')
                ->setParameter('firewalls', [AuditOrigin::Admin->value, AuditOrigin::Player->value], ArrayParameterType::STRING);
        } elseif (null !== $filters->origin) {
            $query->andWhere('blame_user_firewall = :firewall')->setParameter('firewall', $filters->origin->value);
        }
        if (null !== $filters->from) {
            $query->andWhere('created_at >= :from')->setParameter('from', $filters->from->format('Y-m-d H:i:s'));
        }
        if (null !== $filters->to) {
            $query->andWhere('created_at < :to')->setParameter('to', $filters->to->modify('+1 day')->format('Y-m-d H:i:s'));
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Entry
    {
        \assert(\is_string($row['created_at']));
        $row['created_at'] = $this->date($row['created_at']);

        return Entry::fromArray($row);
    }

    private function date(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone($this->provider->getAuditor()->getConfiguration()->timezone));
    }

    private function connection(AuditedEntity $entity): Connection
    {
        return $this->provider->getStorageServiceForEntity($entity->class)->getEntityManager()->getConnection();
    }
}
