#!/usr/bin/env bash
# PreToolUse (Bash) : empêche de contourner les conventions git du projet.
# Analyse chaque commande d'une ligne composée (&&, ||, ;, |) séparément.
exec python3 -c '
import json, re, shlex, sys

command = json.load(sys.stdin).get("tool_input", {}).get("command", "")
# Le contenu des heredocs (messages de commit, corps de PR) n est pas une commande
command = re.sub(r"<<-?\s*([\x27\"]?)(\w+)\1.*?\n.*?^\s*\2\s*$", "", command, flags=re.S | re.M)

PROTECTED = {"develop", "staging", "main"}

def block(message):
    print(message, file=sys.stderr)
    sys.exit(2)

for segment in re.split(r"&&|\|\||[;|\n]", command):
    try:
        words = shlex.split(segment)
    except ValueError:
        words = segment.split()
    if "git" not in words:
        continue
    args = words[words.index("git") + 1:]
    if "--no-verify" in args:
        block("Interdit : --no-verify contourne le contrôle des messages de commit. Corriger le message (skill commit).")
    if args[:1] == ["push"] and any(a.rsplit(":", 1)[-1] in PROTECTED for a in args[1:]):
        block("Interdit : pas de push direct sur develop, staging ou main. Passer par une branche et une PR (skill pull-request).")
'
