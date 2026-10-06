<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /** Connexion : l'adresse saisie est normalisée comme à l'inscription (casse, espaces) */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneByEmail($identifier);
    }

    /**
     * Note l'activité du joueur directement en base : ni flush de l'unité de travail en cours, ni entrée
     * d'historique (§5.6.3) pour une simple date de passage.
     */
    public function recordActivity(User $user, \DateTimeImmutable $now): void
    {
        $this->getEntityManager()->createQuery('UPDATE ' . User::class . ' u SET u.lastActiveAt = :now WHERE u.id = :id')
            ->setParameter('now', $now)
            ->setParameter('id', $user->getId())
            ->execute();
    }

    public function countRegisteredSince(\DateTimeImmutable $since): int
    {
        return $this->countWhere('u.registeredAt >= :since', $since);
    }

    public function countActiveSince(\DateTimeImmutable $since): int
    {
        return $this->countWhere('u.lastActiveAt >= :since', $since);
    }

    /** Re-hache le mot de passe quand l'algorithme ou son coût évoluent */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    private function countWhere(string $condition, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where($condition)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
