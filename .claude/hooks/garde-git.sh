#!/usr/bin/env bash
# PreToolUse (Bash) : empêche de contourner les conventions git du projet.
set -uo pipefail

cmd="$(python3 -c 'import json, sys; print(json.load(sys.stdin).get("tool_input", {}).get("command", ""))')"

block() {
    echo "$1" >&2
    exit 2
}

if [[ "$cmd" =~ git[[:space:]].*--no-verify ]]; then
    block "Interdit : --no-verify contourne le contrôle des messages de commit. Corriger le message (skill commit)."
fi

if [[ "$cmd" =~ git[[:space:]]+push ]] && [[ "$cmd" =~ (^|[[:space:]:])master([[:space:]]|$) ]]; then
    block "Interdit : pas de push direct sur master. Passer par une branche et une PR (skill pull-request)."
fi

exit 0
