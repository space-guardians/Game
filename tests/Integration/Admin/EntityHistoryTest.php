<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\AdminRole;
use App\Admin\History\AuditedEntity;
use App\Admin\History\AuditOrigin;
use App\Admin\History\EntityHistory;
use App\Admin\History\HistoryFilters;
use App\Entity\AdminUser;
use App\Entity\Galaxy;
use App\Entity\GalaxyShapeTemplate;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Entity\User;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Factory\StarSystemFactory;
use DH\Auditor\Model\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Test\Factories;

/**
 * Historique des modifications des données (§5.6.3), enregistré par l'auditeur.
 */
final class EntityHistoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private EntityHistory $history;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->history = self::getContainer()->get(EntityHistory::class);
    }

    public function testAuditsAccountsAndGameConfigurationOnly(): void
    {
        $classes = array_map(static fn(AuditedEntity $entity): string => $entity->class, $this->history->entities());

        self::assertEqualsCanonicalizing([User::class, AdminUser::class, Galaxy::class, GalaxyShapeTemplate::class], $classes);
        self::assertNotContains(Planet::class, $classes);
        self::assertNotContains(StarSystem::class, $classes);
    }

    public function testEveryAuditedEntityHasFrenchLabel(): void
    {
        foreach ($this->history->entities() as $entity) {
            self::assertArrayHasKey($entity->class, EntityHistory::LABELS, $entity->class . ' est auditée sans libellé dans EntityHistory::LABELS.');
        }
    }

    public function testRecordsCreationUpdateAndDeletionWithAuthor(): void
    {
        $this->loginAs('admin@space-guardians.local');
        $galaxy = new Galaxy(4, 'Orion');
        $this->entityManager->persist($galaxy);
        $this->entityManager->flush();
        $id = (string) $galaxy->getId();
        $galaxy->setName('Andromède');
        $this->entityManager->flush();
        $this->entityManager->remove($galaxy);
        $this->entityManager->flush();

        $entries = $this->search(new HistoryFilters(objectId: $id))['entries'];

        self::assertSame([TransactionType::REMOVE, TransactionType::UPDATE, TransactionType::INSERT], array_map(static fn($entry): string => $entry->type, $entries));
        self::assertSame(['new' => 'Andromède', 'old' => 'Orion'], $entries[1]->getDiffs()['name']);
        self::assertSame(['new' => 'Orion'], $entries[2]->getDiffs()['name']);
        // Une suppression n'enregistre que le résumé de l'objet
        self::assertSame('Galaxie 4 — Andromède', $entries[0]->getDiffs()['label']);
        foreach ($entries as $entry) {
            self::assertSame('admin@space-guardians.local', $entry->username);
        }
    }

    public function testNeverStoresSecrets(): void
    {
        $admin = AdminUserFactory::createOne(['email' => 'cible@space-guardians.local', 'role' => AdminRole::Moderator]);
        $admin->setPassword('$2y$13$nouvelle-empreinte');
        $admin->resetTwoFactor();
        $this->entityManager->flush();

        $adminHistory = $this->history->entity('admin_user');
        self::assertNotNull($adminHistory);
        $entries = $this->history->search($adminHistory, new HistoryFilters(objectId: (string) $admin->getId()))['entries'];
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertArrayNotHasKey('password', $entry->getDiffs());
            self::assertArrayNotHasKey('totpSecret', $entry->getDiffs());
        }
        self::assertSame(['new' => false, 'old' => true], $entries[0]->getDiffs()['totpConfirmed']);
    }

    public function testFiltersByTypeAuthorOriginAndPeriod(): void
    {
        $this->entityManager->persist(new Galaxy(5, 'Sans auteur'));
        $this->entityManager->flush();
        $this->loginAs('designer@space-guardians.local');
        $this->entityManager->persist(new Galaxy(6, 'Avec auteur'));
        $this->entityManager->flush();

        self::assertSame(2, $this->search(new HistoryFilters(type: TransactionType::Insert))['total']);
        self::assertSame(0, $this->search(new HistoryFilters(type: TransactionType::Remove))['total']);
        self::assertSame(1, $this->search(new HistoryFilters(author: 'DESIGNER'))['total']);
        // Hors requête HTTP, aucun pare-feu : les deux écritures sont d'origine « système »
        self::assertSame(2, $this->search(new HistoryFilters(origin: AuditOrigin::System))['total']);
        self::assertSame(0, $this->search(new HistoryFilters(origin: AuditOrigin::Admin))['total']);
        self::assertSame(2, $this->search(new HistoryFilters(from: new \DateTimeImmutable('-1 day'), to: new \DateTimeImmutable('today')))['total']);
        self::assertSame(0, $this->search(new HistoryFilters(to: new \DateTimeImmutable('-2 days')))['total']);
    }

    public function testAttachingSystemsDoesNotFloodGalaxyHistory(): void
    {
        $galaxy = GalaxyFactory::createOne();
        StarSystemFactory::createMany(3, ['galaxy' => $galaxy]);

        $entries = $this->search(new HistoryFilters(objectId: (string) $galaxy->getId()))['entries'];

        self::assertSame([TransactionType::INSERT], array_map(static fn($entry): string => $entry->type, $entries));
    }

    public function testGroupsChangesOfSameTransaction(): void
    {
        $this->entityManager->persist(new Galaxy(7, 'Première'));
        $this->entityManager->persist(new GalaxyShapeTemplate('Gabarit lié'));
        $this->entityManager->flush();

        $entry = $this->search(new HistoryFilters())['entries'][0];
        self::assertNotNull($entry->transactionHash);
        $transaction = $this->history->transaction($entry->transactionHash);

        self::assertEqualsCanonicalizing(['galaxy', 'galaxy_shape_template'], array_map(static fn(array $item): string => $item['entity']->key, $transaction));
    }

    /** @return array{entries: list<\DH\Auditor\Model\Entry>, total: int, pages: int, page: int} */
    private function search(HistoryFilters $filters): array
    {
        $galaxies = $this->history->entity('galaxy');
        self::assertNotNull($galaxies);

        return $this->history->search($galaxies, $filters);
    }

    private function loginAs(string $email): void
    {
        $admin = AdminUserFactory::createOne(['email' => $email]);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($admin, 'admin', $admin->getRoles()));
    }
}
