<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Entity\User;
use App\Model\Economy\ResourceSnapshot;
use App\Repository\EmpireRepository;
use App\Repository\PlanetRepository;
use App\Service\Economy\PlanetResources;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Empire du joueur connecté et ses planètes, chargés une fois par requête (barre du haut, navigation, écrans).
 */
final class GameContext implements ResetInterface
{
    private ?Empire $empire = null;
    private bool $empireLoaded = false;

    /** @var list<Planet>|null */
    private ?array $planets = null;

    private ?ResourceSnapshot $resources = null;

    public function __construct(
        private readonly Security $security,
        private readonly EmpireRepository $empires,
        private readonly PlanetRepository $planetRepository,
        private readonly PlanetResources $planetResources,
    ) {}

    public function empire(): ?Empire
    {
        if (!$this->empireLoaded) {
            $user = $this->security->getUser();
            $this->empire = $user instanceof User ? $this->empires->findOneByUser($user) : null;
            $this->empireLoaded = true;
        }

        return $this->empire;
    }

    /** @return list<Planet> */
    public function planets(): array
    {
        $empire = $this->empire();

        return $this->planets ??= null === $empire ? [] : $this->planetRepository->findOwnedBy($empire);
    }

    /** Ressources de la planète active, calculées à la demande (sans écriture) */
    public function activeResources(): ?ResourceSnapshot
    {
        $empire = $this->empire();

        return $this->resources ??= null === $empire ? null : $this->planetResources->snapshot($empire->getActivePlanet());
    }

    public function reset(): void
    {
        $this->resources = null;
        $this->empire = null;
        $this->empireLoaded = false;
        $this->planets = null;
    }
}
