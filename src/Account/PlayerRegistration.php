<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Crée le compte d'un nouveau joueur et lui envoie l'e-mail de bienvenue.
 */
final readonly class PlayerRegistration
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private AccountMailer $accountMailer,
    ) {}

    /**
     * @throws EmailAlreadyRegistered
     */
    public function register(Registration $registration): User
    {
        if (null !== $this->users->findOneByEmail($registration->email)) {
            throw new EmailAlreadyRegistered($registration->email);
        }

        $user = new User($registration->email, $this->clock->now());
        $user->setPassword($this->passwordHasher->hashPassword($user, $registration->plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->mailer->send($this->accountMailer->welcome($user));

        return $user;
    }
}
