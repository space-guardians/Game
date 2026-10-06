<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ShipType;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Fleet\ShipyardQueueFull;
use App\Exception\Research\MissingPrerequisites;
use App\Repository\ShipTypeRepository;
use App\Repository\ShipyardOrderRepository;
use App\Service\Account\GameContext;
use App\Service\Fleet\ShipyardQueue;
use App\Service\Research\PrerequisiteChecker;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Chantier spatial » (§4.5) : commandes en cours et en attente de la planète active, puis une carte par type
 * de vaisseau (caractéristiques, coût et durée unitaires, prérequis, inventaire) avec la commande d'un nombre de
 * vaisseaux.
 */
final class ShipyardController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly ShipTypeRepository $shipTypes,
        private readonly ShipyardOrderRepository $orders,
        private readonly ShipyardQueue $queue,
        private readonly PrerequisiteChecker $prerequisites,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/chantier-spatial', name: 'app_shipyard', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $planet = $empire->getActivePlanet();
        $stock = $this->context->activeResources()?->amounts;
        $locked = $this->prerequisites->missingForShips($planet);

        $cards = [];
        foreach ($this->shipTypes->findAllOrdered() as $type) {
            $cost = $type->getCost();
            $affordable = null !== $stock && $stock->covers($cost);
            $cards[] = [
                'type' => $type,
                'owned' => $planet->shipCount($type),
                'cost' => $cost,
                'duration' => $this->queue->unitSeconds($planet, $type),
                'missing' => $affordable || null === $stock ? null : $stock->shortfall($cost),
                'requires' => $locked[$type->getCode()] ?? [],
                'state' => match (true) {
                    isset($locked[$type->getCode()]) => 'locked',
                    $affordable => 'ready',
                    default => 'short',
                },
            ];
        }

        return $this->render('shipyard/index.html.twig', [
            'empire' => $empire,
            'planet' => $planet,
            'cards' => $cards,
            'orders' => $this->orders->findForPlanet($planet),
            'slots' => $this->queue->slots($planet),
            'max_orders' => ShipyardQueue::MAX_ORDERS,
            'max_quantity' => ShipyardQueue::MAX_QUANTITY,
            'now' => $this->clock->now(),
        ]);
    }

    #[Route('/chantier-spatial/{code}/commander', name: 'app_shipyard_order', methods: ['POST'])]
    public function order(#[MapEntity(mapping: ['code' => 'code'])] ShipType $type, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('shipyard-' . $type->getCode(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_shipyard');
        }
        $quantity = $request->request->getInt('quantity');
        if ($quantity < 1 || $quantity > ShipyardQueue::MAX_QUANTITY) {
            $this->addFlash('error', \sprintf('Commandez entre 1 et %d vaisseaux.', ShipyardQueue::MAX_QUANTITY));

            return $this->redirectToRoute('app_shipyard');
        }

        try {
            $order = $this->queue->order($empire->getActivePlanet(), $type, $quantity);
            $this->addFlash('success', \sprintf('Commande passée : %d × %s, livraison à %s.', $quantity, $type->getName(), $order->getEndsAt()->format('H:i:s')));
        } catch (MissingPrerequisites $exception) {
            $this->addFlash('error', \sprintf('%s est verrouillé : il requiert %s.', $type->getName(), MissingPrerequisites::describe($exception->missing)));
        } catch (ShipyardQueueFull $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (InsufficientResources) {
            $this->addFlash('error', \sprintf('Ressources insuffisantes pour %d × %s.', $quantity, $type->getName()));
        }

        return $this->redirectToRoute('app_shipyard');
    }
}
