<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Technology;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Research\MissingPrerequisites;
use App\Exception\Research\ResearchInProgress;
use App\Repository\ResearchQueueItemRepository;
use App\Repository\TechnologyRepository;
use App\Service\Account\GameContext;
use App\Service\Research\PrerequisiteChecker;
use App\Service\Research\ResearchQueue;
use App\Service\Research\ResearchRules;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Recherche » (§4.4, §5.4) : technologies de l'empire, coût et durée du niveau suivant, prérequis,
 * lancement depuis la planète active. L'arbre graphique arrive avec #28.
 */
final class ResearchController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly TechnologyRepository $technologies,
        private readonly ResearchRules $rules,
        private readonly ResearchQueue $queue,
        private readonly ResearchQueueItemRepository $queueItems,
        private readonly PrerequisiteChecker $prerequisites,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/recherche', name: 'app_research', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $stock = $this->context->activeResources()?->amounts;
        $current = $this->queueItems->findActiveFor($empire);
        $locked = $this->prerequisites->missingForTechnologies($empire);

        $cards = [];
        foreach ($this->technologies->findAllOrdered() as $technology) {
            $level = $empire->researchLevel($technology);
            $cost = $this->rules->cost($technology, $level + 1);
            $affordable = null !== $stock && $stock->covers($cost);
            $cards[] = [
                'technology' => $technology,
                'level' => $level,
                'cost' => $cost,
                'duration' => $this->queue->duration($empire, $technology, $level + 1),
                'missing' => $affordable || null === $stock ? null : $stock->shortfall($cost),
                'requires' => $locked[$technology->getCode()] ?? [],
                'state' => match (true) {
                    $current?->getTechnology() === $technology => 'researching',
                    isset($locked[$technology->getCode()]) => 'locked',
                    $affordable => 'ready',
                    default => 'short',
                },
            ];
        }

        return $this->render('research/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'cards' => $cards,
            'current' => $current,
            'laboratories' => $this->queue->laboratoryLevels($empire),
            // Heure du serveur pour le compte à rebours (décalage d'horloge du navigateur)
            'now_ms' => (int) $this->clock->now()->format('Uv'),
        ]);
    }

    #[Route('/recherche/{code}/lancer', name: 'app_research_start', methods: ['POST'])]
    public function start(#[MapEntity(mapping: ['code' => 'code'])] Technology $technology, Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        if (!$this->isCsrfTokenValid('research-' . $technology->getCode(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('app_research');
        }

        try {
            $item = $this->queue->start($empire->getActivePlanet(), $technology);
            $this->addFlash('success', \sprintf('Recherche lancée : %s niveau %d.', $technology->getName(), $item->getTargetLevel()));
        } catch (ResearchInProgress $exception) {
            $this->addFlash('error', \sprintf('Une seule recherche à la fois : %s est en cours.', $exception->current->getTechnology()->getName()));
        } catch (MissingPrerequisites $exception) {
            $this->addFlash('error', \sprintf('%s est verrouillée : elle requiert %s.', $technology->getName(), MissingPrerequisites::describe($exception->missing)));
        } catch (InsufficientResources) {
            $this->addFlash('error', \sprintf('Ressources insuffisantes sur cette planète pour rechercher %s.', $technology->getName()));
        }

        return $this->redirectToRoute('app_research');
    }
}
