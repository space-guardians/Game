<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\Empire;
use App\Entity\User;
use App\Exception\Account\EmailAlreadyRegistered;
use App\Exception\Account\EmpireNameTaken;
use App\Exception\Universe\NoFreePlanet;
use App\Model\Account\Registration;
use App\Repository\EmpireRepository;
use App\Repository\UserRepository;
use App\Service\Economy\PlanetEconomy;
use App\Service\Universe\HomePlanetAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée le compte d'un nouveau joueur avec son empire et sa planète mère (§2.4, §4.1), puis lui envoie l'e-mail
 * de bienvenue.
 */
final readonly class PlayerRegistration
{
    /** Une inscription à la fois choisit une planète et un nom : pas de doublon entre inscriptions simultanées */
    private const string LOCK = 'player-registration';

    public function __construct(
        private UserRepository $users,
        private EmpireRepository $empires,
        private HomePlanetAllocator $homePlanets,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private AccountMailer $accountMailer,
        private LockFactory $lockFactory,
        private PlanetEconomy $economy,
    ) {}

    /**
     * @throws EmailAlreadyRegistered
     * @throws EmpireNameTaken
     * @throws NoFreePlanet
     */
    public function register(Registration $registration): User
    {
        \assert(null !== $registration->orientation);
        $lock = $this->lockFactory->createLock(self::LOCK, ttl: 30.0);
        $lock->acquire(true);

        try {
            if (null !== $this->users->findOneByEmail($registration->email)) {
                throw new EmailAlreadyRegistered($registration->email);
            }
            if ($this->empires->nameExists($registration->empireName)) {
                throw new EmpireNameTaken($registration->empireName);
            }

            $now = $this->clock->now();
            $user = new User($registration->email, $now);
            $user->setPassword($this->passwordHasher->hashPassword($user, $registration->plainPassword));
            $homePlanet = $this->homePlanets->allocate($registration->orientation);
            $empire = new Empire($user, $registration->empireName, $registration->orientation, $homePlanet, $now);
            // La planète mère commence à produire à la fondation, avec la dotation de départ
            $homePlanet->storeResources($this->economy->startingResources(), $now);

            $this->entityManager->persist($user);
            $this->entityManager->persist($empire);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->mailer->send($this->accountMailer->welcome($user));

        return $user;
    }
}
