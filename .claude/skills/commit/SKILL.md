---
name: commit
description: Créer un ou plusieurs commits conformes aux conventions de Space Guardians (Conventional Commits, message en français, lien vers l'issue). À utiliser à chaque fois qu'il faut committer.
---

# Commit

## Avant de committer

1. `git status` et `git diff` : relire ce qui part. Pas de fichier parasite (`*:Zone.Identifier`, `var/`, rapports, `.env.local`).
2. Corriger le style des fichiers modifiés : `make cs-fix`, `make twig-cs-fix`, `make biome-fix` selon les fichiers touchés.
3. Vérifier qu'on est sur une branche de travail (`<type>/<N>-<slug>`, créée depuis `develop`) et jamais sur `develop`, `staging` ou `main`. Identifier l'issue concernée à partir du nom de la branche. Sans issue identifiable, demander à l'utilisateur plutôt qu'inventer un numéro.

## Découpage

Un commit = un changement cohérent qui compile et passe les tests. Séparer par exemple : la migration et l'entité, le service, l'écran, les tests associés peuvent aller avec le code qu'ils testent. Ne pas mélanger un refactoring et une fonctionnalité.

## Message

```
<type>(<portée>): <description en français, à l'impératif présent ou 3e personne, sans point final>

<corps facultatif : le pourquoi, pas le comment, lignes de 72 caractères>

Refs #<N>
```

- Types : `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`. `!` après la portée pour un changement cassant.
- Portées : `infra`, `ci`, `univers`, `compte`, `economie`, `recherche`, `flotte`, `combat`, `defense`, `espionnage`, `alliance`, `chat`, `messagerie`, `marche`, `notif`, `pve`, `admin`, `docs`, `release` (PR de livraison).
- Titre ≤ 72 caractères, en minuscules après le `:`.
- Pied : `Refs #N` (plusieurs issues : une ligne chacune). La fermeture des issues se fait par la PR (`Closes #N`), pas par les commits.

Exemples :

```
feat(economie): calcule la production des mines à la volée

Refs #19
```

```
fix(flotte): immobilise la flotte au point exact de panne sèche

La position était calculée sur le segment suivant quand la panne
survenait en fin de segment.

Refs #38
```

## Créer le commit

Passer le message par un heredoc pour garder les sauts de ligne :

```bash
git commit -F - <<'EOF'
feat(economie): calcule la production des mines à la volée

Refs #19
EOF
```

Le hook `.githooks/commit-msg` refuse un message non conforme (activé par `make hooks`) ; la CI refait la vérification sur chaque PR. En cas de refus, corriger le message, ne jamais contourner avec `--no-verify`.
