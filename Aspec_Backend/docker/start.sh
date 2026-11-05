#!/usr/bin/env bash
# Arranque do contentor no Render Free (sem workers nem cron à parte): migrations, caches do Laravel,
# fila e scheduler em segundo plano e o Apache em primeiro plano.
set -euo pipefail

cd /var/www/html

# Tudo como www-data (o utilizador do Apache), para nenhum ficheiro de cache ficar de root.
as_www() {
    runuser -u www-data -- "$@"
}

# Com set -e, uma migration falhada impede o arranque e o Render mantém a versão anterior.
as_www php artisan migrate --force

# Caches criadas aqui e não no build: as variáveis de ambiente do Render só existem em runtime.
as_www php artisan optimize

# Se o worker ou o scheduler terminarem (erro, --memory, --max-time), voltam a arrancar 5 s depois.
(while true; do as_www php artisan queue:work --sleep=3 --tries=3 --max-time=3600 --memory=128 || true; sleep 5; done) &
(while true; do as_www php artisan schedule:work || true; sleep 5; done) &

# exec: o Apache fica com o PID 1 e recebe o SIGTERM do Render.
exec apache2-foreground
