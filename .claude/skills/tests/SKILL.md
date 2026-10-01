---
name: tests
description: Écrire les tests PHPUnit de Space Guardians au bon niveau (unitaire, intégration, fonctionnel), avec les factories Foundry, l'horloge et l'aléatoire maîtrisés, et maintenir les fixtures de développement. À utiliser pour toute nouvelle fonctionnalité ou correction de bug.
---

# Tests

## Choisir le niveau

| Niveau | Dossier | Base | Pour |
|---|---|---|---|
| Unitaire | `tests/Unit` | `PHPUnit\Framework\TestCase` | Règles de jeu pures : production, coûts, trajectoires, combat, génération |
| Intégration | `tests/Integration` | `KernelTestCase` | Repositories, handlers Messenger, services avec Doctrine/Redis/Mercure |
| Fonctionnel | `tests/Functional` | `WebTestCase` | Contrôleurs, formulaires, sécurité, Live Components |

Privilégier l'unitaire : une règle de jeu se teste sans base de données. Le fonctionnel couvre le parcours, pas toutes les combinaisons.

L'arborescence de `tests/<Niveau>` reflète celle de `src/`. Une classe de test par classe testée, nom `<Classe>Test`.

## Données : Foundry

- Une factory par entité dans `src/Factory` (`bin/console make:factory`), avec des valeurs par défaut réalistes.
- Dans les tests, créer uniquement ce qui compte pour le cas testé :

```php
use Zenstruck\Foundry\Test\Factories;

final class BuildingQueueTest extends KernelTestCase
{
    use Factories;

    public function testRefusesSecondConstructionOnSamePlanet(): void
    {
        $planet = PlanetFactory::createOne(['metal' => 10_000]);
        BuildingQueueItemFactory::createOne(['planet' => $planet]);
        // …
    }
}
```

- Les tests n'utilisent jamais les fixtures. Chaque test d'intégration/fonctionnel tourne dans une transaction annulée à la fin (DAMA), donc pas de nettoyage manuel.

## Fixtures de développement

`src/DataFixtures` charge des stories Foundry (`src/Story`) qui produisent un univers jouable : une galaxie générée avec seed fixe, quelques empires à différents stades, un compte admin. Mettre à jour la story concernée quand une fonctionnalité ajoute des données utiles à la démo. Chargement : `make fixtures`.

## Temps et hasard

- Horloge : les services reçoivent `Psr\Clock\ClockInterface`. En test, `Symfony\Component\Clock\MockClock` puis `$clock->modify('+2 hours')` pour faire avancer une construction ou une flotte.
- Aléatoire : les services reçoivent un `Random\Randomizer`. En test, `new Randomizer(new Mt19937(42))` pour un résultat reproductible.
- Mercure : `Symfony\Component\Mercure\MockHub` pour vérifier les publications sans hub.

## Bonnes pratiques

- Un test = un comportement, nommé par ce qu'il vérifie (`testRefundIsCappedByStorageCapacity`).
- Data providers (`#[DataProvider]`) pour les tables de valeurs (coûts par niveau, matrice de classes).
- Toute correction de bug commence par un test qui reproduit le bug.
- Vérifier la couverture avec `make coverage` ; les services de règles de jeu doivent être couverts presque entièrement.
