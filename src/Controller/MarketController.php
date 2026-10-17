<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Empire;
use App\Entity\MarketListing;
use App\Entity\Merchant;
use App\Entity\Planet;
use App\Entity\PlanetListing;
use App\Enum\Market\MarketListingStatus;
use App\Enum\Market\PlanetListingStatus;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Repository\MarketListingRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\MerchantRepository;
use App\Repository\PlanetListingRepository;
use App\Repository\PlanetRepository;
use App\Service\Account\GameContext;
use App\Service\Market\Market;
use App\Service\Market\PlanetMarket;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Marché » (§4.15, §5.4) : annonces de ressources et de planètes, publication, mes transactions en cours
 * (livraisons attendues, propositions d'échange, ressources qui m'attendent chez les marchands) et historique. Les
 * livraisons et retraits eux-mêmes passent par des missions de flotte.
 */
final class MarketController extends AbstractController
{
    public const string TOKEN = 'market';

    public const int HISTORY = 30;

    public function __construct(
        private readonly GameContext $context,
        private readonly Market $market,
        private readonly PlanetMarket $planetMarket,
        private readonly MarketListingRepository $listings,
        private readonly PlanetListingRepository $planetListings,
        private readonly MerchantRepository $merchants,
        private readonly MerchantHoldingRepository $holdings,
        private readonly PlanetRepository $planets,
    ) {}

    #[Route('/marche', name: 'app_market', methods: ['GET'])]
    public function index(): Response
    {
        $empire = $this->empire();
        $active = $empire->getActivePlanet();
        $closed = [MarketListingStatus::Completed, MarketListingStatus::Cancelled];
        $planetsClosed = [PlanetListingStatus::Completed, PlanetListingStatus::Cancelled];

        return $this->render('market/index.html.twig', [
            'empire' => $empire,
            'planet' => $active,
            'listings' => $this->listings->findOpen(),
            'planet_listings' => $this->planetListings->findOpen(),
            'mine' => $this->listings->findInvolving($empire, [MarketListingStatus::Open, MarketListingStatus::Accepted]),
            'my_planets' => $this->planetListings->findInvolving($empire, [PlanetListingStatus::Open, PlanetListingStatus::Offered]),
            'holdings' => $this->holdings->findWaitingFor($empire),
            'history' => $this->listings->findInvolving($empire, $closed, self::HISTORY),
            'planet_history' => $this->planetListings->findInvolving($empire, $planetsClosed, self::HISTORY),
            'merchants' => $this->merchants->findNearest($active->getSystem()->getGalaxy(), $active->getSystem()->getPosition()),
            'colonies' => array_values(array_filter($this->planets->findOwnedBy($empire), static fn(Planet $planet): bool => !$empire->isHomePlanet($planet))),
            'token' => self::TOKEN,
        ]);
    }

    #[Route('/marche/annonces', name: 'app_market_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($empire, $request): string {
            $listing = $this->market->post($empire, $this->merchant($request), $this->resources($request, 'offered'), $this->resources($request, 'asked'), $this->antimatter($request, 'offered'), $this->antimatter($request, 'asked'));

            return \sprintf('Annonce publiée chez %s. Livrez vos ressources par une mission « Livraison au marchand ».', $listing->getMerchant()->getName());
        });
    }

    #[Route('/marche/annonces/{id}/accepter', name: 'app_market_accept', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function accept(MarketListing $listing, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($listing, $empire): string {
            $this->market->accept($listing, $empire);

            return \sprintf('Engagement pris : livrez %s chez %s.', self::amounts($listing->getAsked(), $listing->getAskedAntimatter()), $listing->getMerchant()->getName());
        });
    }

    #[Route('/marche/annonces/{id}/retirer', name: 'app_market_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(MarketListing $listing, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($listing, $empire): string {
            $this->market->cancel($listing, $empire);

            return 'Annonce retirée.';
        });
    }

    #[Route('/marche/planetes', name: 'app_market_planet_list', methods: ['POST'])]
    public function listPlanet(Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($empire, $request): string {
            $planet = $this->ownPlanet($empire, $request->request->getInt('planet'));
            $swap = 'swap' === $request->request->getString('kind');
            $this->planetMarket->list($empire, $planet, $swap ? null : $this->merchant($request), $swap ? null : $this->resources($request, 'price'));

            return \sprintf('%s est sur le marché : elle est gelée (ni production ni pillage) jusqu’à sa cession ou son retrait.', $planet);
        });
    }

    #[Route('/marche/planetes/{id}/{operation}', name: 'app_market_planet', methods: ['POST'], requirements: ['id' => '\d+', 'operation' => 'acheter|proposer|accepter|refuser|retirer'])]
    public function planet(PlanetListing $listing, string $operation, Request $request): Response
    {
        $empire = $this->empire();

        return $this->act($request, function () use ($listing, $operation, $empire, $request): string {
            switch ($operation) {
                case 'acheter':
                    $this->planetMarket->buy($listing, $empire);

                    return \sprintf('%s vous appartient désormais.', $listing->getPlanet());
                case 'proposer':
                    $offered = $this->ownPlanet($empire, $request->request->getInt('planet'));
                    $this->planetMarket->offer($listing, $empire, $offered);

                    return \sprintf('%s proposée en échange : elle est gelée jusqu’à la réponse.', $offered);
                case 'accepter':
                    $this->planetMarket->acceptOffer($listing, $empire);

                    return 'Échange conclu.';
                case 'refuser':
                    $this->planetMarket->dropOffer($listing, $empire);

                    return 'Proposition d’échange retirée.';
                default:
                    $this->planetMarket->cancel($listing, $empire);

                    return \sprintf('%s n’est plus sur le marché.', $listing->getPlanet());
            }
        });
    }

    /** @param callable(): string $action */
    private function act(Request $request, callable $action): Response
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action());
            } catch (InvalidMarketAction $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_market');
    }

    /** @throws InvalidMarketAction */
    private function merchant(Request $request): Merchant
    {
        return $this->merchants->find($request->request->getInt('merchant')) ?? throw new InvalidMarketAction('Choisissez un marchand.');
    }

    /** @throws InvalidMarketAction */
    private function ownPlanet(Empire $empire, int $id): Planet
    {
        $planet = $this->planets->find($id);
        if (null === $planet || $planet->getOwner() !== $empire) {
            throw new InvalidMarketAction('Choisissez une de vos planètes.');
        }

        return $planet;
    }

    private function resources(Request $request, string $key): Resources
    {
        $values = $request->request->all($key);
        $amount = static fn(string $resource): float => max(0.0, floor((float) ($values[$resource] ?? 0)));

        return new Resources($amount('metal'), $amount('crystal'), $amount('deuterium'));
    }

    /** Antimatière saisie avec les ressources (§4.14) */
    private function antimatter(Request $request, string $key): float
    {
        return max(0.0, floor((float) ($request->request->all($key)['antimatter'] ?? 0)));
    }

    private function empire(): Empire
    {
        return $this->context->empire() ?? throw $this->createAccessDeniedException();
    }

    private static function amounts(Resources $resources, float $antimatter = 0.0): string
    {
        $parts = [];
        foreach (['métal' => $resources->metal, 'cristal' => $resources->crystal, 'deutérium' => $resources->deuterium, 'antimatière' => $antimatter] as $label => $amount) {
            if ($amount > 0) {
                $parts[] = number_format($amount, 0, ',', ' ') . ' ' . $label;
            }
        }

        return implode(', ', $parts);
    }
}
