<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Empire;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\MarketListing;
use App\Entity\Merchant;
use App\Entity\MerchantHolding;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Entity\User;
use App\Enum\Account\StartingOrientation;
use App\Enum\Market\MarketListingStatus;
use App\Model\Economy\Resources;
use PHPUnit\Framework\TestCase;

/**
 * Annonces du marché et ressources gardées par un marchand (§4.15) : validation à double livraison, retrait limité
 * au bénéficiaire.
 */
final class MarketListingTest extends TestCase
{
    private StarSystem $system;

    protected function setUp(): void
    {
        $this->system = new StarSystem(new Galaxy(1, 'Orion'), 7, new GlobalPosition(100.0, 0.0));
    }

    public function testTransactionIsValidatedOnlyOnceBothPartiesDelivered(): void
    {
        [$ana, $bob] = [$this->empire('Ana'), $this->empire('Bob')];
        $listing = new MarketListing($ana, $this->merchant(), new Resources(1000.0), new Resources(0.0, 500.0), new \DateTimeImmutable('2026-10-13 10:00'));
        self::assertSame(MarketListingStatus::Open, $listing->getStatus());

        $listing->accept($bob, new \DateTimeImmutable('2026-10-13 11:00'));
        self::assertSame($bob, $listing->getBuyer());
        self::assertEquals(new Resources(1000.0), $listing->dueFrom($ana));
        self::assertEquals(new Resources(0.0, 500.0), $listing->dueFrom($bob));

        self::assertFalse($listing->markDelivered($bob));
        self::assertTrue($listing->isDeliveredBy($bob));
        self::assertFalse($listing->isDeliveredBy($ana));
        try {
            $listing->complete(new \DateTimeImmutable('2026-10-13 12:00'));
            self::fail('Transaction validée sans la livraison du vendeur.');
        } catch (\LogicException) {
        }
        self::assertTrue($listing->markDelivered($ana));
        $listing->complete(new \DateTimeImmutable('2026-10-13 12:00'));

        self::assertSame(MarketListingStatus::Completed, $listing->getStatus());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 12:00'), $listing->getClosedAt());
    }

    public function testListingRules(): void
    {
        $ana = $this->empire('Ana');
        $at = new \DateTimeImmutable('2026-10-13 10:00');
        foreach ([[Resources::zero(), new Resources(1.0)], [new Resources(1.0), Resources::zero()]] as [$offered, $asked]) {
            try {
                new MarketListing($ana, $this->merchant(), $offered, $asked, $at);
                self::fail('Annonce sans contrepartie acceptée.');
            } catch (\InvalidArgumentException) {
            }
        }

        $listing = new MarketListing($ana, $this->merchant(), new Resources(10.0), new Resources(5.0), $at);
        try {
            $listing->accept($ana, $at);
            self::fail('Le vendeur achète sa propre annonce.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $listing->markDelivered($ana);
    }

    public function testOnlyAnOpenListingIsCancelled(): void
    {
        $at = new \DateTimeImmutable('2026-10-13 10:00');
        $open = new MarketListing($this->empire('Ana'), $this->merchant(), new Resources(10.0), new Resources(5.0), $at);
        $open->cancel($at);
        self::assertSame(MarketListingStatus::Cancelled, $open->getStatus());

        $accepted = new MarketListing($this->empire('Ana'), $this->merchant(), new Resources(10.0), new Resources(5.0), $at);
        $accepted->accept($this->empire('Bob'), $at);
        $this->expectException(\LogicException::class);
        $accepted->cancel($at);
    }

    public function testHoldingIsCreditedAndWithdrawnWithinItsBalance(): void
    {
        $holding = new MerchantHolding($this->merchant(), $this->empire('Ana'));
        self::assertTrue($holding->isEmpty());

        $holding->credit(new Resources(100.0, 50.0));
        $holding->withdraw(new Resources(40.0, 50.0));

        self::assertEquals(new Resources(60.0), $holding->getResources());
        $this->expectException(\InvalidArgumentException::class);
        $holding->withdraw(new Resources(61.0));
    }

    public function testMerchantIsAStationOfItsSystem(): void
    {
        $merchant = $this->merchant();

        $position = $merchant->position();
        self::assertSame([3.0, -2.0, null], [$position->localX, $position->localY, $position->planetId]);
        self::assertSame('Comptoir 1-7 (Système 7 (galaxie 1))', (string) $merchant);
    }

    private function merchant(): Merchant
    {
        return new Merchant($this->system, 'Comptoir 1-7', 3.0, -2.0);
    }

    private function empire(string $name): Empire
    {
        $planet = new Planet($this->system, new OrbitalPosition(4, 40.0, 0.0), 20);

        return new Empire(new User($name . '@exemple.fr', new \DateTimeImmutable()), $name, StartingOrientation::Aggressive, $planet, new \DateTimeImmutable());
    }
}
