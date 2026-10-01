---
name: pull-request
description: Ouvrir une pull request conforme aux conventions de Space Guardians — courte, liée à ses issues, facile à relire. À utiliser quand le travail d'une branche est prêt à être revu.
---

# Pull request

## Prérequis

1. `make qa` passe en local (skill `qualite`).
2. Les commits sont conformes (skill `commit`) et la branche est à jour avec `master` (`git fetch && git rebase origin/master`).
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

## Créer la PR

```bash
git push -u origin HEAD
gh pr create --base master --title "<titre>" --body-file /tmp/pr.md
```

Ensuite, suivre la CI (`gh pr checks --watch`) et corriger ce qui échoue avant de demander la relecture.
