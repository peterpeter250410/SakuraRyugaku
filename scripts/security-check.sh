#!/bin/bash
# WordPressBase Security Audit Script
# Usage: bash security-check.sh

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ISSUES=0
WARNINGS=0

echo "=== WordPressBase Security Audit ==="
echo "Date: $(date)"
echo "Root: ${SITE_ROOT}"
echo "=================================="

# 1. wp-config.php permissions
echo ""
echo "[Check 1] wp-config.php file permissions"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    PERMS=$(stat -c %a "${SITE_ROOT}/wp-config.php" 2>/dev/null || stat -f %Lp "${SITE_ROOT}/wp-config.php" 2>/dev/null || echo "unknown")
    if [ "$PERMS" = "unknown" ]; then
        echo "  [SKIP] Cannot read permissions on this OS"
    elif [ "$PERMS" != "600" ] && [ "$PERMS" != "400" ]; then
        echo "  [FAIL] wp-config.php permission is ${PERMS}, should be 600"
        ISSUES=$((ISSUES + 1))
    else
        echo "  [PASS] Permission: ${PERMS}"
    fi
else
    echo "  [WARN] wp-config.php not found (not deployed yet?)"
    WARNINGS=$((WARNINGS + 1))
fi

# 2. Table prefix
echo ""
echo "[Check 2] Database table prefix"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    PREFIX=$(grep "table_prefix" "${SITE_ROOT}/wp-config.php" | grep -o "'[^']*'" | head -1 | tr -d "'")
    if [ "$PREFIX" = "wp_" ]; then
        echo "  [FAIL] Using default table prefix wp_, should change to custom prefix"
        ISSUES=$((ISSUES + 1))
    else
        echo "  [PASS] Table prefix: ${PREFIX}"
    fi
fi

# 3. Debug mode
echo ""
echo "[Check 3] Debug mode"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    if grep -q "WP_DEBUG.*true" "${SITE_ROOT}/wp-config.php"; then
        echo "  [FAIL] WP_DEBUG is enabled (should be off in production)"
        ISSUES=$((ISSUES + 1))
    else
        echo "  [PASS] WP_DEBUG is off"
    fi
fi

# 4. File editor
echo ""
echo "[Check 4] Backend file editor"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    if grep -q "DISALLOW_FILE_EDIT.*true" "${SITE_ROOT}/wp-config.php"; then
        echo "  [PASS] File editor disabled"
    else
        echo "  [FAIL] File editor not disabled, set DISALLOW_FILE_EDIT to true"
        ISSUES=$((ISSUES + 1))
    fi
fi

# 5. Secret keys
echo ""
echo "[Check 5] Authentication keys"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    if grep -q "{{GENERATE_NEW}}" "${SITE_ROOT}/wp-config.php"; then
        echo "  [FAIL] Keys not replaced, get from https://api.wordpress.org/secret-key/1.1/salt/"
        ISSUES=$((ISSUES + 1))
    else
        echo "  [PASS] Keys configured"
    fi
fi

# 6. Sensitive files
echo ""
echo "[Check 6] Sensitive file exposure"
for FILE in readme.html license.txt wp-config-sample.php; do
    if [ -f "${SITE_ROOT}/${FILE}" ]; then
        echo "  [WARN] ${FILE} exists in root, consider removing or blocking via .htaccess"
        WARNINGS=$((WARNINGS + 1))
    else
        echo "  [PASS] ${FILE} not found"
    fi
done

# 7. PHP files in uploads
echo ""
echo "[Check 7] PHP files in uploads directory"
if [ -d "${SITE_ROOT}/wp-content/uploads" ]; then
    PHP_COUNT=$(find "${SITE_ROOT}/wp-content/uploads" -name "*.php" 2>/dev/null | wc -l)
    if [ "$PHP_COUNT" -gt 0 ]; then
        echo "  [FAIL] Found ${PHP_COUNT} PHP file(s) in uploads (possible malware)"
        find "${SITE_ROOT}/wp-content/uploads" -name "*.php" 2>/dev/null
        ISSUES=$((ISSUES + 1))
    else
        echo "  [PASS] No PHP files in uploads"
    fi
else
    echo "  [PASS] uploads directory not found"
fi

# 8. .htaccess
echo ""
echo "[Check 8] .htaccess security config"
if [ -f "${SITE_ROOT}/.htaccess" ]; then
    if grep -q "Options -Indexes" "${SITE_ROOT}/.htaccess"; then
        echo "  [PASS] Directory listing disabled"
    else
        echo "  [FAIL] Directory listing not disabled"
        ISSUES=$((ISSUES + 1))
    fi
else
    echo "  [WARN] .htaccess not found"
    WARNINGS=$((WARNINGS + 1))
fi

# 9. mu-plugins
echo ""
echo "[Check 9] Security hardening plugin"
if [ -f "${SITE_ROOT}/wp-content/mu-plugins/security-hardening.php" ]; then
    echo "  [PASS] security-hardening.php deployed"
else
    echo "  [FAIL] security-hardening.php not deployed"
    ISSUES=$((ISSUES + 1))
fi

# 10. SSL config
echo ""
echo "[Check 10] SSL configuration"
if [ -f "${SITE_ROOT}/wp-config.php" ]; then
    if grep -q "FORCE_SSL_ADMIN.*true" "${SITE_ROOT}/wp-config.php"; then
        echo "  [PASS] Force SSL admin enabled"
    else
        echo "  [WARN] Force SSL admin not enabled"
        WARNINGS=$((WARNINGS + 1))
    fi
fi

# Summary
echo ""
echo "=================================="
echo "Audit complete"
echo "  Issues: ${ISSUES}"
echo "  Warnings: ${WARNINGS}"
if [ "$ISSUES" -gt 0 ]; then
    echo "  Status: SECURITY ISSUES FOUND - please fix"
    exit 1
else
    echo "  Status: PASSED"
    exit 0
fi
