---
name: pull-request
description: Ouvrir ou mettre à jour une pull request conforme aux conventions de Space Guardians — courte, liée à ses issues, avec labels et jalon — y compris les PR de livraison develop → staging → main. À utiliser quand le travail d'une branche est prêt à être revu, quand le périmètre d'une PR change, ou pour livrer en préproduction / production.
---

# Pull request

## Prérequis

1. `make qa` passe en local (skill `qualite`).
2. Les commits sont conformes (skill `commit`) et la branche est à jour avec `develop` (`git fetch && git rebase origin/develop`).
3. La checklist de l'issue est à jour (skill `suivi-issue`).

## Contenu

Une PR doit pouvoir être relue en quelques minutes. Si elle dépasse ~400 lignes de diff hors migrations et fichiers générés, proposer de la découper.

- **Titre** : au format Conventional Commits, en français, ex. `feat(economie): production des mines à la volée`.
- **Corps** (modèle `.github/pull_request_template.md`) :

```markdown
Closes #19

Calcule les ressources produites depuis la dernière mise à jour au lieu d'un cron par planète. La production est persistée à chaque action qui en a besoin.

**Technique**
- Service pur `ProductionCalculator`, horloge injectée
- Colonne `resources_updated_at` sur `Planet` + migration
- Plafonnement par la capacité des dépôts
```

Règles :
- Une ligne `Closes #N` par issue terminée ; `Refs #N` pour une issue seulement avancée.
- **1 à 3 phrases** de résumé : ce que fait la PR et pourquoi, pas la liste des fichiers.
- **2 à 5 puces** techniques : choix structurants, nouvelles entités/migrations, points d'attention pour la relecture.
- Ne pas recopier les résultats de tests, de couverture ou de PHPStan : la CI les publie elle-même en commentaire.

## Issues, labels et jalon (obligatoires sur chaque PR)

Le workflow `.github/workflows/pr.yml` refuse une PR sans issue liée, sans label de type, sans label de zone ou sans jalon.

### Issues

Chercher toutes les issues que la PR fait avancer : celle de la branche, les `Refs #N` des commits (`git log origin/develop..HEAD`), et les issues voisines dont une case est cochée par ce travail.

- Issue **terminée** par la PR → `Closes #N` : GitHub l'associe à la PR (encart « Development ») et la ferme à la fusion.
- Issue **seulement avancée** → `Refs #N` : la PR apparaît dans l'historique de l'issue, sans la fermer.

### Labels

Au moins un label de **type** et un label de **zone**.

| Type de commit dominant | Label de type |
|---|---|
| `feat` | Fonctionnalité |
| `fix` | Correction |
| `docs` | Documentation |
| `refactor`, `style` | Refactorisation |
| `perf` | Performance |
| `test` | Tests |
| `ci`, `build` | CI / Build |
| `chore` | Maintenance |

Ajouter un second label de type seulement si une autre nature de changement pèse vraiment dans la PR (ex. une fonctionnalité accompagnée d'une mise à jour du cahier des charges → `Fonctionnalité` + `Documentation`).

| Zone touchée | Label de zone |
|---|---|
| Règles de jeu, écrans, API | Développement |
| Back-office EasyAdmin | Administration |
| Docker, CI, outils qualité, configuration | Configuration |

Labels transverses, si applicables : `Changement cassant` (commit avec `!`, migration destructive, changement de contrat), `Dépendances` (mise à jour de paquets).

Si les issues liées ont des labels de zone, la PR reprend les mêmes.

### Jalon

Le jalon de l'issue principale (celle de la branche). Si la PR ferme des issues de plusieurs phases, prendre la phase la plus avancée parmi elles. Lister les jalons : `gh api repos/space-guardians/Game/milestones --jq '.[] | "\(.number) \(.title)"'`.

## Créer la PR

```bash
git push -u origin HEAD
gh pr create --base develop --title "<titre>" --body-file /tmp/pr.md \
  --label "Fonctionnalité" --label "Développement" --milestone "Phase 3 - Économie"
```

Pour modifier une PR existante, passer par l'API REST : `gh pr edit` échoue sur ce dépôt (bug lié aux « Projects classic »).

```bash
gh api -X POST repos/space-guardians/Game/issues/<PR>/labels -f "labels[]=Tests"
gh api -X DELETE "repos/space-guardians/Game/issues/<PR>/labels/<label encodé en URL>"
gh api -X PATCH repos/space-guardians/Game/issues/<PR> -F milestone=<numéro du jalon>
gh api -X PATCH repos/space-guardians/Game/pulls/<PR> -f body="$(cat /tmp/pr.md)"
```

Ensuite, suivre la CI (`gh pr checks --watch`) et corriger ce qui échoue avant de demander la relecture. Si le périmètre de la PR change en cours de relecture, remettre à jour issues, labels et jalon.

## PR de livraison

Promotion `develop` → `staging` (préproduction), puis `staging` → `main` (production). Seulement à la demande de l'utilisateur.

- **Titre** : `chore(release): livraison en préproduction du AAAA-MM-JJ` (ou `en production`).
- **Corps** : une ligne `Refs #N` par issue livrée (fermée depuis la livraison précédente), puis la liste des PR incluses :
  ```bash
  git log --merges --format='- %s' origin/staging..origin/develop   # ou origin/main..origin/staging
  ```
- **Label** : `Livraison` uniquement ; pas de label de zone ni de jalon (la livraison couvre plusieurs phases).
- Fusion par commit de fusion, comme toutes les PR.

```bash
gh pr create --base staging --head develop --title "chore(release): livraison en préproduction du 2026-11-17" \
  --body-file /tmp/release.md --label "Livraison"
```

## Correctif urgent (`hotfix/*`)

PR de `hotfix/N-<slug>` vers `main` (labels et jalon comme une PR normale, `Closes #N`), puis deux PR de la même branche vers `staging` et `develop` (`Refs #N`) pour que le correctif ne soit pas écrasé par la livraison suivante.
