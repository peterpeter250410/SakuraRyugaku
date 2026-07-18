Help me write a WordPress database query or custom WP_Query.

Ask me what data I need to query, then generate the appropriate code following these rules:

## Rules

### For WP_Query / get_posts:
- Always use `WP_Query` class or `get_posts()` for post queries
- Always call `wp_reset_postdata()` after custom WP_Query loops
- Use `'no_found_rows' => true` when pagination is not needed
- Use `'update_post_meta_cache' => false` and `'update_post_term_cache' => false` when meta/terms are not needed
- Set reasonable `'posts_per_page'` limits

### For direct $wpdb queries:
- ALWAYS use `$wpdb->prepare()` for any query with user input
- Use `$wpdb->prefix` for table names, never hardcode prefix
- Use the appropriate method: `get_results()`, `get_var()`, `get_row()`, `get_col()`
- Use `insert()`, `update()`, `delete()` helper methods when possible

### For meta queries:
- Consider performance impact of meta_query
- Suggest creating custom tables for complex data structures
- Use `'meta_key'` + `'meta_value'` for simple single-key queries instead of `meta_query` array

### Caching:
- Suggest transient caching for expensive queries
- Show how to implement with `get_transient()` / `set_transient()`
- Include cache invalidation hooks

## Output Format
1. The complete query code, ready to use
2. Where to place it (functions.php, plugin file, template, etc.)
3. Performance notes if applicable
4. Security considerations

## Example prompt from user
"Query the latest 5 published posts from category 'news' with their featured images"
