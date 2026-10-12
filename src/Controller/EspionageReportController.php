<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\EspionageReportRepository;
use App\Service\Account\GameContext;
use App\Service\Espionage\EspionageRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Espionnage » (§4.9) : rapports rédigés par les sondes de l'empire, puis le détail d'un rapport (sections
 * révélées selon le niveau de détail).
 */
final class EspionageReportController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly EspionageReportRepository $reports,
    ) {}

    #[Route('/espionnage', name: 'app_espionage_reports', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('espionage/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'reports' => $this->reports->findForEmpire($empire),
        ]);
    }

    #[Route('/espionnage/{id}', name: 'app_espionage_report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $empire = $this->context->empire();
        $report = $this->reports->find($id);
        if (null === $empire || null === $report || $report->getEmpire() !== $empire) {
            throw $this->createNotFoundException('Rapport introuvable.');
        }

        return $this->render('espionage/show.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'report' => $report,
            'thresholds' => EspionageRules::THRESHOLDS,
        ]);
    }
}
