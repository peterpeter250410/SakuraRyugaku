#!/bin/bash
# WordPressBase deploy script
# Usage: bash deploy.sh [wordpress_version]
# Example: bash deploy.sh 6.5.5
# Without args, downloads latest: bash deploy.sh

set -e

WP_VERSION="${1:-latest}"
SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== WordPressBase Deploy ==="
echo "Site root: ${SITE_ROOT}"
echo "WordPress version: ${WP_VERSION}"
echo ""

# 1. Download WordPress core
echo "[1/5] Downloading WordPress core..."
cd /tmp
if [ "$WP_VERSION" = "latest" ]; then
    curl -O https://wordpress.org/latest.tar.gz
    TAR_FILE="latest.tar.gz"
else
    curl -O "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
    TAR_FILE="wordpress-${WP_VERSION}.tar.gz"
fi
tar -xzf "$TAR_FILE"

# 2. Sync core files (preserve wp-content and wp-config.php)
echo "[2/5] Syncing core files..."
rm -rf /tmp/wordpress/wp-content
rm -f /tmp/wordpress/wp-config-sample.php
cp -rf /tmp/wordpress/* "${SITE_ROOT}/"

# 3. Check wp-config.php
echo "[3/5] Checking config..."
if [ ! -f "${SITE_ROOT}/wp-config.php" ]; then
    echo "  [WARN] wp-config.php not found!"
    echo "  Run: cp ${SITE_ROOT}/config/wp-config-sample.php ${SITE_ROOT}/wp-config.php"
    echo "  Then edit wp-config.php with your database info and secret keys"
else
    echo "  [OK] wp-config.php exists"
fi

# 4. Set file permissions (Linux only)
echo "[4/5] Setting permissions..."
IS_WINDOWS=false
UNAME_OUT="$(uname -s)"
case "$UNAME_OUT" in
    MINGW*|MSYS*|CYGWIN*) IS_WINDOWS=true ;;
esac

if [ "$IS_WINDOWS" = true ]; then
    echo "  [SKIP] Windows detected, skipping permissions"
else
    chmod 644 "${SITE_ROOT}"/*.php 2>/dev/null || true
    chmod 755 "${SITE_ROOT}/wp-admin" "${SITE_ROOT}/wp-includes" 2>/dev/null || true
    chmod 600 "${SITE_ROOT}/wp-config.php" 2>/dev/null || true
    chmod 644 "${SITE_ROOT}/.htaccess" 2>/dev/null || true
    chown -R www:www "${SITE_ROOT}/wp-content/uploads" 2>/dev/null || true
    echo "  [OK] Permissions set"
fi

# 5. Cleanup
echo "[5/5] Cleaning up..."
rm -rf /tmp/wordpress /tmp/"$TAR_FILE"

echo ""
echo "=== Deploy complete ==="
echo ""
echo "Next steps:"
echo "  1. Configure wp-config.php (database, secret keys, table prefix)"
echo "  2. Copy .htaccess: cp ${SITE_ROOT}/config/.htaccess-sample ${SITE_ROOT}/.htaccess"
echo "  3. Create database"
echo "  4. Visit site to run WordPress install wizard"
echo "  5. Security check: bash ${SITE_ROOT}/scripts/security-check.sh"
