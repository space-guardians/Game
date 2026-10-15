<?php

declare(strict_types=1);

namespace App\Service\FairPlay;

use App\Entity\Empire;
use App\Exception\Fleet\InvalidFleetMission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rythme des actions sensibles d'un joueur (§4.13) : envois de flotte (RateLimiter `fleet_dispatch`, fenêtre glissante
 * par empire). Les messages ont le leur dans `Messaging` (`message_send`).
 */
final readonly class ActionLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.fleet_dispatch')]
        private RateLimiterFactoryInterface $fleetDispatch,
    ) {}

    /** @throws InvalidFleetMission */
    public function fleetDispatch(Empire $empire): void
    {
        if (!$this->fleetDispatch->create('empire-' . $empire->getId())->consume()->isAccepted()) {
            throw new InvalidFleetMission('Trop d’envois de flotte en peu de temps : patientez quelques minutes.');
        }
    }
}
