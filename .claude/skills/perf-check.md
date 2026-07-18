Perform a performance review on this WordPress project. Analyze the following areas:

## 1. wp-config.php Performance Settings
- Check `WP_MEMORY_LIMIT` and `WP_MAX_MEMORY_LIMIT`
- Check `WP_POST_REVISIONS` (should be limited, not unlimited)
- Check `AUTOSAVE_INTERVAL`
- Check `WP_CACHE` setting

## 2. .htaccess / Server-level Optimization
- Check if GZIP compression is enabled in `config/.htaccess-sample`
- Check if browser caching (mod_expires) is configured
- Check if ETags are handled properly

## 3. Theme Performance
Scan custom themes in `wp-content/themes/` (excluding twenty* themes) for:
- Excessive database queries in templates (direct `$wpdb` calls in template files)
- Missing `wp_enqueue_script` / `wp_enqueue_style` (inline scripts/styles instead)
- Large unoptimized images referenced in CSS/templates
- Missing `loading="lazy"` on images
- Render-blocking script loading (missing `defer` or `async`)
- Excessive use of `get_posts()` or `WP_Query` without `'no_found_rows' => true`
- Missing `wp_reset_postdata()` after custom queries

## 4. Plugin Performance
Scan custom plugins in `wp-content/plugins/` for:
- Queries running on every page load without caching
- Missing transient caching for expensive operations
- Hooks running on `init` or `wp_loaded` that should be more specific
- Autoloaded options (`autoload = yes`) that shouldn't be

## 5. Asset Loading
- Check if scripts/styles are loaded only where needed (not globally)
- Check for jQuery dependency when vanilla JS would suffice
- Check for multiple versions of the same library

## Output Format
Rate each area: Good / Needs Improvement / Poor
Provide specific code-level recommendations with file paths and line numbers.
