<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Entity\EmpireBlueprint;
use App\Entity\Planet;
use App\Enum\Messaging\MessageCategory;
use App\Enum\Pve\NpcCategory;
use App\Repository\EmpireBlueprintRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Messaging\GameReports;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Récompense PvE (§4.14) : vaincre une civilisation non-joueuse sur sa planète rapporte, en plus du pillage, la
 * récompense de victoire de son profil, versée sur la planète mère de l'empire qui a lancé l'attaque, avec un rapport.
 * Les « boss » y ajoutent des récompenses spéciales : de l'antimatière (ressource rare) et le plan d'un vaisseau
 * réservé, acquis une fois pour toutes.
 */
final readonly class NpcRewards
{
    public function __construct(
        private PlanetResources $resources,
        private GameReports $reports,
        private EmpireBlueprintRepository $blueprints,
        private EntityManagerInterface $entityManager,
    ) {}

    public function victory(Planet $planet, Empire $victor, \DateTimeImmutable $at): void
    {
        $owner = $planet->getOwner();
        $profile = $owner?->getNpcProfile();
        // Guilde marchande : ses champs de récompense paient ses convois escortés (NpcConvoys), pas sa défaite
        if (null === $owner || null === $profile || $victor->isNpc() || NpcCategory::Trader === $profile->getCategory()) {
            return;
        }
        $reward = $profile->getVictoryReward();
        $antimatter = $profile->getVictoryAntimatter();
        $blueprint = $profile->getVictoryBlueprint();
        $newBlueprint = null !== $blueprint && !$this->blueprints->has($victor, $blueprint);
        if ($reward->isZero() && 0 === $antimatter && !$newBlueprint) {
            return;
        }

        $home = $victor->getHomePlanet();
        $gains = [];
        if (!$reward->isZero()) {
            $stock = $this->resources->settle($home, $at)->amounts;
            $home->storeResources($stock->plus($reward), $at);
            $gains[] = \sprintf('%s métal, %s cristal et %s deutérium', self::format($reward->metal), self::format($reward->crystal), self::format($reward->deuterium));
        }
        if ($antimatter > 0) {
            $home->addAntimatter($antimatter);
            $gains[] = \sprintf('%s antimatière', self::format($antimatter));
        }
        $text = \sprintf('La civilisation « %s » est vaincue sur %s.', $owner->getName(), $planet);
        if ([] !== $gains) {
            $text .= \sprintf(' %s vous sont versés sur %s.', ucfirst(implode(', ', $gains)), $home);
        }
        if ($newBlueprint) {
            $this->entityManager->persist(new EmpireBlueprint($victor, $blueprint, $at));
            $text .= \sprintf(' Vous remportez le plan du vaisseau « %s » : il se construit désormais dans vos chantiers spatiaux.', $blueprint->getName());
        }
        $this->reports->report($victor, MessageCategory::Combat, \sprintf('Victoire sur %s', $owner->getName()), $text);
    }

    private static function format(float|int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }
}
