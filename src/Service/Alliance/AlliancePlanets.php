<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\AllianceMember;
use App\Entity\AlliancePlanet;
use App\Entity\Empire;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\ShipyardOrder;
use App\Entity\StarSystem;
use App\Enum\Alliance\AllianceBuildingType;
use App\Enum\Alliance\AllianceRole;
use App\Enum\Economy\BuildingEffect;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Exception\Economy\InsufficientResources;
use App\Model\Economy\Resources;
use App\Repository\AllianceMemberRepository;
use App\Repository\AlliancePlanetRepository;
use App\Repository\BuildingTypeRepository;
use App\Repository\GalaxyRepository;
use App\Repository\PlanetRepository;
use App\Repository\StarSystemRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Fleet\ShipyardQueue;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Service\Universe\PlanetGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Planètes d'alliance (§4.10) : construction d'une planète artificielle sur une orbite libre (payée par le fondateur
 * sur sa planète active, achevée après un délai), propriété collective de l'alliance ; bâtiments uniques et défenses
 * commandés par le fondateur ou un diplomate sur le stock de la planète, que les membres alimentent par transport ;
 * intégrité entamée par chaque victoire ennemie, jusqu'à la destruction. Valeurs provisoires (§7).
 */
final readonly class AlliancePlanets
{
    /** Planètes d'alliance au plus par alliance (« une, ou plusieurs », §7) */
    public const int MAX_PER_ALLIANCE = 1;

    public const int BUILD_HOURS = 24;

    /** Intégrité perdue à chaque victoire ennemie sur la planète */
    public const int DAMAGE_PER_DEFEAT = 25;

    /** Chantier orbital de la planète artificielle : il construit ses défenses */
    public const int SHIPYARD_LEVEL = 4;

    public function __construct(
        private AllianceMemberRepository $members,
        private AlliancePlanetRepository $alliancePlanets,
        private GalaxyRepository $galaxies,
        private StarSystemRepository $systems,
        private PlanetRepository $planets,
        private BuildingTypeRepository $buildingTypes,
        private PlanetResources $resources,
        private ShipyardQueue $shipyard,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {}

    public static function cost(): Resources
    {
        return new Resources(50_000, 50_000, 20_000);
    }

    /** @throws InvalidAllianceAction */
    public function build(Empire $founder, int $galaxyNumber, int $systemNumber, int $orbit, string $name): AlliancePlanet
    {
        $member = $this->member($founder);
        if (AllianceRole::Founder !== $member->getRole()) {
            throw new InvalidAllianceAction('Seul le fondateur fait construire une planète d’alliance.');
        }
        $alliance = $member->getAlliance();
        if (\count($this->alliancePlanets->findOf($alliance)) >= self::MAX_PER_ALLIANCE) {
            throw new InvalidAllianceAction(\sprintf('Une alliance compte au plus %d planète(s) d’alliance.', self::MAX_PER_ALLIANCE));
        }
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > AlliancePlanet::NAME_MAX_LENGTH) {
            throw new InvalidAllianceAction(\sprintf('Le nom compte de 1 à %d caractères.', AlliancePlanet::NAME_MAX_LENGTH));
        }
        $galaxy = $this->galaxies->findOneBy(['number' => $galaxyNumber]);
        $system = null === $galaxy ? null : $this->systems->findOneBy(['galaxy' => $galaxy, 'number' => $systemNumber]);
        if (!$system instanceof StarSystem) {
            throw new InvalidAllianceAction('Aucun système à ces coordonnées.');
        }
        if ($orbit < 1 || $orbit > OrbitalPosition::MAX_ORBIT) {
            throw new InvalidAllianceAction(\sprintf('L’orbite va de 1 à %d.', OrbitalPosition::MAX_ORBIT));
        }
        if (null !== $this->planets->findOneBy(['system' => $system, 'position.orbit' => $orbit])) {
            throw new InvalidAllianceAction('Cette orbite est déjà occupée.');
        }

        // Payée par le fondateur sur sa planète active, sous son verrou
        $source = $founder->getActivePlanet();
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $source->getId()), ttl: 30.0);
        $lock->acquire(true);
        try {
            $snapshot = $this->resources->settle($source);
            if (!$snapshot->amounts->covers(self::cost())) {
                throw new InvalidAllianceAction('Ressources insuffisantes sur votre planète active pour la construction.');
            }
            $now = $this->clock->now();
            $source->storeResources($snapshot->amounts->minus(self::cost()), $snapshot->at);

            $planet = new Planet($system, new OrbitalPosition($orbit, PlanetGenerator::FIRST_ORBIT_RADIUS + PlanetGenerator::ORBIT_SPACING * ($orbit - 1), 0.0), 0);
            $planet->assignToAlliance($alliance);
            $shipyard = $this->buildingTypes->findOneBy(['effect' => BuildingEffect::Shipyard]);
            if (null !== $shipyard) {
                $planet->setBuildingLevel($shipyard, self::SHIPYARD_LEVEL);
            }
            $planet->storeResources(new Resources(), $now);
            $alliancePlanet = new AlliancePlanet($alliance, $planet, $name, $now, $now->modify(\sprintf('+%d hours', self::BUILD_HOURS)));
            $this->entityManager->persist($planet);
            $this->entityManager->persist($alliancePlanet);
            $this->entityManager->flush();

            return $alliancePlanet;
        } finally {
            $lock->release();
        }
    }

    /**
     * Monte un bâtiment unique d'un niveau, payé sur le stock de la planète d'alliance.
     *
     * @throws InvalidAllianceAction
     */
    public function raise(Empire $by, AlliancePlanet $alliancePlanet, AllianceBuildingType $type): int
    {
        $this->assertManager($by, $alliancePlanet);
        $planet = $alliancePlanet->getPlanet();
        $cost = $type->cost($alliancePlanet->buildingLevel($type) + 1);
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);
        try {
            $snapshot = $this->resources->settle($planet);
            if (!$snapshot->amounts->covers($cost)) {
                throw new InvalidAllianceAction('Stock de la planète d’alliance insuffisant : les membres l’alimentent par transport.');
            }
            $planet->storeResources($snapshot->amounts->minus($cost), $snapshot->at);
            $level = $alliancePlanet->raise($type)->getLevel();
            $this->entityManager->flush();

            return $level;
        } finally {
            $lock->release();
        }
    }

    /**
     * Commande de défenses au chantier orbital de la planète, payées sur son stock ; les prérequis technologiques sont
     * ceux du membre qui commande.
     *
     * @throws InvalidAllianceAction
     */
    public function orderDefenses(Empire $by, AlliancePlanet $alliancePlanet, ShipType $type, int $quantity): ShipyardOrder
    {
        $this->assertManager($by, $alliancePlanet);
        if (!$type->isDefense()) {
            throw new InvalidAllianceAction('Une planète d’alliance ne construit que des défenses.');
        }
        try {
            return $this->shipyard->order($alliancePlanet->getPlanet(), $type, $quantity, $by);
        } catch (InsufficientResources) {
            throw new InvalidAllianceAction('Stock de la planète d’alliance insuffisant : les membres l’alimentent par transport.');
        } catch (\DomainException|\InvalidArgumentException $exception) {
            throw new InvalidAllianceAction($exception->getMessage());
        }
    }

    /**
     * Victoire ennemie sur une planète d'alliance (sous le verrou de la planète) : son intégrité baisse ; à zéro, la
     * planète est détruite et redevient une planète libre et vide.
     */
    public function conquered(Planet $planet, \DateTimeImmutable $at): void
    {
        $alliancePlanet = $this->alliancePlanets->findOneByPlanet($planet);
        if (null === $alliancePlanet) {
            return;
        }
        if ($alliancePlanet->damage(self::DAMAGE_PER_DEFEAT) <= 0) {
            $planet->abandon($at);
            $this->entityManager->remove($alliancePlanet);
        }
    }

    /** @throws InvalidAllianceAction */
    private function member(Empire $empire): AllianceMember
    {
        return $this->members->findOneByEmpire($empire) ?? throw new InvalidAllianceAction('Vous n’appartenez à aucune alliance.');
    }

    /**
     * Fondateur ou diplomate de l'alliance propriétaire, planète achevée.
     *
     * @throws InvalidAllianceAction
     */
    private function assertManager(Empire $by, AlliancePlanet $alliancePlanet): void
    {
        $member = $this->member($by);
        if ($member->getAlliance() !== $alliancePlanet->getAlliance() || !$member->getRole()->canRecruit()) {
            throw new InvalidAllianceAction('Seuls le fondateur et les diplomates gèrent la planète d’alliance.');
        }
        if (!$alliancePlanet->isBuiltAt($this->clock->now())) {
            throw new InvalidAllianceAction(\sprintf('La planète d’alliance est en construction jusqu’au %s.', $alliancePlanet->getCompletesAt()->format('d/m H:i')));
        }
    }
}
