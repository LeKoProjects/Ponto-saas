#!/bin/sh
# Backup diário do Postgres (plano: pg_dump diário para S3, retenção de 30 dias).
# Uso no cron da VPS:  0 3 * * * /opt/ponto-saas/docker/backup/pg_backup.sh
# Requer: aws-cli configurado e a variável BACKUP_BUCKET (ex.: s3://ponto-backups).
set -eu

STAMP=$(date +%Y%m%d-%H%M%S)
FILE="/tmp/ponto-${STAMP}.sql.gz"
COMPOSE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

docker compose -f "${COMPOSE_DIR}/docker-compose.yml" exec -T postgres \
    pg_dump -U ponto -d ponto --no-owner --format=plain | gzip -9 > "${FILE}"

aws s3 cp "${FILE}" "${BACKUP_BUCKET}/daily/ponto-${STAMP}.sql.gz" --only-show-errors
rm -f "${FILE}"

# Retenção: configure uma lifecycle rule no bucket expirando "daily/" em 30 dias.
echo "Backup enviado: ponto-${STAMP}.sql.gz"
