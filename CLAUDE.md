# Space Guardians

Jeu de gestion spatiale multijoueur en temps réel (façon OGame), monolithe Symfony.

- **Besoin fonctionnel** : [space-guardians-cahier-des-charges.md](space-guardians-cahier-des-charges.md) — source de vérité. Le citer par section (§4.6.1) plutôt que le paraphraser.
- **Tâches** : issues GitHub de `space-guardians/Game`, liées par dépendances natives (« Blocked by »), rangées par jalon (phase).
- **Planning** : projet GitHub « Space Guardians », vue roadmap « Lot » (champs Status, Date de début, Date de fin).

## Stack

PHP 8.5, Symfony 7.4, Doctrine ORM + PostgreSQL, Redis, Mercure, Symfony UX (Turbo, Stimulus, Live Components), Asset Mapper, Messenger, EasyAdmin. Environnement Docker Compose (service `php`).

## Commandes

Tout passe par le `Makefile` (`make help`) :

| Commande | Rôle |
|---|---|
| `make qa` | Toutes les vérifications de la CI — à lancer avant chaque PR |
| `make cs-fix` / `make twig-cs-fix` / `make biome-fix` | Corrige le style PHP / Twig / JS-CSS |
| `make stan` | PHPStan niveau 6 |
| `make test`, `make test-unit`, `make coverage` | Tests PHPUnit |
| `make fixtures` | Charge les données de développement |

## Code

### PHP
- Style **PER-CS 3.1** (PHP-CS-Fixer, `.php-cs-fixer.dist.php`), `declare(strict_types=1);` partout.
- **PHPStan niveau 6** sans baseline : pas d'erreur ignorée sans commentaire justifiant pourquoi. Typer les tableaux (`list<Fleet>`, `array<string, int>`, array shapes).
- Classes `final` par défaut, propriétés `readonly` quand c'est possible, injection par constructeur uniquement.
- **Temps** : injecter `Psr\Clock\ClockInterface` (jamais `new \DateTimeImmutable()` ni `time()`), pour pouvoir figer l'horloge dans les tests. Tout le jeu repose sur des échéances.
- **Aléatoire** : injecter un `Random\Randomizer` (seed fixe en test) — génération de galaxie, précision en combat.
- Les règles de jeu (production, coûts, trajectoires, combat) sont des **services PHP purs**, sans Doctrine ni HTTP, testables unitairement et réutilisables pour les simulations d'équilibrage.
- Schéma de base : uniquement via migrations Doctrine générées (`doctrine:migrations:diff`), relues avant commit.
- Chaque entité de configuration de jeu a son écran EasyAdmin dans la même PR (§5.6).

### Twig, JS, CSS
- Twig : pas de logique métier ; style vérifié par Twig-CS-Fixer et `lint:twig`.
- Interactions : Live Components ou contrôleurs Stimulus (`assets/controllers`). Pas de framework JS.
- JS / CSS / JSON : **Biome** (lint + format, `biome.json`).

### Autres
- YAML : `lint:yaml` ; conteneur : `lint:container` ; mapping : `doctrine:schema:validate`.
- Dockerfile : Hadolint. Workflows GitHub : actionlint.

## Tests

- `tests/Unit` : logique pure, sans kernel. `tests/Integration` : `KernelTestCase` + base de données. `tests/Functional` : `WebTestCase`.
- Chaque test d'intégration/fonctionnel tourne dans une transaction annulée à la fin (DAMA DoctrineTestBundle).
- Données de test : factories **Foundry** (`src/Factory`), jamais les fixtures. Fixtures de dev (`src/DataFixtures`) = stories Foundry (`src/Story`).
- Mercure en test : `MockHub`. Horloge : `MockClock`.
- Toute fonctionnalité arrive avec ses tests ; toute correction de bug avec un test de non-régression.
- La CI publie les résultats, la couverture et PHPStan en commentaire de PR et en badges (branche `badges`).

## Git

- Branche par issue depuis `master` : `<type>/<numéro>-<slug>`, ex. `feat/33-suite-ordres`.
- **Commits** : Conventional Commits, description en français, pied liant l'issue. Vérifié par `.githooks/commit-msg` (activé par `make hooks`) et en CI.
  ```
  feat(flotte): ajoute l'enchaînement des ordres de flotte

  Refs #33
  ```
  Types : `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. Portées : `infra`, `ci`, `univers`, `compte`, `economie`, `recherche`, `flotte`, `combat`, `defense`, `espionnage`, `alliance`, `chat`, `messagerie`, `marche`, `notif`, `pve`, `admin`, `docs`.
- **PR** : courtes et lisibles. Modèle `.github/pull_request_template.md` : issue(s) (`Closes #n`), 1 à 3 phrases, quelques points techniques. Titre au format Conventional Commits.
- **Issues** : tenues à jour en continu (statut dans le projet, cases cochées, dates). Voir le skill `suivi-issue`.

## Skills du projet

- `suivi-issue` : démarrer, suivre et clôturer une issue (statut, cases, dates, nouvelles issues découvertes).
- `commit` : rédiger et créer un commit conforme.
- `pull-request` : ouvrir une PR conforme.
- `qualite` : lancer et corriger les vérifications avant PR.
- `tests` : écrire les tests au bon niveau avec Foundry.
