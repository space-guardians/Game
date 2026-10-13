<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Ranking\RankingCategory;
use App\Repository\AllianceMemberRepository;
use App\Repository\EmpireScoreRepository;
use App\Service\Account\GameContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Classement » (§4.11) : classement global ou par catégorie, des empires ou des alliances, par pages ; rang
 * de l'empire du joueur rappelé en tête.
 */
final class RankingController extends AbstractController
{
    public const int PER_PAGE = 50;

    public function __construct(
        private readonly GameContext $context,
        private readonly EmpireScoreRepository $scores,
        private readonly AllianceMemberRepository $members,
    ) {}

    #[Route('/classement', name: 'app_ranking', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            return $this->redirectToRoute('app_home');
        }
        $category = RankingCategory::tryFrom($request->query->getString('categorie')) ?? RankingCategory::Global;
        $alliances = 'alliances' === $request->query->getString('vue');
        $page = max(1, $request->query->getInt('page', 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $total = $alliances ? $this->scores->countAlliances() : $this->scores->count([]);

        return $this->render('ranking/index.html.twig', [
            'empire' => $empire,
            'alliance' => $this->members->findOneByEmpire($empire)?->getAlliance(),
            'categories' => RankingCategory::cases(),
            'category' => $category,
            'alliances_view' => $alliances,
            'rows' => $alliances ? $this->scores->findAlliances($category, $offset, self::PER_PAGE) : $this->scores->findPage($category, $offset, self::PER_PAGE),
            'offset' => $offset,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'rank' => $this->scores->rankOf($empire, $category),
            'own_score' => $this->scores->findOneBy(['empire' => $empire]),
        ]);
    }
}
