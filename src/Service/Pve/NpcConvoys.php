<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Empire;
use App\Enum\Account\EmpireKind;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Pve\NpcCategory;
use App\Exception\Market\InvalidMarketAction;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Repository\FleetRepository;
use App\Repository\MerchantRepository;
use App\Repository\PlayerQuestRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Market\Escorts;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * Convois des guildes marchandes (§4.15, « une offre publique peut aussi être postée par un PNJ ») : à l'intervalle de
 * son profil, une guilde forme un convoi sur sa planète mère et publie un contrat d'escorte vers le comptoir d'un
 * marchand de sa galaxie ; la récompense, celle de son profil, est versée par le jeu (ajoutée à son stock juste avant).
 * Les convois arrivés, désormais inutiles, sont retirés au passage suivant.
 */
final readonly class NpcConvoys
{
    /** Délai entre la publication et le départ, pour laisser venir les escortes (provisoire) */
    public const int NOTICE_HOURS = 2;

    /** Récompense par défaut si le profil n'en fixe pas (provisoire) */
    public const array REWARD = ['metal' => 3000.0, 'crystal' => 2000.0, 'deuterium' => 0.0];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NpcCivilisations $civilisations,
        private Escorts $escorts,
        private MerchantRepository $merchants,
        private PlayerQuestRepository $quests,
        private FleetRepository $fleets,
        private PlanetResources $resources,
        private Randomizer $randomizer,
        private ClockInterface $clock,
    ) {}

    /** Publie les convois dus ; renvoie leur nombre */
    public function launchDue(): int
    {
        $now = $this->clock->now();
        $launched = 0;
        /** @var list<Empire> $guilds */
        $guilds = $this->entityManager->createQueryBuilder()
            ->select('e', 'profile')
            ->from(Empire::class, 'e')
            ->join('e.npcProfile', 'profile')
            ->where('e.kind = :npc')
            ->andWhere('profile.category = :trader')
            ->setParameter('npc', EmpireKind::Npc)
            ->setParameter('trader', NpcCategory::Trader)
            ->getQuery()
            ->getResult();
        foreach ($guilds as $guild) {
            $this->retire($guild);
            $profile = $guild->getNpcProfile();
            \assert(null !== $profile);
            $last = $guild->getNpcLastConvoyAt();
            if (null !== $last && $last->modify(\sprintf('+%d hours', $profile->getConvoyIntervalHours())) > $now) {
                continue;
            }
            if (null === $this->launch($guild)) {
                $guild->npcConvoyed($now);
                $this->entityManager->flush();
                ++$launched;
            }
        }

        return $launched;
    }

    /** Forme un convoi et publie son contrat d'escorte ; renvoie la raison d'un échec, ou null */
    public function launch(Empire $guild): ?string
    {
        $profile = $guild->getNpcProfile();
        if (null === $profile || NpcCategory::Trader !== $profile->getCategory()) {
            return 'Seule une guilde marchande fait escorter des convois.';
        }
        // Un convoi à la fois : le précédent doit être arrivé
        foreach ($this->quests->findInvolving($guild, 5) as $quest) {
            if ($quest->getPoster() === $guild && !$quest->getStatus()->isClosed()) {
                return 'Le convoi précédent n’est pas encore arrivé.';
            }
        }
        $home = $guild->getHomePlanet();
        $merchants = $this->merchants->findNearest($home->getSystem()->getGalaxy(), $home->getSystem()->getPosition());
        $merchants = array_values(array_filter($merchants, static fn($merchant): bool => $merchant->getSystem() !== $home->getSystem()));
        if ([] === $merchants) {
            return 'Aucun comptoir de marchand dans la galaxie de la guilde.';
        }
        $merchant = $merchants[$this->randomizer->getInt(0, \count($merchants) - 1)];

        $convoy = $this->civilisations->fleet($guild, \sprintf('Convoi de %s', $guild->getName()), $home, $profile->getFleetComposition() ?: NpcCivilisations::FLEET['trader']);
        $this->entityManager->persist($convoy);
        $reward = $profile->getVictoryReward();
        if ($reward->isZero()) {
            $reward = new Resources(self::REWARD['metal'], self::REWARD['crystal'], self::REWARD['deuterium']);
        }
        // Récompense versée par le jeu : ajoutée au stock de la guilde, aussitôt bloquée par le contrat
        $snapshot = $this->resources->settle($home);
        $home->storeResources($snapshot->amounts->plus($reward), $snapshot->at);
        $this->entityManager->flush();

        try {
            $this->escorts->post($guild, $convoy, new MissionStep($merchant->position(), FleetAction::Station, mb_substr(\sprintf('comptoir %s', $merchant->getName()), 0, 60)), $this->clock->now()->modify(\sprintf('+%d hours', self::NOTICE_HOURS)), $reward);
        } catch (InvalidMarketAction $exception) {
            $this->entityManager->remove($convoy);
            $this->entityManager->flush();

            return $exception->getMessage();
        }

        return null;
    }

    /** Convois arrivés (stationnés hors de la planète mère, sans contrat en cours) : leur voyage est fini */
    private function retire(Empire $guild): void
    {
        foreach ($this->fleets->findOwnedBy($guild) as $fleet) {
            if (FleetStatus::Stationed === $fleet->getStatus() && !$fleet->isAtHome() && null === $this->quests->findActiveForConvoy($fleet)) {
                // Les contrats clos gardent leur historique, sans la flotte
                foreach ($this->quests->findBy(['convoy' => $fleet]) as $quest) {
                    $quest->forgetConvoy();
                }
                $this->entityManager->remove($fleet);
            }
        }
        $this->entityManager->flush();
    }
}
