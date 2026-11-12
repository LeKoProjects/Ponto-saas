#!/bin/sh
# Prepara o container PHP antes de iniciar o processo (php-fpm, horizon ou scheduler).
#
# - vendor/ fica num volume do Docker (rápido no Windows/macOS): o serviço "php"
#   instala as dependências na primeira subida; worker e scheduler esperam.
# - storage/ e bootstrap/cache precisam ser graváveis pelo PHP.
set -e
cd /var/www/html

if [ "${INSTALL_DEPS:-0}" = "1" ]; then
    if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
        echo "[entrypoint] composer install…"
        composer install --no-interaction --prefer-dist --no-progress
        touch vendor/autoload.php
    fi
else
    i=0
    until [ -f vendor/autoload.php ]; do
        i=$((i + 1))
        [ "$i" -gt 180 ] && echo "[entrypoint] vendor/ não ficou pronto" && exit 1
        echo "[entrypoint] aguardando o serviço php instalar o vendor/…"
        sleep 2
    done
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

exec "$@"
