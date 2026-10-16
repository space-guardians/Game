<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Model\Economy\Resources;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Verrou d'une planète (§5.2) : le même que celui de la résolution de ses événements planifiés, partagé entre les
 * requêtes web et le worker (magasin Redis, LOCK_DSN).
 *
 * Une fois le verrou pris, le stock de la planète est relu en base. L'entité chargée plus tôt (au début d'une
 * requête) peut être périmée : le worker a pu créditer ou débiter le stock entre-temps (livraison d'un transport,
 * pillage…), et le recalculer depuis l'ancienne valeur effacerait ses changements. Le stock est le seul état de la
 * planète que les deux côtés écrivent ; Doctrine n'écrit que les champs modifiés, les autres ne risquent rien.
 */
final readonly class PlanetLocks
{
    public const float TTL = 30.0;

    private const array STOCK = ['metal', 'crystal', 'deuterium', 'resourcesUpdatedAt'];

    public function __construct(
        private LockFactory $lockFactory,
        private EntityManagerInterface $entityManager,
    ) {}

    /** Attend le verrou de la planète, puis relit son stock ; à libérer par l'appelant (release) */
    public function acquire(Planet $planet): LockInterface
    {
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: self::TTL);
        $lock->acquire(true);
        $this->reloadStock($planet);

        return $lock;
    }

    /** Verrou d'une flotte : celui de sa planète si elle y stationne (stock relu), le sien ailleurs (dans l'espace) */
    public function acquireForFleet(Fleet $fleet): LockInterface
    {
        $planet = $fleet->getPlanet();
        if (null !== $planet) {
            return $this->acquire($planet);
        }
        $lock = $this->lockFactory->createLock('fleet-' . $fleet->getId(), ttl: self::TTL);
        $lock->acquire(true);

        return $lock;
    }

    private function reloadStock(Planet $planet): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        if (!$unitOfWork->isInIdentityMap($planet)) {
            // Pas encore enregistrée : rien à relire
            return;
        }
        // Lu d'abord : charge un proxy pas encore initialisé, dont les données d'origine sont alors connues
        $current = ['metal' => $planet->getResources()->metal, 'crystal' => $planet->getResources()->crystal, 'deuterium' => $planet->getResources()->deuterium, 'resourcesUpdatedAt' => $planet->getResourcesUpdatedAt()];
        $original = $unitOfWork->getOriginalEntityData($planet);
        foreach (self::STOCK as $field) {
            if (!\array_key_exists($field, $original) || $original[$field] != $current[$field]) {
                // Stock déjà modifié par l'appelant, pas encore écrit : c'est le sien, on ne l'écrase pas
                return;
            }
        }

        /** @var array{metal: float, crystal: float, deuterium: float, resourcesUpdatedAt: \DateTimeImmutable|null} $stock */
        $stock = $this->entityManager->createQuery(\sprintf('SELECT p.metal, p.crystal, p.deuterium, p.resourcesUpdatedAt FROM %s p WHERE p.id = :id', Planet::class))
            ->setParameter('id', $planet->getId())
            ->getSingleResult();
        if (null === $stock['resourcesUpdatedAt']) {
            return;
        }
        $planet->storeResources(new Resources($stock['metal'], $stock['crystal'], $stock['deuterium']), $stock['resourcesUpdatedAt']);
        // Valeur lue en base : ce n'est pas une modification à écrire
        $oid = spl_object_id($planet);
        foreach (self::STOCK as $field) {
            $unitOfWork->setOriginalEntityProperty($oid, $field, 'resourcesUpdatedAt' === $field ? $planet->getResourcesUpdatedAt() : $stock[$field]);
        }
    }
}
