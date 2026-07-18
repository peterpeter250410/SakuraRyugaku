Perform a security audit on this WordPress project. Check the following items and report findings:

## Audit Checklist

### 1. wp-config.php Security
- Check if `DISALLOW_FILE_EDIT` is set to `true`
- Check if `FORCE_SSL_ADMIN` is set to `true`
- Check if `WP_DEBUG` is `false` (production)
- Check if table prefix is NOT the default `wp_`
- Check if authentication keys/salts have been properly set (not placeholder values)
- Check if `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` are `false`

### 2. mu-plugins Security
- Read `wp-content/mu-plugins/security-hardening.php`
- Verify XML-RPC is disabled
- Verify user enumeration protection is in place (REST API + author archives)
- Verify security headers are being sent
- Verify login attempt limiting is working
- Verify WordPress version is hidden

### 3. .htaccess / Server Config
- Check if `config/.htaccess-sample` blocks access to `wp-config.php`, `xmlrpc.php`
- Check if directory listing is disabled
- Check if PHP execution in uploads is blocked

### 4. File Permissions (if on Linux)
- wp-config.php should be 600 or 400
- Directories should be 755
- Files should be 644

### 5. Code Vulnerabilities
- Scan any custom theme files in `wp-content/themes/` for:
  - Direct SQL queries without `$wpdb->prepare()`
  - Unescaped output (missing `esc_html()`, `esc_attr()`, `wp_kses()`)
  - Missing nonce verification in form handlers
  - Unsanitized `$_GET`, `$_POST`, `$_REQUEST` usage
- Scan any custom plugin files for the same issues

### 6. Sensitive File Exposure
- Check if `readme.html`, `license.txt`, `wp-config-sample.php` exist in root
- These should be deleted or blocked in production

## Output Format
Present findings as a table with columns: Item | Status (PASS/FAIL/WARN) | Details
Provide a summary with total issues and recommended fixes.
