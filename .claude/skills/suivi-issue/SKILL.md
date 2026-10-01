---
name: suivi-issue
description: Démarrer, suivre et clôturer une issue GitHub de Space Guardians — vérifier ses dépendances, passer son statut dans le projet, cocher sa checklist, ajuster ses dates, créer les issues découvertes en cours de route. À utiliser au début et à la fin de tout travail sur une issue, et dès que le périmètre d'une issue change.
---

# Suivi d'une issue

Dépôt `space-guardians/Game`, projet GitHub n° 1 (propriétaire `space-guardians`), vue roadmap « Lot ».

## Identifiants du projet

| Élément | ID |
|---|---|
| Projet | `PVT_kwHOAjWXuM4BiAID` |
| Champ Status | `PVTSSF_lAHOAjWXuM4BiAIDzhg5s2I` — options : Todo `f75ad846`, In Progress `47fc9ee4`, Done `98236657` |
| Champ Date de début | `PVTF_lAHOAjWXuM4BiAIDzhg50DA` |
| Champ Date de fin | `PVTF_lAHOAjWXuM4BiAIDzhkBPL8` |

ID de l'élément de projet d'une issue `N` :

```bash
gh project item-list 1 --owner space-guardians --limit 500 --format json --jq '.items[] | select(.content.number == N) | .id'
```

## Démarrer une issue

1. Lire l'issue et les sections du cahier des charges qu'elle cite : `gh issue view N`.
2. Vérifier ses prérequis :
   ```bash
   gh api repos/space-guardians/Game/issues/N/dependencies/blocked_by --jq '.[] | "#\(.number) \(.state) \(.title)"'
   ```
   S'il en reste d'ouverts, le signaler à l'utilisateur avant de commencer.
3. Passer le statut à **In Progress** :
   ```bash
   gh project item-edit --project-id PVT_kwHOAjWXuM4BiAID --id <ITEM_ID> --field-id PVTSSF_lAHOAjWXuM4BiAIDzhg5s2I --single-select-option-id 47fc9ee4
   ```
4. Créer la branche dans un **worktree** dédié, depuis `develop` à jour, puis y basculer la session (outil `EnterWorktree` avec `path`) :
   ```bash
   git fetch origin
   git worktree add .claude/worktrees/<type>-N-<slug> -b <type>/N-<slug> origin/develop
   ```
   Correctif urgent en production : `hotfix/N-<slug>` depuis `origin/main` (voir CLAUDE.md, sections « Branches » et « Worktrees »).
5. Démarrer l'environnement du worktree : `make up` (changer `HTTP_PORT`, `MERCURE_PORT`, `MAILPIT_PORT` si un autre environnement tourne déjà).

## Pendant le travail

- **Cocher les cases** de la checklist au fur et à mesure qu'elles sont terminées (pas toutes à la fin) :
  ```bash
  gh issue view N --json body --jq .body > /tmp/issue-N.md   # éditer, puis :
  gh issue edit N --body-file /tmp/issue-N.md
  ```
- **Périmètre qui change** : modifier la checklist de l'issue et laisser un commentaire court expliquant pourquoi (`gh issue comment N --body "…"`).
- **Travail découvert hors périmètre** : ne pas l'absorber silencieusement. Créer une nouvelle issue (même format : checklist + `> Cf. §x`), dans le jalon de la phase concernée, avec son ou ses labels de zone (`Développement`, `Administration`, `Configuration`) — plus `Correction` s'il s'agit d'un bug — puis la relier :
  ```bash
  ID=$(gh api repos/space-guardians/Game/issues/<BLOQUANTE> --jq .id)
  gh api -X POST repos/space-guardians/Game/issues/<BLOQUÉE>/dependencies/blocked_by -F issue_id=$ID
  ```
  Ajouter l'issue au projet (`gh project item-add 1 --owner space-guardians --url <url>`) et lui donner des dates cohérentes avec ses dépendances.
- **Décision technique notable** (choix de librairie, écart avec le cahier des charges) : la consigner en commentaire de l'issue ; si elle modifie le besoin, mettre aussi à jour le cahier des charges.

## Dates

Si l'issue déborde de sa « Date de fin », la repousser et décaler les dates des issues qu'elle bloque si elles deviennent incohérentes :

```bash
gh project item-edit --project-id PVT_kwHOAjWXuM4BiAID --id <ITEM_ID> --field-id PVTF_lAHOAjWXuM4BiAIDzhkBPL8 --date AAAA-MM-JJ
```

Prévenir l'utilisateur de tout décalage en cascade.

## Terminer

1. Toutes les cases cochées (ou retirées avec justification en commentaire).
2. PR vers `develop` ouverte avec `Closes #N` (skill `pull-request`) : l'issue se ferme à la fusion dans `develop`.
3. Après la fusion, vérifier que l'issue est fermée et passer le statut à **Done** (`98236657`) si l'automatisation du projet ne l'a pas fait. Nettoyer : branche distante (`git push origin --delete <branche>`), conteneurs du worktree (`docker compose down -v` depuis le worktree), puis sortie du worktree (`ExitWorktree`), `git worktree remove .claude/worktrees/<dossier>` et `git branch -D <branche>`.
4. Issue seulement avancée (`Refs #N`) : laisser un commentaire résumant ce que la PR fusionnée a apporté et ce qui reste à faire.
