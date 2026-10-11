<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Combat\AttackAngle;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationColumn;
use App\Enum\Fleet\FormationRow;
use App\Repository\CombatReportRepository;
use App\Service\Account\GameContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Combats » (§4.7) : rapports des batailles où l'empire était engagé, puis le détail d'un rapport (angle
 * d'attaque, formation et sort de chaque groupe de vaisseaux, déroulé des rounds).
 */
final class CombatReportController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly CombatReportRepository $reports,
    ) {}

    #[Route('/combats', name: 'app_combat_reports', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('combat/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'reports' => $this->reports->findForEmpire($empire),
        ]);
    }

    #[Route('/combats/{id}', name: 'app_combat_report', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $empire = $this->context->empire();
        $report = null === $empire ? null : $this->reports->findOneForEmpire($id, $empire);
        if (null === $empire || null === $report) {
            throw $this->createNotFoundException('Rapport introuvable.');
        }

        return $this->render('combat/show.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'report' => $report,
            'side' => $report->sideOf($empire),
            'sides' => CombatSide::cases(),
            'rows' => FormationRow::cases(),
            'columns' => FormationColumn::cases(),
            'angles' => array_map(AttackAngle::from(...), $report->getAngles()),
        ]);
    }
}
