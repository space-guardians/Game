# Space Guardians

[![CI](https://github.com/space-guardians/Game/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/space-guardians/Game/actions/workflows/ci.yml)
[![Tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/space-guardians/Game/badges/tests.json)](https://github.com/space-guardians/Game/actions/workflows/ci.yml)
[![Couverture](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/space-guardians/Game/badges/coverage.json)](https://github.com/space-guardians/Game/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/space-guardians/Game/badges/phpstan.json)](https://github.com/space-guardians/Game/actions/workflows/ci.yml)
[![Style](https://img.shields.io/badge/style-PER--CS%203.1-blue)](https://www.php-fig.org/per/coding-style/)

Jeu de gestion spatiale multijoueur en temps réel, en PHP / Symfony.

- [Cahier des charges](space-guardians-cahier-des-charges.md)
- [Planning (vue « Lot »)](https://github.com/users/space-guardians/projects/1/views/1)
- [Conventions de développement](CLAUDE.md)

## Démarrer

```bash
make hooks   # active le contrôle des messages de commit
make up      # démarre l'environnement Docker
make help    # liste les commandes
```

| Service | Adresse |
|---|---|
| Application | http://localhost:8100 |
| Hub Mercure | http://localhost:3100 |
| Mailpit (e-mails de dev) | http://localhost:8125 |
| PostgreSQL | `docker compose port database 5432` |

Ports modifiables avec `HTTP_PORT`, `MERCURE_PORT` et `MAILPIT_PORT` (ex. `HTTP_PORT=8200 make up`).
