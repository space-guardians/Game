#!/bin/sh
# Déploiement d'une version sur le serveur (docs/exploitation.md), lancé par le workflow « Déploiement » (#93) depuis
# le dossier de l'environnement, déjà placé sur le commit à déployer :
#   sh docker/deploy/deploy.sh <commit> <projet Compose> [<préfixe des images>]
# Tire les images de ce commit (IMAGE_TAG), redémarre l'environnement (migrations comprises, worker relancé), vérifie
# qu'il répond ; sinon revient à la version précédente (.deployed) et échoue.
set -eu

SHA="${1:?commit à déployer}"
PROJECT="${2:?projet Compose (space-guardians-preprod, space-guardians-prod)}"
PREFIX="${3:-}"

# Variables de l'environnement (ports publiés…) ; le commit déployé et le registre du workflow priment
set -a
# shellcheck source=/dev/null
. ./.env.server
set +a
export IMAGE_TAG="$SHA"
if [ -n "$PREFIX" ]; then
    export IMAGE_PREFIX="$PREFIX"
fi

compose() {
    docker compose -f compose.prod.yaml --env-file .env.server -p "$PROJECT" "$@"
}

# Tests de fumée : application et ses dépendances (/sante), page de connexion, hub Mercure (400 sans topic)
smoke() {
    curl --fail --silent --show-error --max-time 10 "http://127.0.0.1:${HTTP_PORT}/sante" > /dev/null \
        && curl --fail --silent --show-error --max-time 10 --output /dev/null "http://127.0.0.1:${HTTP_PORT}/connexion" \
        && [ "$(curl --silent --max-time 10 --output /dev/null --write-out '%{http_code}' "http://127.0.0.1:${MERCURE_PORT}/.well-known/mercure")" -lt 500 ]
}

previous=$(cat .deployed 2>/dev/null || true)

echo "Déploiement de ${SHA} (${PROJECT}) ; version en place : ${previous:-aucune}"
compose pull --quiet
if compose up --detach --wait --remove-orphans && smoke; then
    echo "$SHA" > .deployed
    echo "Version ${SHA} en ligne."
    exit 0
fi

echo "Échec du déploiement de ${SHA}." >&2
if [ -n "$previous" ] && [ "$previous" != "$SHA" ]; then
    echo "Retour à ${previous} (les migrations déjà appliquées restent : docs/exploitation.md)." >&2
    git checkout --quiet --detach "$previous"
    export IMAGE_TAG="$previous"
    compose up --detach --wait --remove-orphans || echo "Le retour arrière a échoué lui aussi : intervention manuelle." >&2
fi
exit 1
