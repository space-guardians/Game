<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Empire;
use App\Entity\Merchant;
use App\Entity\Planet;
use App\Entity\PlanetListing;
use App\Enum\Market\PlanetListingStatus;
use App\Enum\Messaging\MessageCategory;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Repository\BuildingQueueItemRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\PlanetListingRepository;
use App\Repository\ShipyardOrderRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Messaging\GameReports;
use App\Service\Universe\ColonyLimit;
use App\Service\Universe\PlanetLocks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Vente et échange de planètes (§4.15). Mise sur le marché, une planète est gelée (`Planet::freeze()` : ni production
 * ni pillage) jusqu'à sa cession ou son retrait. Vente : l'acheteur, qui doit avoir un emplacement de colonisation
 * libre, paie le prix depuis sa planète active ; le marchand choisi le garde pour le vendeur. Échange : un autre empire
 * propose une de ses planètes, gelée à son tour ; le vendeur accepte (les deux changent de mains) ou refuse. Ni planète
 * mère, ni planète en chantier. Bâtiments et vaisseaux au sol suivent la planète. Chaque opération verrouille les
 * planètes en jeu.
 */
final readonly class PlanetMarket
{
    public function __construct(
        private PlanetListingRepository $listings,
        private MerchantHoldingRepository $holdings,
        private BuildingQueueItemRepository $buildingQueue,
        private ShipyardOrderRepository $shipyardOrders,
        private PlanetResources $resources,
        private ColonyLimit $colonyLimit,
        private EntityManagerInterface $entityManager,
        private PlanetLocks $planetLocks,
        private GameReports $reports,
        private ClockInterface $clock,
    ) {}

    /**
     * Met une planète en vente (prix et marchand) ou à l'échange (ni l'un ni l'autre).
     *
     * @throws InvalidMarketAction
     */
    public function list(Empire $seller, Planet $planet, ?Merchant $merchant = null, ?Resources $price = null): PlanetListing
    {
        if ((null === $merchant) !== (null === $price) || (null !== $price && $price->isZero())) {
            throw new InvalidMarketAction('Fixez un prix et un marchand pour une vente, ou ni l’un ni l’autre pour un échange.');
        }

        return $this->locked([$planet], function () use ($seller, $planet, $merchant, $price): PlanetListing {
            $this->checkTradable($seller, $planet);
            $now = $this->clock->now();
            $this->resources->settle($planet, $now);
            $planet->freeze($now);
            $listing = new PlanetListing($seller, $planet, $merchant, $price, $now);
            $this->entityManager->persist($listing);
            $this->entityManager->flush();

            return $listing;
        });
    }

    /** @throws InvalidMarketAction */
    public function cancel(PlanetListing $listing, Empire $seller): void
    {
        if ($listing->getSeller() !== $seller) {
            throw new InvalidMarketAction('Seul le vendeur retire son annonce.');
        }
        $this->locked([$listing->getPlanet()], function () use ($listing): void {
            try {
                $listing->cancel($this->clock->now());
            } catch (\LogicException) {
                throw new InvalidMarketAction('Répondez d’abord à la proposition d’échange en attente.');
            }
            $this->thaw($listing->getPlanet());
            $this->entityManager->flush();
        });
    }

    /**
     * Achat d'une planète en vente : emplacement de colonisation libre exigé, prix payé depuis la planète active.
     *
     * @throws InvalidMarketAction
     */
    public function buy(PlanetListing $listing, Empire $buyer): void
    {
        $payer = $buyer->getActivePlanet();
        $this->locked([$listing->getPlanet(), $payer], function () use ($listing, $buyer, $payer): void {
            if ($listing->isSwap() || !$listing->isOpen()) {
                throw new InvalidMarketAction('Cette planète n’est plus en vente.');
            }
            if ($listing->getSeller() === $buyer) {
                throw new InvalidMarketAction('Vous ne pouvez pas acheter votre propre planète.');
            }
            $refusal = $this->colonyLimit->slots($buyer)->refusal();
            if (null !== $refusal) {
                throw new InvalidMarketAction('Achat impossible : ' . lcfirst($refusal));
            }
            $now = $this->clock->now();
            $price = $listing->getPrice();
            $stock = $this->resources->settle($payer, $now)->amounts;
            if (!$stock->covers($price)) {
                throw new InvalidMarketAction(\sprintf('%s n’a pas de quoi payer %s.', $payer, self::amounts($price)));
            }
            $payer->storeResources($stock->minus($price), $now);
            $merchant = $listing->getMerchant();
            \assert(null !== $merchant);
            $this->holdings->forEmpire($merchant, $listing->getSeller())->credit($price);
            $listing->sell($buyer, $now);
            $this->handOver($listing->getPlanet(), $buyer, $now);
            $this->entityManager->flush();
            $this->reports->report($listing->getSeller(), MessageCategory::Transport, \sprintf('Planète vendue : %s', $listing->getPlanet()), \sprintf('%s a acheté %s. Le prix (%s) vous attend chez %s.', $buyer->getName(), $listing->getPlanet(), self::amounts($price), $merchant->getName()));
            $this->reports->report($buyer, MessageCategory::Transport, \sprintf('Planète achetée : %s', $listing->getPlanet()), \sprintf('%s vous appartient désormais, avec ses bâtiments et ses vaisseaux au sol.', $listing->getPlanet()));
        });
    }

    /**
     * Propose une de ses planètes en échange : elle est gelée le temps de la réponse du vendeur.
     *
     * @throws InvalidMarketAction
     */
    public function offer(PlanetListing $listing, Empire $buyer, Planet $planet): void
    {
        $this->locked([$listing->getPlanet(), $planet], function () use ($listing, $buyer, $planet): void {
            if (!$listing->isSwap() || PlanetListingStatus::Open !== $listing->getStatus()) {
                throw new InvalidMarketAction('Cette planète n’attend pas de proposition d’échange.');
            }
            if ($listing->getSeller() === $buyer) {
                throw new InvalidMarketAction('Vous ne pouvez pas échanger avec vous-même.');
            }
            $this->checkTradable($buyer, $planet);
            $now = $this->clock->now();
            $this->resources->settle($planet, $now);
            $planet->freeze($now);
            $listing->offer($buyer, $planet);
            $this->entityManager->flush();
            $this->reports->report($listing->getSeller(), MessageCategory::Transport, \sprintf('Proposition d’échange : %s', $listing->getPlanet()), \sprintf('%s propose %s en échange de %s.', $buyer->getName(), $planet, $listing->getPlanet()));
        });
    }

    /**
     * Le vendeur accepte l'échange proposé : les deux planètes changent de mains et sont dégelées.
     *
     * @throws InvalidMarketAction
     */
    public function acceptOffer(PlanetListing $listing, Empire $seller): void
    {
        $offered = $listing->getOfferedPlanet();
        $buyer = $listing->getBuyer();
        if ($listing->getSeller() !== $seller || null === $offered || null === $buyer) {
            throw new InvalidMarketAction('Aucune proposition d’échange à accepter.');
        }
        $this->locked([$listing->getPlanet(), $offered], function () use ($listing, $seller, $buyer, $offered): void {
            $now = $this->clock->now();
            try {
                $listing->concludeSwap($now);
            } catch (\LogicException) {
                throw new InvalidMarketAction('Aucune proposition d’échange à accepter.');
            }
            $this->handOver($listing->getPlanet(), $buyer, $now);
            $this->handOver($offered, $seller, $now);
            $this->entityManager->flush();
            foreach ([[$seller, $offered, $listing->getPlanet()], [$buyer, $listing->getPlanet(), $offered]] as [$empire, $gained, $lost]) {
                $this->reports->report($empire, MessageCategory::Transport, \sprintf('Échange conclu : %s', $gained), \sprintf('%s vous appartient désormais, en échange de %s.', $gained, $lost));
            }
        });
    }

    /**
     * Le vendeur refuse la proposition, ou l'empire qui l'a faite la retire : sa planète est dégelée.
     *
     * @throws InvalidMarketAction
     */
    public function dropOffer(PlanetListing $listing, Empire $by): void
    {
        $offered = $listing->getOfferedPlanet();
        $buyer = $listing->getBuyer();
        if (null === $offered || null === $buyer || ($by !== $listing->getSeller() && $by !== $buyer)) {
            throw new InvalidMarketAction('Aucune proposition d’échange à retirer.');
        }
        $this->locked([$offered], function () use ($listing, $offered, $buyer, $by): void {
            $listing->dropOffer();
            $this->thaw($offered);
            $this->entityManager->flush();
            if ($by === $listing->getSeller()) {
                $this->reports->report($buyer, MessageCategory::Transport, \sprintf('Échange refusé : %s', $listing->getPlanet()), \sprintf('%s a refusé %s en échange de %s.', $listing->getSeller()->getName(), $offered, $listing->getPlanet()));
            }
        });
    }

    /** @throws InvalidMarketAction */
    private function checkTradable(Empire $empire, Planet $planet): void
    {
        if ($planet->getOwner() !== $empire) {
            throw new InvalidMarketAction(\sprintf('%s n’appartient pas à votre empire.', $planet));
        }
        if ($empire->isHomePlanet($planet)) {
            throw new InvalidMarketAction('La planète mère ne se vend pas.');
        }
        if (null !== $this->listings->findOpenInvolving($planet)) {
            throw new InvalidMarketAction(\sprintf('%s est déjà sur le marché.', $planet));
        }
        if (null !== $this->buildingQueue->findActiveFor($planet) || [] !== $this->shipyardOrders->findForPlanet($planet)) {
            throw new InvalidMarketAction(\sprintf('%s a un chantier en cours : attendez qu’il s’achève.', $planet));
        }
    }

    /** Cession : stock consolidé gelé (rien produit pendant le gel), dégel, nouveau propriétaire */
    private function handOver(Planet $planet, Empire $to, \DateTimeImmutable $at): void
    {
        $from = $planet->getOwner();
        $this->thaw($planet, $at);
        $planet->transferTo($to);
        if (null !== $from && $from->getActivePlanet() === $planet) {
            $from->switchTo($from->getHomePlanet());
        }
    }

    private function thaw(Planet $planet, ?\DateTimeImmutable $at = null): void
    {
        $this->resources->settle($planet, $at ?? $this->clock->now());
        $planet->unfreeze();
    }

    /**
     * @template T
     *
     * @param list<Planet>  $planets
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function locked(array $planets, \Closure $operation): mixed
    {
        // Toujours dans le même ordre (identifiant croissant) : deux échanges croisés ne s'attendent pas l'un l'autre
        $sorted = [];
        foreach ($planets as $planet) {
            $sorted[(int) $planet->getId()] = $planet;
        }
        ksort($sorted);
        $locks = [];
        try {
            foreach ($sorted as $planet) {
                $locks[] = $this->planetLocks->acquire($planet);
            }

            return $operation();
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    private static function amounts(Resources $resources): string
    {
        return \sprintf('%s métal, %s cristal, %s deutérium', number_format($resources->metal, 0, ',', ' '), number_format($resources->crystal, 0, ',', ' '), number_format($resources->deuterium, 0, ',', ' '));
    }
}
