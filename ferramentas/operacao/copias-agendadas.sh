#!/bin/sh
# Processo principal do serviço "copias" (docker-compose.prod.yml): agenda backup.sh com o crond do Alpine.
#   ERP_COPIAS_CRON          expressão cron (por omissão "30 2 * * *" = todos os dias às 02:30, hora de Luanda)
#   ERP_COPIAS_AO_ARRANCAR   1 = faz também uma cópia logo no arranque do serviço (0)
# O crond não passa o ambiente do contentor às tarefas: as variáveis são gravadas num ficheiro só de root.
set -eu

CRON="${ERP_COPIAS_CRON:-30 2 * * *}"
AMBIENTE=/run/erp-copias.env

umask 077
export -p | grep -E 'export (PG|POSTGRES_|ERP_COPIAS_|TZ)' > "$AMBIENTE"

mkdir -p /etc/crontabs
printf '%s . %s; /operacao/backup.sh >> /proc/1/fd/1 2>> /proc/1/fd/2\n' "$CRON" "$AMBIENTE" > /etc/crontabs/root

echo "[copias] agendado: '$CRON' (TZ=${TZ:-UTC}) -> ${ERP_COPIAS_DESTINO:-/copias}"
if [ "${ERP_COPIAS_AO_ARRANCAR:-0}" = "1" ]; then
    /operacao/backup.sh || echo "[copias] AVISO: a cópia inicial falhou (ver acima)." >&2
fi
exec crond -f -l 6 -c /etc/crontabs
