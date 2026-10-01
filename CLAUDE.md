# Space Guardians

Jeu de gestion spatiale multijoueur en temps réel (façon OGame), monolithe Symfony.

- **Besoin fonctionnel** : [space-guardians-cahier-des-charges.md](space-guardians-cahier-des-charges.md) — source de vérité. Le citer par section (§4.6.1) plutôt que le paraphraser.
- **Tâches** : issues GitHub de `space-guardians/Game`, liées par dépendances natives (« Blocked by »), rangées par jalon (phase).
- **Planning** : projet GitHub « Space Guardians », vue roadmap « Lot » (champs Status, Date de début, Date de fin).
- **Bonnes pratiques Symfony** (recette `framework-bundle`, adaptée au projet) : @AGENTS.md. En cas de conflit, ce fichier-ci prime.

## Stack

PHP 8.5, Symfony 8.1, Doctrine ORM + PostgreSQL, Redis, Mercure, Symfony UX (Turbo, Stimulus, Live Components), Asset Mapper, Messenger, EasyAdmin.

## Environnement

Docker Compose (`compose.yaml`, image PHP construite depuis le `Dockerfile`, cible `dev`). Les commandes PHP, Composer et console passent par le service `php` : `docker compose exec php bin/console …`, jamais sur l'hôte.

| Service | Rôle | Accès depuis l'hôte (défaut) |
|---|---|---|
| `web` | nginx → PHP-FPM | http://localhost:8100 (`HTTP_PORT`) |
| `php` | PHP-FPM 8.5, Composer, Xdebug (`XDEBUG_MODE=off` par défaut) | — |
| `database` | PostgreSQL 18 | port aléatoire : `docker compose port database 5432` |
| `redis` | Redis 8 | — |
| `mercure` | Hub Mercure | http://localhost:3100 (`MERCURE_PORT`) |
| `mailer` | Mailpit (SMTP + interface) | http://localhost:8125 (`MAILPIT_PORT`) |

**Mercure** : hub v1, protocole 1.0. Les JWT suivent la RFC 9068 (`iss`, `sub`, `client_id`, `aud`, `exp`) ; l'émetteur `MERCURE_JWT_ISSUER` doit figurer dans `MERCURE_TRUSTED_ISSUERS` du hub, et l'audience est l'URL publique, épinglée par `resource_identifier`. Un abonnement utilise le paramètre `match` (plus `topic`). En test, `MockHub` partout sauf `tests/Integration/MercureHubTest.php`, qui valide la configuration contre le vrai hub.

Le conteneur `php` tourne avec l'UID/GID de l'hôte (`UID`, `GID`, 1000 par défaut) : les fichiers générés t'appartiennent.

## Commandes

Tout passe par le `Makefile` (`make help`) :

| Commande | Rôle |
|---|---|
| `make qa` | Toutes les vérifications de la CI — à lancer avant chaque PR |
| `make cs-fix` / `make twig-cs-fix` / `make biome-fix` | Corrige le style PHP / Twig / JS-CSS |
| `make stan` | PHPStan niveau 6 |
| `make test`, `make test-unit`, `make coverage` | Tests PHPUnit |
| `make fixtures` | Applique les migrations (`make db`) puis charge les données de développement (galaxie générée, graine 1) |
| `docker compose exec php bin/console app:galaxy:generate` | Génère une galaxie ; `--seed` la rend reproductible (`--help` pour les options) |

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

### Branches

| Branche | Rôle | Reçoit des PR de |
|---|---|---|
| `develop` | Intégration, branche par défaut | branches de travail |
| `staging` | Préproduction | `develop` (livraison), `hotfix/*` |
| `main` | Production | `staging` (livraison), `hotfix/*` |

- La production est `main` (convention actuelle de git et GitHub) ; il n'existe pas de branche `master`.
- Branche de travail par issue, **créée depuis `develop`** et fusionnée dans `develop` : `<type>/<numéro>-<slug>`, ex. `feat/33-suite-ordres`.
- **Livraison** : PR `develop` → `staging`, puis `staging` → `main`, avec le label « Livraison » (skill `pull-request`).
- **Correctif urgent** : `hotfix/<numéro>-<slug>` créée depuis `main`, PR vers `main`, puis la même branche en PR vers `staging` et `develop` pour ne pas perdre le correctif.
- Fusion par **commit de fusion** uniquement (squash et rebase désactivés), pour garder les trois branches alignées.
- Jamais de push direct sur `develop`, `staging` ou `main` : bloqué par le hook `.claude/hooks/garde-git.sh` et par le ruleset GitHub « Branches protégées » (PR obligatoire, checks de CI verts, conversations résolues, pas de force push ni de suppression). Les administrateurs peuvent passer outre en cas d'urgence ; Claude ne le fait jamais.
- Un nouveau job de CI devient obligatoire en ajoutant son nom aux checks requis du ruleset (`gh api repos/space-guardians/Game/rulesets`).
- Les issues se ferment à la fusion dans `develop` (branche par défaut) via `Closes #N`.

### Worktrees

Claude travaille chaque issue dans un **worktree** dédié, sans toucher au dépôt principal (où l'utilisateur peut avoir sa propre branche en cours) :

```bash
git fetch origin
git worktree add .claude/worktrees/<type>-<N>-<slug> -b <type>/<N>-<slug> origin/develop
```

puis bascule la session dedans (outil `EnterWorktree` avec ce chemin). `.claude/worktrees/` est ignoré par git.

- Le projet Compose prend le nom du dossier : chaque worktree a ses propres conteneurs, base et volumes. Démarrer avec `make up` depuis le worktree.
- Deux environnements démarrés en même temps se disputent les ports publiés : changer `HTTP_PORT`, `MERCURE_PORT`, `MAILPIT_PORT` pour l'un d'eux (ex. `HTTP_PORT=8200 make up`).
- Après la fusion : `docker compose down -v` dans le worktree, puis `git worktree remove .claude/worktrees/<dossier>` et suppression de la branche locale.

### Commits et PR

- **Commits** : Conventional Commits, description en français, pied liant l'issue. Vérifié par `.githooks/commit-msg` (activé par `make hooks`) et en CI.
  ```
  feat(flotte): ajoute l'enchaînement des ordres de flotte

  Refs #33
  ```
  Types : `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. Portées : `infra`, `ci`, `univers`, `compte`, `economie`, `recherche`, `flotte`, `combat`, `defense`, `espionnage`, `alliance`, `chat`, `messagerie`, `marche`, `notif`, `pve`, `admin`, `docs`, `release`.
- **PR** : courtes et lisibles. Modèle `.github/pull_request_template.md` : issue(s) (`Closes #n` / `Refs #n`), 1 à 3 phrases, quelques points techniques. Titre au format Conventional Commits. Chaque PR porte un label de type (Fonctionnalité, Correction, Documentation, Refactorisation, Performance, Tests, CI / Build, Maintenance), un label de zone (Développement, Administration, Configuration) et le jalon de son issue — vérifié par `.github/workflows/pr.yml`. Voir le skill `pull-request`.
- **Issues** : tenues à jour en continu (statut dans le projet, cases cochées, dates). Voir le skill `suivi-issue`.

## Skills du projet

- `suivi-issue` : démarrer, suivre et clôturer une issue (statut, cases, dates, nouvelles issues découvertes).
- `commit` : rédiger et créer un commit conforme.
- `pull-request` : ouvrir ou mettre à jour une PR conforme (issues liées, labels, jalon), y compris les PR de livraison.
- `qualite` : lancer et corriger les vérifications avant PR.
- `tests` : écrire les tests au bon niveau avec Foundry.
