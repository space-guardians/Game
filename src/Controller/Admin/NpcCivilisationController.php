<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Empire;
use App\Entity\Planet;
use App\Enum\Account\EmpireKind;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Repository\EmpireRepository;
use App\Repository\GalaxyRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\PlanetRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Admin\AdminAudit;
use App\Service\Fleet\DestinationResolver;
use App\Service\Pve\NpcCivilisations;
use App\Service\Pve\NpcRaids;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Civilisations mineures (§4.14 « Administration », §5.6.1 « IA », rôle GameDesigner) : liste, création sur une
 * planète libre (désignée ou tirée au sort dans une galaxie), renfort de flotte, ordre d'attaque manuel. Routes :
 * admin_npc_index, _found, _reinforce, _raid.
 */
#[IsGranted(AdminRole::GameDesigner->value)]
#[AdminRoute(path: '/civilisations', name: 'npc')]
final class NpcCivilisationController extends AbstractController
{
    use SameOriginTrait;

    public const string TOKEN = 'admin_npc';

    public function __construct(
        private readonly EmpireRepository $empires,
        private readonly NpcBehaviorProfileRepository $profiles,
        private readonly GalaxyRepository $galaxies,
        private readonly PlanetRepository $planets,
        private readonly ShipTypeRepository $shipTypes,
        private readonly DestinationResolver $destinations,
        private readonly NpcCivilisations $civilisations,
        private readonly NpcRaids $raids,
        private readonly EntityManagerInterface $entityManager,
        private readonly Randomizer $randomizer,
        private readonly AdminAudit $audit,
    ) {}

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->render('admin/npc/index.html.twig', [
            'civilisations' => $this->empires->findBy(['kind' => EmpireKind::Npc], ['name' => 'ASC']),
            'profiles' => $this->profiles->findBy([], ['name' => 'ASC']),
            'ships' => $this->shipTypes->findShipsOrdered(),
            'token' => self::TOKEN,
        ]);
    }

    #[AdminRoute(path: '/fonder', name: 'found', options: ['methods' => ['POST']])]
    public function found(Request $request): Response
    {
        return $this->act($request, function () use ($request): string {
            $profile = $this->profiles->find($request->request->getInt('profile')) ?? throw new \DomainException('Choisissez un profil.');
            $name = trim($request->request->getString('name'));
            if ('' === $name) {
                throw new \DomainException('Donnez un nom à la civilisation.');
            }
            $planet = $this->planet($request->request->getString('coordinates'));
            $npc = $this->civilisations->found($profile, $name, $planet);
            $this->audit->record(AuditAction::NpcFound, $npc, ['profil' => [null, $profile->getCode()], 'planète' => [null, (string) $planet]]);

            return \sprintf('Civilisation « %s » fondée sur %s.', $npc->getName(), $planet);
        });
    }

    #[AdminRoute(path: '/{id}/renforcer', name: 'reinforce', options: ['methods' => ['POST']])]
    public function reinforce(int $id, Request $request): Response
    {
        return $this->act($request, function () use ($id, $request): string {
            $npc = $this->npc($id);
            $type = $this->shipTypes->find($request->request->getInt('ship')) ?? throw new \DomainException('Choisissez un type de vaisseau.');
            $fleet = $this->civilisations->reinforce($npc, $type, $request->request->getInt('quantity'));
            $this->audit->record(AuditAction::NpcOrder, $npc, ['renfort' => [null, \sprintf('%d × %s', $request->request->getInt('quantity'), $type->getCode())]]);

            return \sprintf('Flotte « %s » renforcée : %d vaisseau(x).', $fleet->getName(), $fleet->shipCount());
        });
    }

    #[AdminRoute(path: '/{id}/attaquer', name: 'raid', options: ['methods' => ['POST']])]
    public function raid(int $id, Request $request): Response
    {
        return $this->act($request, function () use ($id, $request): string {
            $npc = $this->npc($id);
            $coordinates = trim($request->request->getString('coordinates'));
            $target = '' === $coordinates ? null : $this->planet($coordinates, free: false);
            $failure = $this->raids->raidNow($npc, $target);
            if (null !== $failure) {
                throw new \DomainException($failure);
            }

            $this->audit->record(AuditAction::NpcOrder, $npc, ['attaque' => [null, null === $target ? 'planète de joueur la plus proche' : (string) $target]]);

            return \sprintf('Ordre d’attaque donné à « %s ».', $npc->getName());
        });
    }

    /** @param callable(): string $action */
    private function act(Request $request, callable $action): Response
    {
        $this->denyUnlessSameOrigin($request);
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action());
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('admin_npc_index');
    }

    private function npc(int $id): Empire
    {
        $npc = $this->empires->find($id);
        if (null === $npc || !$npc->isNpc()) {
            throw $this->createNotFoundException('Civilisation introuvable.');
        }

        return $npc;
    }

    /**
     * Planète désignée par « galaxie:système:position », ou tirée au sort parmi les planètes libres d'une galaxie
     * (« galaxie » seule).
     *
     * @throws \DomainException
     */
    private function planet(string $coordinates, bool $free = true): Planet
    {
        $parts = array_map('intval', explode(':', trim($coordinates)));
        if (1 === \count($parts) && $free) {
            $galaxy = $this->galaxies->findOneBy(['number' => $parts[0]]) ?? throw new \DomainException('Galaxie introuvable.');
            /** @var list<Planet> $candidates */
            $candidates = $this->entityManager->createQueryBuilder()
                ->select('p')->from(Planet::class, 'p')->join('p.system', 's')
                ->where('s.galaxy = :galaxy')->andWhere('p.owner IS NULL AND p.alliance IS NULL')
                ->setParameter('galaxy', $galaxy)->getQuery()->getResult();
            if ([] === $candidates) {
                throw new \DomainException('Aucune planète libre dans cette galaxie.');
            }

            return $candidates[$this->randomizer->getInt(0, \count($candidates) - 1)];
        }
        $resolved = 3 === \count($parts) ? $this->destinations->resolve($parts[0], $parts[1], $parts[2]) : null;
        $planet = null === $resolved || null === $resolved['position']->planetId ? null : $this->planets->find($resolved['position']->planetId);

        return $planet ?? throw new \DomainException('Coordonnées attendues : galaxie:système:position (ou une galaxie seule pour un tirage).');
    }
}
