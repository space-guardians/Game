---
name: qualite
description: Lancer et corriger les vérifications qualité de Space Guardians (PHP-CS-Fixer PER-CS 3.1, PHPStan niveau 6, Twig-CS-Fixer, Biome, lints Symfony, PHPUnit) avant un commit ou une PR, ou quand la CI échoue.
---

# Qualité

Les mêmes vérifications tournent en local (`Makefile`) et en CI (`.github/workflows/ci.yml`).

## Ordre

1. Environnement démarré : `make up`.
2. Corrections automatiques d'abord : `make cs-fix`, `make twig-cs-fix`, `make biome-fix`.
3. Puis les vérifications : `make stan`, `make lint`, `make test` — ou tout d'un coup : `make qa`.

## Corriger correctement

- **PHPStan** : corriger la cause (types manquants, null non géré, tableau non typé). Pas de baseline, pas de `@phpstan-ignore` sans commentaire expliquant pourquoi l'erreur est un faux positif. Ne jamais baisser le niveau.
- **Tests en échec** : comprendre l'échec avant de toucher au test. On ne modifie un test que si le comportement attendu a réellement changé (et l'issue le dit).
- **Lint Doctrine** (`doctrine:schema:validate`) : mapping incohérent → corriger l'entité, puis générer la migration (`bin/console doctrine:migrations:diff`).
- **Biome / Twig-CS-Fixer** : relancer le `-fix` ; ce qui reste demande une correction manuelle.

## CI en échec sur une PR

```bash
gh pr checks
gh run view <run-id> --log-failed
```

Les erreurs PHPStan et PHP-CS-Fixer apparaissent aussi en annotations sur les lignes concernées de la PR ; les résultats de tests et la couverture en commentaires.
