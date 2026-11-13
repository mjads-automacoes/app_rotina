#!/bin/sh
# Partida do container (Docker/Easypanel).
# 1. O volume /data costuma chegar como root: o Apache (www-data) precisa gravar o banco nele.
# 2. As automacoes rodam a cada minuto aqui dentro, sem depender do cron do servidor.
set -e

mkdir -p "${ROTINA_DATA_DIR:-/data}"
chown -R www-data:www-data "${ROTINA_DATA_DIR:-/data}"

(
    while true; do
        sleep 60
        su -s /bin/sh www-data -c "php /var/www/html/scripts/tick.php" >/dev/null 2>&1 || true
    done
) &

exec "$@"
