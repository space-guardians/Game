<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un joueur banni (§4.13) ne se connecte plus ; il lit le motif et la fin éventuelle du bannissement.
 */
final readonly class BannedUserChecker implements UserCheckerInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {}

    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $user->isBannedAt($this->clock->now())) {
            throw new CustomUserMessageAccountStatusException(self::message($user));
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void {}

    public static function message(User $user): string
    {
        $end = $user->getBanEndsAt();

        return \sprintf('Ce compte est banni %s. Motif : %s', null === $end ? 'définitivement' : 'jusqu’au ' . $end->format('d/m/Y à H:i'), (string) $user->getBanReason());
    }
}
