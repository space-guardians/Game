<?php

declare(strict_types=1);

namespace App\Service\FairPlay;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Enum\FairPlay\ActivityAction;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Journal d'activité et limite de comptes par adresse (§4.13). L'adresse IP de la requête n'est jamais stockée en
 * clair : seule son empreinte HMAC (clé = secret de l'application) l'est, assez pour reconnaître une même adresse.
 * Au plus `MAX_ACCOUNTS_PER_ADDRESS` inscriptions par adresse sur `REGISTRATION_WINDOW_DAYS` jours (provisoire).
 */
final readonly class ActivityLogger
{
    public const int MAX_ACCOUNTS_PER_ADDRESS = 3;

    public const int REGISTRATION_WINDOW_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ActivityLogRepository $logs,
        private RequestStack $requests,
        private ClockInterface $clock,
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {}

    /** Note l'action ; l'appelant enregistre (flush) */
    public function record(User $user, ActivityAction $action): ActivityLog
    {
        $log = new ActivityLog($user, $action, $this->currentAddress(), $this->clock->now());
        $this->entityManager->persist($log);

        return $log;
    }

    /** Raison d'un refus d'inscription depuis l'adresse de la requête, ou null */
    public function registrationRefusal(): ?string
    {
        $address = $this->currentAddress();
        if (null === $address) {
            return null;
        }
        $since = $this->clock->now()->modify(\sprintf('-%d days', self::REGISTRATION_WINDOW_DAYS));

        return $this->logs->countSince($address, ActivityAction::Registration, $since) >= self::MAX_ACCOUNTS_PER_ADDRESS
            ? 'Trop de comptes ont déjà été créés depuis cette connexion : un seul compte par joueur (règles de fair-play).'
            : null;
    }

    public function hash(string $ip): string
    {
        return hash_hmac('sha256', $ip, $this->secret);
    }

    private function currentAddress(): ?string
    {
        $ip = $this->requests->getMainRequest()?->getClientIp();

        return null === $ip ? null : $this->hash($ip);
    }
}
