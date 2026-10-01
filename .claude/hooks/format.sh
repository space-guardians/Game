#!/usr/bin/env bash
# PostToolUse (Edit/Write) : formate le fichier modifié si l'environnement Docker tourne.
# Ne bloque jamais : sans conteneur ou sans outil installé, ne fait rien.
set -uo pipefail

cd "${CLAUDE_PROJECT_DIR:-.}" || exit 0

file="$(python3 -c 'import json, sys; print(json.load(sys.stdin).get("tool_input", {}).get("file_path", ""))')"
file="${file#"$PWD"/}"

case "$file" in
    *.php) tool=(vendor/bin/php-cs-fixer fix --quiet) ;;
    templates/*.twig) tool=(vendor/bin/twig-cs-fixer lint --fix --no-cache) ;;
    *) exit 0 ;;
esac

[[ -f "$file" && -x "${tool[0]}" ]] || exit 0
docker compose ps --status running --services 2>/dev/null | grep -qx php || exit 0

docker compose exec -T php "${tool[@]}" "$file" >/dev/null 2>&1
exit 0
