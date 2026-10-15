<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Enum\FairPlay\ActivityAction;
use App\Service\FairPlay\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Chaque connexion d'un joueur est notée au journal d'activité (§4.13), avec l'empreinte de son adresse.
 */
#[AsEventListener]
final readonly class LoginActivityRecorder
{
    public function __construct(
        private ActivityLogger $activity,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $this->activity->record($user, ActivityAction::Login);
        $this->entityManager->flush();
    }
}
