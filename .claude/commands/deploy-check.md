Perform a pre-deployment checklist for this WordPress project. Verify everything is ready for production.

## Checklist

### 1. Configuration
- [ ] `wp-config.php` exists and is properly configured (check `config/wp-config-sample.php` for reference)
- [ ] `WP_DEBUG` is `false`
- [ ] `WP_DEBUG_LOG` is `false`
- [ ] `WP_DEBUG_DISPLAY` is `false`
- [ ] `SCRIPT_DEBUG` is `false`
- [ ] `DISALLOW_FILE_EDIT` is `true`
- [ ] `FORCE_SSL_ADMIN` is `true`
- [ ] Authentication keys/salts are unique (not placeholders)
- [ ] Table prefix is NOT `wp_`

### 2. Security
- [ ] `wp-content/mu-plugins/security-hardening.php` is present
- [ ] `.htaccess` is in place with security rules (compare with `config/.htaccess-sample`)
- [ ] No PHP files in `wp-content/uploads/`
- [ ] `readme.html` and `license.txt` should be deleted or blocked
- [ ] `wp-config-sample.php` in root should be deleted

### 3. Git Status
- [ ] No uncommitted changes
- [ ] No sensitive files tracked (wp-config.php, .env, *.sql)
- [ ] `.gitignore` is properly configured

### 4. Code Quality
- [ ] No `var_dump()`, `print_r()`, `error_log()` debug statements in custom code
- [ ] No hardcoded URLs (should use `home_url()`, `get_template_directory_uri()`)
- [ ] No hardcoded database credentials outside wp-config.php

### 5. Assets
- [ ] All custom CSS/JS files are properly enqueued
- [ ] No console.log() statements in JavaScript files

### 6. WordPress Core
- [ ] WordPress version is up to date
- [ ] Check `wp-includes/version.php` for current version

## Output Format
Present as a checklist with PASS/FAIL/WARN status for each item.
Provide a final GO / NO-GO recommendation.
