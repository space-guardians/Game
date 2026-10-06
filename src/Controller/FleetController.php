<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Fleet;
use App\Exception\Fleet\InvalidFleetComposition;
use App\Repository\FleetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Account\GameContext;
use App\Service\Fleet\FleetAssembly;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Flotte » (§4.5) : hangar de la planète active (vaisseaux en inventaire), constitution d'une flotte à partir
 * de cet inventaire, flottes de l'empire et dissolution d'une flotte stationnée.
 */
final class FleetController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly ShipTypeRepository $shipTypes,
        private readonly FleetRepository $fleets,
        private readonly FleetAssembly $assembly,
    ) {}

    #[Route('/flotte', name: 'app_fleet', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $planet = $empire->getActivePlanet();

        $hangar = [];
        foreach ($this->shipTypes->findAllOrdered() as $type) {
            $count = $planet->shipCount($type);
            if ($count > 0) {
                $hangar[] = ['type' => $type, 'count' => $count];
            }
        }

        return $this->render('fleet/index.html.twig', [
            'empire' => $empire,
            'planet' => $planet,
            'hangar' => $hangar,
            'fleets' => $this->fleets->findOwnedBy($empire),
            'name_max_length' => Fleet::NAME_MAX_LENGTH,
        ]);
    }

    #[Route('/flotte/constituer', name: 'app_fleet_assemble', methods: ['POST'])]
    public function assemble(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('assemble-fleet', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_fleet');
        }

        $requested = $request->request->all('ships');
        $ships = [];
        foreach ($this->shipTypes->findAllOrdered() as $type) {
            $quantity = $requested[$type->getCode()] ?? '';
            if (\is_string($quantity) && '' !== $quantity) {
                $ships[] = ['type' => $type, 'quantity' => (int) $quantity];
            }
        }

        try {
            $fleet = $this->assembly->assemble($empire->getActivePlanet(), $request->request->getString('name'), $ships);
            $this->addFlash('success', \sprintf('Flotte « %s » constituée : %d vaisseau(x).', $fleet->getName(), $fleet->shipCount()));
        } catch (InvalidFleetComposition $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_fleet');
    }

    #[Route('/flotte/{id}/dissoudre', name: 'app_fleet_disband', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function disband(Fleet $fleet, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire || $fleet->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Flotte introuvable.');
        }
        if (!$this->isCsrfTokenValid('disband-fleet-' . $fleet->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_fleet');
        }

        $name = $fleet->getName();
        $this->assembly->disband($fleet);
        $this->addFlash('success', \sprintf('Flotte « %s » dissoute : ses vaisseaux ont rejoint le hangar.', $name));

        return $this->redirectToRoute('app_fleet');
    }
}
