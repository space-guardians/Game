<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BuildingType;
use App\Exception\Economy\ConstructionInProgress;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Economy\NoCancellableConstruction;
use App\Repository\BuildingTypeRepository;
use App\Service\Account\GameContext;
use App\Service\Economy\BuildingConstruction;
use App\Service\Economy\BuildingRules;
use App\Service\Economy\CancellationRefund;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Bâtiments » (§5.4) : bâtiments de la planète active, coût et durée du niveau suivant, lancement d'une
 * construction (§4.3).
 */
final class BuildingsController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly BuildingTypeRepository $buildingTypes,
        private readonly BuildingRules $rules,
        private readonly BuildingConstruction $construction,
        private readonly CancellationRefund $refund,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/batiments', name: 'app_buildings', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $planet = $empire->getActivePlanet();
        $stock = $this->context->activeResources()?->amounts;
        $current = $this->context->activeConstruction();

        $cards = [];
        foreach ($this->buildingTypes->findAllOrdered() as $type) {
            $level = $planet->buildingLevel($type);
            $cost = $this->rules->cost($type, $level + 1);
            $building = $current?->getType() === $type;
            $affordable = null !== $stock && $stock->covers($cost);
            $cards[] = [
                'type' => $type,
                'level' => $level,
                'cost' => $cost,
                'duration' => $this->construction->duration($planet, $type, $level + 1),
                'missing' => $affordable || null === $stock ? null : $stock->shortfall($cost),
                'state' => $building ? 'building' : ($affordable ? 'ready' : 'short'),
            ];
        }

        return $this->render('buildings/index.html.twig', [
            'empire' => $empire,
            'planet' => $planet,
            'cards' => $cards,
            'current' => $current,
            // Part du coût rendue si l'annulation avait lieu maintenant (indicative : le temps continue de passer)
            'refund_share' => null === $current ? null : $this->refund->remainingShare($current->getStartedAt(), $current->getEndsAt(), $this->clock->now()),
        ]);
    }

    #[Route('/batiments/annuler', name: 'app_buildings_cancel', methods: ['POST'])]
    public function cancel(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('cancel-construction', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_buildings');
        }

        try {
            $result = $this->construction->cancel($empire->getActivePlanet());
            $lost = $result->lost->metal + $result->lost->crystal + $result->lost->deuterium;
            $this->addFlash('success', \sprintf(
                'Construction annulée : %d %% du coût remboursé%s.',
                (int) floor($result->share * 100),
                $lost >= 1 ? ', une partie a été perdue faute de place dans les dépôts' : '',
            ));
        } catch (NoCancellableConstruction $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_buildings');
    }

    #[Route('/batiments/{code}/construire', name: 'app_buildings_build', methods: ['POST'])]
    public function build(#[MapEntity(mapping: ['code' => 'code'])] BuildingType $type, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('build-' . $type->getCode(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_buildings');
        }

        try {
            $item = $this->construction->start($empire->getActivePlanet(), $type);
            $this->addFlash('success', \sprintf('Construction lancée : %s niveau %d.', $type->getName(), $item->getTargetLevel()));
        } catch (ConstructionInProgress $exception) {
            $this->addFlash('error', \sprintf('Une seule construction à la fois : %s est en cours.', $exception->current));
        } catch (InsufficientResources) {
            $this->addFlash('error', \sprintf('Ressources insuffisantes pour construire %s.', $type->getName()));
        }

        return $this->redirectToRoute('app_buildings');
    }
}
