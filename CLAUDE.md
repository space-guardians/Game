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
| `worker` | Consomme la file Messenger `async` (e-mails, générations de galaxie, réveils des événements planifiés…) et les tâches récurrentes `scheduler_default` ; `docker compose restart worker` après une modification du code | — |

**Mercure** : hub v1, protocole 1.0. Les JWT suivent la RFC 9068 (`iss`, `sub`, `client_id`, `aud`, `exp`) ; l'émetteur `MERCURE_JWT_ISSUER` doit figurer dans `MERCURE_TRUSTED_ISSUERS` du hub, et l'audience est l'URL publique, épinglée par `resource_identifier`. Un abonnement utilise le paramètre `match` (plus `topic`). En test, `MockHub` partout sauf `tests/Integration/MercureHubTest.php`, qui valide la configuration contre le vrai hub. Notifications du joueur : topics privés `/empire/{id}` et `/planet/{id}` (`GameTopics`), auxquels le gabarit applicatif s'abonne (`<twig:Turbo:Stream:From … private/>`) ; un écouteur de `ScheduledEventResolved` publie un Turbo Stream (gabarits `templates/streams/`). Cookie d'abonnement : `MERCURE_COOKIE_NAME` (sans préfixe `__Secure-` en HTTP), même nom côté hub.

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
| `docker compose exec php bin/console app:galaxy:generate` | Génère une galaxie ; `--seed` la rend reproductible, `--template="<nom>"` utilise un gabarit de forme du panneau (`--help` pour les options). Même service (`GalaxyCreator`) que la génération depuis le panneau (`/admin/generations`, exécutée par le `worker`) |
| `docker compose exec php bin/console app:admin:create <email> [--role=…]` | Crée un compte d'administration (mot de passe demandé en saisie masquée) |

**Panneau d'administration** : http://localhost:8100/admin, avec des comptes `AdminUser` distincts des joueurs (pare-feu `admin`). Les rôles sont l'enum `App\Enum\Admin\AdminRole` (`Moderator` < `GameDesigner` < `Admin` < `SuperAdmin`, valeurs `ROLE_…`) ; jamais de chaîne `'ROLE_…'` en dur, et la `role_hierarchy` de `security.yaml` suit l'ordre de l'enum (vérifié par un test). Chaque écran déclare le rôle minimal requis (§5.6.2) **sur toutes ses actions**, consultation comprise : `Actions::setPermission(Action::INDEX / DETAIL / EDIT…, AdminRole::GameDesigner->value)` et `MenuItem::setPermission()`. `Crud::setEntityPermission()` ne fait que filtrer les lignes affichées, il n'interdit pas la page. Une action personnalisée (méthode du contrôleur CRUD) n'est **pas** protégée par `setPermission()` : elle appelle elle-même `denyAccessUnlessGranted()`, n'accepte que POST et vérifie l'origine. Un test fonctionnel vérifie le refus (403) pour le rôle juste en dessous.

Sécurité du panneau : double authentification TOTP obligatoire (scheb/2fa ; tant qu'elle n'est pas activée, seule `/admin/double-authentification/activation` est accessible), déconnexion après 30 min d'inactivité (`app.admin_idle_timeout`), comptes gérés par la super administration (`/admin/comptes`). En test, `AdminUserFactory` crée des comptes à 2FA activée (`totpCode($clock->now())` donne le code, `withoutTwoFactor()` pour un compte à activer).

Tableau de bord (`/admin`, §5.6.1) : indicateurs calculés par `AdminIndicators` (joueurs actifs d'après `User::lastActiveAt`, noté par `PlayerActivityRecorder` ; événements planifiés en attente par type, libellés dans `AdminIndicators::EVENT_LABELS`) ; alertes d'exploitation visibles à partir du rôle `Admin`. Supervision (rôle `Admin`) : files Messenger (`/admin/files-messages`, `MessengerSupervision` : relance d'un message en échec dans sa file d'origine, suppression) et événements planifiés (`/admin/evenements-planifies`, relance d'un événement en échec ou en retard via `EventScheduler::retry()`). Joueurs (`/admin/joueurs`, rôle `Moderator`) : CRUD sur `Empire` en lecture seule, fiche assemblée par `PlayerFiles` (planètes, ressources à l'instant, bâtiments, construction, journal d'activité = historique des modifications faites par le joueur via `EntityHistory::byAuthor()` + événements de ses planètes) ; sections à compléter au fil des phases. Actions POST personnalisées : `SameOriginTrait` + jeton CSRF ou formulaire EasyAdmin ; confirmation via le contrôleur Stimulus `confirm` du panneau.

Historique des données (`/admin/historique`, rôle `Admin`, §5.6.3) : `damienharper/auditor-bundle`. Toute entité pertinente porte `#[Auditable]` (libellé dans `EntityHistory::LABELS`, vérifié par un test) ; secrets et collections inverses volumineuses portent `#[Ignore]`. Journal des actions (`/admin/journal-actions`) : actions métier (génération, sanction, réinitialisation 2FA, relance ou suppression en supervision) via `AdminAudit::record()` en passant l'auteur hors requête ; table non modifiable (trigger).

## Code

### PHP
- Style **PER-CS 3.1** (PHP-CS-Fixer, `.php-cs-fixer.dist.php`), `declare(strict_types=1);` partout.
- **PHPStan niveau 6** sans baseline : pas d'erreur ignorée sans commentaire justifiant pourquoi. Typer les tableaux (`list<Fleet>`, `array<string, int>`, array shapes).
- Classes `final` par défaut, propriétés `readonly` quand c'est possible, injection par constructeur uniquement.
- **Organisation de `src/`** : par type, puis par domaine (`Account`, `Admin`, `Universe`…), jamais de bundle. Types Symfony dans leurs dossiers standards (`Controller`, `Entity`, `Repository`, `Form`, `Command`, `EventListener`, `Twig`…) ; services métier dans `Service/<Domaine>`, enums dans `Enum/<Domaine>`, DTO et objets de valeur dans `Model/<Domaine>`, exceptions dans `Exception/<Domaine>`. Les tests unitaires et d'intégration suivent la même arborescence.
- **Temps** : injecter `Psr\Clock\ClockInterface` (jamais `new \DateTimeImmutable()` ni `time()`), pour pouvoir figer l'horloge dans les tests. Tout le jeu repose sur des échéances.
- **Aléatoire** : injecter un `Random\Randomizer` (seed fixe en test) — génération de galaxie, précision en combat.
- Les règles de jeu (production, coûts, trajectoires, combat) sont des **services PHP purs**, sans Doctrine ni HTTP, testables unitairement et réutilisables pour les simulations d'équilibrage.
- Schéma de base : uniquement via migrations Doctrine générées (`doctrine:migrations:diff`), relues avant commit.
- Chaque entité de configuration de jeu a son écran EasyAdmin dans la même PR (§5.6).
- Contenu de jeu de départ (types de bâtiments, technologies, prérequis…) : installé par une migration (données), réglable ensuite dans le panneau. Sa table s'ajoute à `CONTENT_TABLES` du `Makefile`, pour que `make fixtures` ne l'efface pas.
- Arbre technologique (§4.4) : `Technology` (contenu), niveaux par empire (`Research`, `Empire::researchLevel()`), prérequis croisés `Prerequisite` (cible et requis : bâtiment ou technologie). Vérification : `PrerequisiteRules` (pur) via `PrerequisiteChecker` (bâtiments de la planète, technologies de son empire) ; une cible verrouillée lève `MissingPrerequisites`. Pour une technologie, un bâtiment requis se compte sur tout l'empire (somme des planètes). File de recherche : `ResearchQueue` (une par empire, verrou empire puis planète de lancement, qui paie ; annulation remboursée au prorata sur cette planète, plafonnée par son stockage), durée selon la somme des laboratoires (`ResearchRules`), fin `research.completed` (`ResearchCompletedHandler`) ; écran `/recherche`.

### Twig, JS, CSS
- Interface conforme à la [charte graphique](docs/charte-graphique.md) : couleurs et polices uniquement via les jetons `--sg-*` (`assets/styles/tokens.css`), composants de la charte en Twig Components (`templates/components/` : `Btn`, `Chip`, `AlertBanner`, `Icon`, `Logo`…), formulaires via le thème `templates/form/theme.html.twig`.
- Textes de l'interface rédigés directement en français (langue par défaut `fr`).
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
