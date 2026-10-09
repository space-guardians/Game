<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ClassMatchup;
use App\Enum\Admin\AdminRole;
use App\Exception\Combat\InvalidMatchupMatrix;
use App\Repository\ClassMatchupRepository;
use App\Repository\ShipClassRepository;
use App\Service\Combat\ClassMatchups;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Matrice des classes de vaisseaux (contenu de jeu, §4.7, §5.6.1) : multiplicateur de dégâts pour chaque paire
 * classe attaquante × classe visée, édité en une grille. Routes : admin_class_matchup_index, _save.
 */
#[IsGranted(AdminRole::GameDesigner->value)]
#[AdminRoute(path: '/matrice-classes', name: 'class_matchup')]
final class ClassMatchupController extends AbstractController
{
    use SameOriginTrait;

    public function __construct(
        private readonly ShipClassRepository $classes,
        private readonly ClassMatchupRepository $matchups,
        private readonly ClassMatchups $editor,
    ) {}

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->renderGrid($this->matchups->matrix()->toArray());
    }

    #[AdminRoute(path: '/enregistrer', name: 'save', options: ['methods' => ['POST']])]
    public function save(Request $request): Response
    {
        $this->denyUnlessSameOrigin($request);
        if (!$this->isCsrfTokenValid('admin_class_matchup_save', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La page a expiré : recommencez.');

            return $this->redirectToRoute('admin_class_matchup_index');
        }
        $grid = $request->request->all('matrix');

        try {
            $changed = $this->editor->save($grid);
            $this->addFlash('success', 0 === $changed ? 'Matrice inchangée.' : \sprintf('Matrice enregistrée : %d case(s) modifiée(s).', $changed));

            return $this->redirectToRoute('admin_class_matchup_index');
        } catch (InvalidMatchupMatrix $exception) {
            foreach ($exception->violations as $violation) {
                $this->addFlash('danger', $violation);
            }

            // Grille ressaisie telle quelle, pour corriger
            return $this->renderGrid($grid, 422);
        }
    }

    /**
     * @param array<mixed> $values [code attaquante][code visée] => multiplicateur enregistré (nombre) ou saisi (texte)
     */
    private function renderGrid(array $values, int $status = 200): Response
    {
        // Affichage à la française (« 1,50 ») ; une saisie refusée est rendue telle quelle
        $cells = [];
        foreach ($values as $attacker => $row) {
            foreach (\is_array($row) ? $row : [] as $defender => $value) {
                $cells[(string) $attacker][(string) $defender] = \is_float($value) ? number_format($value, 2, ',', '') : (string) $value;
            }
        }

        return $this->render('admin/class_matchup/index.html.twig', [
            'classes' => $this->classes->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']),
            'values' => $cells,
            'min' => ClassMatchup::MIN,
            'max' => ClassMatchup::MAX,
        ], new Response(status: $status));
    }
}
