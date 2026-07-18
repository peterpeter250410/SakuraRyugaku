Review the specified WordPress code file(s) for quality, security, and best practices.

If no file is specified, ask which file or directory to review.

## Review Criteria

### Security (Critical)
- SQL injection: All queries using `$wpdb->prepare()`?
- XSS: All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`)?
- CSRF: Forms using nonces (`wp_nonce_field`, `wp_verify_nonce`)?
- Input validation: `$_GET`, `$_POST`, `$_REQUEST` sanitized?
- File uploads: Validated file types and permissions?
- Direct file access: `defined('ABSPATH')` check at top?
- Capability checks: `current_user_can()` before privileged operations?

### WordPress Best Practices
- Using WordPress API functions instead of raw PHP (e.g., `wp_remote_get` not `curl`)
- Proper hook usage (actions/filters in correct locations)
- Proper script/style enqueuing (not inline or hardcoded)
- Proper use of WordPress options API
- Text domain consistency for internationalization
- Following WordPress naming conventions

### PHP Quality
- Compatible with PHP 7.4+?
- No deprecated function usage?
- Proper error handling?
- Clean separation of concerns?
- No unnecessary global variables?

### Performance
- Unnecessary database queries?
- Missing caching opportunities?
- Queries inside loops?
- Loading assets globally when only needed on specific pages?

## Output Format
For each issue found:
- **File:Line** - Description of issue
- **Severity**: Critical / Warning / Info
- **Fix**: Specific code suggestion

End with a summary: total issues by severity and overall code quality rating.
