# Toutes les commandes s'exécutent dans le service « php » du Docker Compose.
DC      = docker compose
PHP     = $(DC) exec php
CONSOLE = $(PHP) bin/console
BIOME   = docker run --rm -u "$$(id -u):$$(id -g)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app node:22-alpine npx --yes @biomejs/biome@2.5.15

.DEFAULT_GOAL := help
.PHONY: help hooks up down sh qa cs cs-fix twig-cs twig-cs-fix stan lint biome biome-fix db \
        test test-unit test-integration test-functional coverage db-test fixtures

help: ## Liste les commandes
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

hooks: ## Active les hooks git du projet (contrôle des messages de commit)
	git config core.hooksPath .githooks

## —— Docker ————————————————————————————————————————————
up: ## Démarre l'environnement
	$(DC) up -d --wait

down: ## Arrête l'environnement
	$(DC) down

sh: ## Ouvre un shell dans le conteneur PHP
	$(PHP) sh

## —— Qualité ———————————————————————————————————————————
qa: cs twig-cs stan lint biome test ## Lance toutes les vérifications (comme la CI)

cs: ## Vérifie le style PHP (PER-CS 3.1)
	$(PHP) vendor/bin/php-cs-fixer check --diff

cs-fix: ## Corrige le style PHP
	$(PHP) vendor/bin/php-cs-fixer fix

twig-cs: ## Vérifie le style Twig
	$(PHP) vendor/bin/twig-cs-fixer lint

twig-cs-fix: ## Corrige le style Twig
	$(PHP) vendor/bin/twig-cs-fixer lint --fix

stan: ## Analyse statique PHPStan (niveau 6)
	$(CONSOLE) cache:warmup --env=test
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint: ## Composer, YAML, Twig, conteneur et mapping Doctrine
	$(PHP) composer validate --strict
	$(PHP) composer audit
	$(CONSOLE) lint:yaml config --parse-tags
	$(CONSOLE) lint:twig templates
	$(CONSOLE) lint:container
	$(CONSOLE) doctrine:schema:validate --skip-sync

biome: ## Vérifie JS / CSS / JSON (Biome)
	$(BIOME) check --no-errors-on-unmatched .

biome-fix: ## Corrige JS / CSS / JSON
	$(BIOME) check --write --no-errors-on-unmatched .

## —— Tests —————————————————————————————————————————————
test: db-test ## Lance tous les tests
	$(PHP) vendor/bin/phpunit

test-unit: ## Tests unitaires
	$(PHP) vendor/bin/phpunit --testsuite unit

test-integration: db-test ## Tests d'intégration
	$(PHP) vendor/bin/phpunit --testsuite integration

test-functional: db-test ## Tests fonctionnels
	$(PHP) vendor/bin/phpunit --testsuite functional

coverage: db-test ## Tests avec couverture (rapport HTML dans var/reports/coverage)
	$(DC) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-html var/reports/coverage --coverage-text

db-test: ## (Re)crée la base de test
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

## —— Données ———————————————————————————————————————————
db: ## Applique les migrations à la base de développement
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

# Contenu de jeu installé par les migrations (types de bâtiments…) : conservé lors du chargement des fixtures
CONTENT_TABLES = building_type technology prerequisite ship_class ship_type quest_template quest_outcome

fixtures: db ## Charge les fixtures de développement (migrations comprises)
	$(CONSOLE) doctrine:fixtures:load --no-interaction $(addprefix --purge-exclusions=,$(CONTENT_TABLES))
