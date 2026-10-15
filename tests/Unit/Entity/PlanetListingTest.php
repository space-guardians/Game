<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Empire;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\Merchant;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\PlanetListing;
use App\Entity\StarSystem;
use App\Entity\User;
use App\Enum\Account\StartingOrientation;
use App\Enum\Market\PlanetListingStatus;
use App\Model\Economy\Resources;
use PHPUnit\Framework\TestCase;

/**
 * Planète sur le marché (§4.15) : vente à prix fixe chez un marchand, ou échange contre une planète proposée ; gel et
 * cession de la planète.
 */
final class PlanetListingTest extends TestCase
{
    private StarSystem $system;

    protected function setUp(): void
    {
        $this->system = new StarSystem(new Galaxy(1, 'Orion'), 7, new GlobalPosition(100.0, 0.0));
    }

    public function testASaleHasAPriceAndAMerchantAndASwapNeither(): void
    {
        $ana = $this->empire('Ana');
        $at = new \DateTimeImmutable('2026-10-15 10:00');
        $merchant = new Merchant($this->system, 'Comptoir', 3.0, -2.0);

        $sale = new PlanetListing($ana, $this->planet(), $merchant, new Resources(100.0), $at);
        self::assertFalse($sale->isSwap());
        $sale->sell($this->empire('Bob'), $at);
        self::assertSame(PlanetListingStatus::Completed, $sale->getStatus());
        self::assertFalse($sale->isOpen());

        foreach ([[$merchant, null], [null, new Resources(1.0)], [$merchant, Resources::zero()]] as [$withMerchant, $price]) {
            try {
                new PlanetListing($ana, $this->planet(), $withMerchant, $price, $at);
                self::fail('Annonce de planète incohérente acceptée.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testASwapWaitsForAnOfferThatCanBeDropped(): void
    {
        $at = new \DateTimeImmutable('2026-10-15 10:00');
        $swap = new PlanetListing($this->empire('Ana'), $this->planet(), null, null, $at);
        $bob = $this->empire('Bob');
        $offered = $this->planet();

        $swap->offer($bob, $offered);
        self::assertSame([PlanetListingStatus::Offered, $bob, $offered], [$swap->getStatus(), $swap->getBuyer(), $swap->getOfferedPlanet()]);
        self::assertTrue($swap->isOpen());
        $swap->dropOffer();
        self::assertSame([PlanetListingStatus::Open, null, null], [$swap->getStatus(), $swap->getBuyer(), $swap->getOfferedPlanet()]);

        $swap->offer($bob, $offered);
        $swap->concludeSwap($at);
        self::assertSame(PlanetListingStatus::Completed, $swap->getStatus());
        $this->expectException(\LogicException::class);
        $swap->cancel($at);
    }

    public function testPlanetIsFrozenAndHandedOver(): void
    {
        $ana = $this->empire('Ana');
        $planet = $this->planet();
        $planet->assignTo($ana);
        $at = new \DateTimeImmutable('2026-10-15 10:00');

        $planet->freeze($at);
        $planet->freeze($at->modify('+1 hour'));
        self::assertEquals($at, $planet->getFrozenAt());
        $planet->unfreeze();
        self::assertFalse($planet->isFrozen());

        $bob = $this->empire('Bob');
        $planet->transferTo($bob);
        self::assertSame($bob, $planet->getOwner());
        $this->expectException(\LogicException::class);
        $planet->transferTo($bob);
    }

    private function planet(): Planet
    {
        return new Planet($this->system, new OrbitalPosition(4, 40.0, 0.0), 20);
    }

    private function empire(string $name): Empire
    {
        return new Empire(new User($name . '@exemple.fr', new \DateTimeImmutable()), $name, StartingOrientation::Aggressive, $this->planet(), new \DateTimeImmutable());
    }
}
