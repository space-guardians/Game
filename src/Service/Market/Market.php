<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\MarketListing;
use App\Entity\Merchant;
use App\Entity\SpaceLocation;
use App\Enum\Messaging\MessageCategory;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Repository\MarketListingRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\MerchantRepository;
use App\Service\Messaging\GameReports;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Vente et achat de ressources (§4.15). Le vendeur publie une annonce chez un marchand ; un acheteur s'y engage. Chacun
 * livre son dû au marchand par une mission « Livraison au marchand » (transport classique, donc interceptable) — le
 * vendeur dès la publication. Quand les deux ont livré, la transaction est validée : chacun trouve chez le marchand ce
 * qui lui revient (`MerchantHolding`) et vient le chercher par une mission « Retrait chez le marchand » ; personne ne
 * retire ce qui ne lui est pas destiné. Chaque marchand est verrouillé le temps d'une opération.
 */
final readonly class Market
{
    /** Annonces ouvertes ou en cours au plus par vendeur (provisoire) */
    public const int MAX_LISTINGS = 20;

    public function __construct(
        private MarketListingRepository $listings,
        private MerchantHoldingRepository $holdings,
        private MerchantRepository $merchants,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private GameReports $reports,
        private ClockInterface $clock,
    ) {}

    /**
     * @param float $offeredAntimatter antimatière proposée (§4.14), en plus ou à la place des ressources classiques
     * @param float $askedAntimatter   antimatière demandée en contrepartie
     *
     * @throws InvalidMarketAction
     */
    public function post(Empire $seller, Merchant $merchant, Resources $offered, Resources $asked, float $offeredAntimatter = 0.0, float $askedAntimatter = 0.0): MarketListing
    {
        if (($offered->isZero() && $offeredAntimatter <= 0) || ($asked->isZero() && $askedAntimatter <= 0)) {
            throw new InvalidMarketAction('Indiquez les ressources proposées et la contrepartie demandée.');
        }
        if ($this->listings->countOpenBy($seller) >= self::MAX_LISTINGS) {
            throw new InvalidMarketAction(\sprintf('Vous avez déjà %d annonces en cours : attendez qu’elles aboutissent.', self::MAX_LISTINGS));
        }
        $listing = new MarketListing($seller, $merchant, $offered, $asked, $this->clock->now(), $offeredAntimatter, $askedAntimatter);
        $this->entityManager->persist($listing);
        $this->entityManager->flush();

        return $listing;
    }

    /** @throws InvalidMarketAction */
    public function accept(MarketListing $listing, Empire $buyer): void
    {
        $this->locked($listing->getMerchant(), function () use ($listing, $buyer): void {
            $this->entityManager->refresh($listing);
            if ($listing->getSeller() === $buyer) {
                throw new InvalidMarketAction('Vous ne pouvez pas acheter votre propre annonce.');
            }
            try {
                $listing->accept($buyer, $this->clock->now());
            } catch (\LogicException) {
                throw new InvalidMarketAction('Cette annonce n’est plus ouverte.');
            }
            $this->entityManager->flush();
            $this->reports->report(
                $listing->getSeller(),
                MessageCategory::Transport,
                \sprintf('Annonce acceptée chez %s', $listing->getMerchant()->getName()),
                \sprintf('%s s’engage à livrer %s contre vos %s. %s', $buyer->getName(), self::amounts($listing->getAsked(), $listing->getAskedAntimatter()), self::amounts($listing->getOffered(), $listing->getOfferedAntimatter()), $listing->isDeliveredBy($listing->getSeller()) ? 'Vous avez déjà livré : la transaction sera validée à sa livraison.' : 'Livrez votre part au marchand pour valider la transaction.'),
            );
        });
    }

    /**
     * Le vendeur retire son annonce tant qu'aucun acheteur ne s'est engagé ; ce qu'il avait livré lui revient chez le
     * marchand.
     *
     * @throws InvalidMarketAction
     */
    public function cancel(MarketListing $listing, Empire $seller): void
    {
        if ($listing->getSeller() !== $seller) {
            throw new InvalidMarketAction('Seul le vendeur retire son annonce.');
        }
        $this->locked($listing->getMerchant(), function () use ($listing, $seller): void {
            $this->entityManager->refresh($listing);
            try {
                $refund = $listing->cancel($this->clock->now());
            } catch (\LogicException) {
                throw new InvalidMarketAction('Un acheteur s’est déjà engagé : l’annonce ne peut plus être retirée.');
            }
            if ($refund) {
                $holding = $this->holdings->forEmpire($listing->getMerchant(), $seller);
                $holding->credit($listing->getOffered());
                $holding->creditAntimatter($listing->getOfferedAntimatter());
            }
            $this->entityManager->flush();
        });
    }

    /**
     * Arrivée d'une mission « Livraison au marchand » : la cargaison règle, des plus anciennes aux plus récentes, les
     * transactions pour lesquelles l'empire doit encore livrer chez ce marchand et qu'elle couvre entièrement ; ce qui
     * reste demeure à bord. Renvoie la raison d'un échec, ou null.
     */
    public function deliver(Fleet $fleet, SpaceLocation $location, \DateTimeImmutable $at): ?string
    {
        $merchant = $this->merchants->findAt($location);
        if (null === $merchant) {
            return 'Aucun marchand à cette position.';
        }

        return $this->locked($merchant, function () use ($fleet, $merchant, $at): ?string {
            $empire = $fleet->getEmpire();
            $due = $this->listings->findAwaitingDeliveryFrom($empire, $merchant);
            if ([] === $due) {
                return \sprintf('Aucune livraison n’est attendue de vous chez %s : la cargaison reste à bord.', $merchant->getName());
            }
            $settled = 0;
            foreach ($due as $listing) {
                $amount = $listing->dueFrom($empire);
                $antimatter = $listing->antimatterDueFrom($empire);
                if (!$fleet->getCargo()->covers($amount) || $fleet->getCargoAntimatter() + 1e-6 < $antimatter) {
                    continue;
                }
                $fleet->unloadPart($amount);
                $fleet->unloadAntimatterPart($antimatter);
                ++$settled;
                $this->reports->report($empire, MessageCategory::Transport, \sprintf('Livraison au marchand : %s', $merchant->getName()), \sprintf('La flotte « %s » a livré %s pour l’annonce n°%d.', $fleet->getName(), self::amounts($amount, $antimatter), $listing->getId()));
                if ($listing->markDelivered($empire)) {
                    $this->complete($listing, $at);
                }
            }
            if (0 === $settled) {
                return \sprintf('La cargaison ne couvre aucune des livraisons attendues chez %s : elle reste à bord.', $merchant->getName());
            }
            $this->entityManager->flush();

            return null;
        });
    }

    /**
     * Arrivée d'une mission « Retrait chez le marchand » : la flotte charge ce que le marchand garde pour son empire, et
     * rien d'autre, dans la limite de ses soutes libres (au prorata de chaque ressource). Renvoie la raison d'un échec,
     * ou null.
     */
    public function collect(Fleet $fleet, SpaceLocation $location): ?string
    {
        $merchant = $this->merchants->findAt($location);
        if (null === $merchant) {
            return 'Aucun marchand à cette position.';
        }

        return $this->locked($merchant, function () use ($fleet, $merchant): ?string {
            $holding = $this->holdings->findOneBy(['merchant' => $merchant, 'empire' => $fleet->getEmpire()]);
            if (null === $holding || $holding->isEmpty()) {
                return \sprintf('%s ne garde aucune ressource pour vous.', $merchant->getName());
            }
            $free = $fleet->freeCargo();
            $held = $holding->getResources();
            $total = $held->metal + $held->crystal + $held->deuterium + $holding->getAntimatter();
            $share = min(1.0, max(0.0, $free) / $total);
            $taken = new Resources(floor($held->metal * $share), floor($held->crystal * $share), floor($held->deuterium * $share));
            $antimatter = floor($holding->getAntimatter() * $share);
            if ($taken->isZero() && $antimatter <= 0) {
                return 'Soutes pleines : rien n’a pu être retiré.';
            }
            $holding->withdraw($taken);
            $holding->withdrawAntimatter($antimatter);
            $fleet->load($taken);
            $fleet->loadAntimatter($antimatter);
            $this->entityManager->flush();

            return null;
        });
    }

    /** Les deux parties ont livré : chacune trouve chez le marchand ce que l'autre a livré */
    private function complete(MarketListing $listing, \DateTimeImmutable $at): void
    {
        $listing->complete($at);
        $buyer = $listing->getBuyer();
        \assert(null !== $buyer);
        $merchant = $listing->getMerchant();
        $sellerHolding = $this->holdings->forEmpire($merchant, $listing->getSeller());
        $sellerHolding->credit($listing->getAsked());
        $sellerHolding->creditAntimatter($listing->getAskedAntimatter());
        $buyerHolding = $this->holdings->forEmpire($merchant, $buyer);
        $buyerHolding->credit($listing->getOffered());
        $buyerHolding->creditAntimatter($listing->getOfferedAntimatter());
        foreach ([[$listing->getSeller(), $listing->getAsked(), $listing->getAskedAntimatter()], [$buyer, $listing->getOffered(), $listing->getOfferedAntimatter()]] as [$party, $share, $antimatter]) {
            $this->reports->report(
                $party,
                MessageCategory::Transport,
                \sprintf('Transaction validée chez %s', $merchant->getName()),
                \sprintf('Les deux parties ont livré leur dû (annonce n°%d) : %s vous attendent chez %s. Envoyez une flotte en « Retrait chez le marchand » pour les récupérer.', $listing->getId(), self::amounts($share, $antimatter), $merchant->getName()),
            );
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function locked(Merchant $merchant, \Closure $operation): mixed
    {
        $lock = $this->lockFactory->createLock('merchant-' . $merchant->getId(), ttl: 30.0);
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private static function amounts(Resources $resources, float $antimatter = 0.0): string
    {
        $amounts = \sprintf('%s métal, %s cristal, %s deutérium', number_format($resources->metal, 0, ',', ' '), number_format($resources->crystal, 0, ',', ' '), number_format($resources->deuterium, 0, ',', ' '));

        return $antimatter > 0 ? \sprintf('%s, %s antimatière', $amounts, number_format($antimatter, 0, ',', ' ')) : $amounts;
    }
}
