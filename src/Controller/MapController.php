<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Galaxy;
use App\Repository\GalaxyRepository;
use App\Service\Account\GameContext;
use App\Service\Universe\GalaxyMap;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carte de l'univers (§2.3, §5.5) : seul écran hors du cadre HTML/Live Components. La page porte un SVG piloté par le
 * contrôleur Stimulus « galaxy-map », qui lit ses données dans l'endpoint JSON de la zone visible.
 */
final class MapController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly GalaxyRepository $galaxies,
        private readonly GalaxyMap $map,
    ) {}

    #[Route('/carte', name: 'app_map', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $home = $empire->getActivePlanet()->getSystem();
        $galaxies = $this->galaxies->findBy([], ['number' => 'ASC']);
        $number = $request->query->getInt('galaxie', $home->getGalaxy()->getNumber());
        $galaxy = array_find($galaxies, static fn(Galaxy $galaxy): bool => $galaxy->getNumber() === $number) ?? $home->getGalaxy();
        $centered = $galaxy === $home->getGalaxy();

        return $this->render('map/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'galaxies' => $galaxies,
            'galaxy' => $galaxy,
            // Vue centrée sur la planète active dans sa galaxie, sur le centre ailleurs
            'center' => $centered ? ['x' => $home->getPosition()->x, 'y' => $home->getPosition()->y] : ['x' => 0.0, 'y' => 0.0],
            'detail_systems' => GalaxyMap::MAX_DETAILED_SYSTEMS,
        ]);
    }

    /**
     * Zone visible : x1, y1, x2, y2 en coordonnées globales ; detail=1 ajoute les planètes si la zone compte peu de
     * systèmes.
     */
    #[Route('/carte/{number}/donnees', name: 'app_map_data', methods: ['GET'], requirements: ['number' => '\d+'])]
    public function data(#[MapEntity(mapping: ['number' => 'number'])] Galaxy $galaxy, Request $request): JsonResponse
    {
        $empire = $this->context->empire();
        $query = $request->query;
        [$x1, $x2] = [(float) $query->get('x1', -1e7), (float) $query->get('x2', 1e7)];
        [$y1, $y2] = [(float) $query->get('y1', -1e7), (float) $query->get('y2', 1e7)];
        $systems = $this->map->systems($galaxy, min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2), $empire);

        $detailed = $query->getBoolean('detail') && \count($systems) <= GalaxyMap::MAX_DETAILED_SYSTEMS;
        $planets = $detailed ? $this->map->planets($galaxy, array_column($systems, 'id'), $empire) : [];

        return new JsonResponse([
            'galaxy' => ['number' => $galaxy->getNumber(), 'name' => $galaxy->getName()],
            'systems' => $systems,
            'planets' => $planets,
            'detailed' => $detailed,
            'truncated' => \count($systems) >= GalaxyMap::MAX_SYSTEMS,
        ]);
    }
}
