#!/bin/bash
# WordPressBase Backup Script
# Usage: bash backup.sh [backup_dir]
# Example: bash backup.sh /www/backup/wordpress
# Without args, backs up to /www/backup/wordpress

set -e

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${1:-/www/backup/wordpress}"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_NAME="wpbase_${DATE}"

echo "=== WordPressBase Backup ==="
echo "Site root: ${SITE_ROOT}"
echo "Backup dir: ${BACKUP_DIR}"
echo ""

# Create backup directory
mkdir -p "${BACKUP_DIR}"

# 1. Backup database
echo "[1/3] Backing up database..."
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    DB_NAME=$(grep "DB_NAME" "${SITE_ROOT}/wp-config.php" | cut -d "'" -f 4)
    DB_USER=$(grep "DB_USER" "${SITE_ROOT}/wp-config.php" | cut -d "'" -f 4)
    DB_PASS=$(grep "DB_PASSWORD" "${SITE_ROOT}/wp-config.php" | cut -d "'" -f 4)
    DB_HOST=$(grep "DB_HOST" "${SITE_ROOT}/wp-config.php" | cut -d "'" -f 4)

    if [ -n "$DB_NAME" ] && [ "$DB_NAME" != "{{DB_NAME}}" ]; then
        mysqldump -h"${DB_HOST}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" | gzip > "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz"
        echo "  [OK] Database backed up: ${BACKUP_NAME}_db.sql.gz"
    else
        echo "  [SKIP] Database not configured, skipping"
    fi
else
    echo "  [SKIP] wp-config.php not found, skipping database backup"
fi

# 2. Backup wp-content
echo "[2/3] Backing up wp-content..."
tar -czf "${BACKUP_DIR}/${BACKUP_NAME}_wp-content.tar.gz" \
    -C "${SITE_ROOT}" wp-content \
    --exclude="wp-content/cache" \
    --exclude="wp-content/upgrade"
echo "  [OK] wp-content backed up: ${BACKUP_NAME}_wp-content.tar.gz"

# 3. Backup config files
echo "[3/3] Backing up config files..."
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    cp "${SITE_ROOT}/wp-config.php" "${BACKUP_DIR}/${BACKUP_NAME}_wp-config.php"
    echo "  [OK] wp-config.php backed up"
fi
if [ -f "${SITE_ROOT}/.htaccess" ]; then
    cp "${SITE_ROOT}/.htaccess" "${BACKUP_DIR}/${BACKUP_NAME}_htaccess"
    echo "  [OK] .htaccess backed up"
fi

# Clean old backups (keep last 30 days)
echo ""
echo "Cleaning backups older than 30 days..."
find "${BACKUP_DIR}" -name "wpbase_*" -mtime +30 -delete 2>/dev/null || true

echo ""
echo "=== Backup complete ==="
echo "Files saved to: ${BACKUP_DIR}"
ls -lh "${BACKUP_DIR}/${BACKUP_NAME}"* 2>/dev/null
