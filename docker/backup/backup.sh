#!/bin/sh
# Sauvegarde de la base (docs/exploitation.md) : un pg_dump au format custom chaque jour à BACKUP_HOUR (UTC),
# puis suppression des sauvegardes plus vieilles que BACKUP_RETENTION_DAYS jours. Restauration : pg_restore.
set -eu

: "${POSTGRES_DB:?}" "${POSTGRES_USER:?}" "${POSTGRES_PASSWORD:?}"
HOUR="${BACKUP_HOUR:-3}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
export PGPASSWORD="$POSTGRES_PASSWORD"

backup() {
    file="/backups/${POSTGRES_DB}-$(date -u +%Y%m%d-%H%M%S).dump"
    # Écrit sous un nom temporaire : une sauvegarde interrompue ne passe jamais pour complète
    pg_dump --host=database --username="$POSTGRES_USER" --format=custom --file="$file.partial" "$POSTGRES_DB"
    mv "$file.partial" "$file"
    find /backups -name "${POSTGRES_DB}-*.dump" -mtime +"$RETENTION" -delete
    echo "Sauvegarde : $file ($(du -h "$file" | cut -f1))"
}

# Une sauvegarde au démarrage, puis chaque jour à l'heure dite
backup
while true; do
    now=$(date -u +%s)
    # Minuit UTC du jour + l'heure dite ; le lendemain si elle est passée
    next=$(( now - now % 86400 + HOUR * 3600 ))
    if [ "$next" -le "$now" ]; then
        next=$(( next + 86400 ))
    fi
    sleep $(( next - now ))
    backup
done
