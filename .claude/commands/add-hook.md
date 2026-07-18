Help me add a WordPress action or filter hook.

Ask me:
1. What functionality do I want to add or modify?
2. Where should the code go? (mu-plugin, theme functions.php, custom plugin)

## Guidelines

### Finding the right hook:
- Identify the correct WordPress hook (action or filter) for the task
- Prefer specific hooks over generic ones (e.g., `save_post_{post_type}` over `save_post`)
- Use `init` sparingly - prefer more specific hooks like `wp_enqueue_scripts`, `admin_init`, etc.

### Code standards:
- Use named functions, not anonymous closures (easier to debug and unhook)
- Prefix all function names with project slug (e.g., `wpbase_`)
- Set appropriate priority (default 10) - explain when to change it
- Specify the number of accepted arguments
- Compatible with PHP 7.4+

### Common hooks reference:
**Actions:**
- `init` - WordPress initialization
- `wp_enqueue_scripts` - Frontend scripts/styles
- `admin_enqueue_scripts` - Admin scripts/styles
- `wp_head` / `wp_footer` - Head/footer output
- `save_post` - Post save
- `pre_get_posts` - Modify main query
- `admin_menu` - Admin menu items
- `widgets_init` - Register widgets/sidebars
- `rest_api_init` - Register REST routes

**Filters:**
- `the_content` - Post content
- `the_title` - Post title
- `body_class` - Body CSS classes
- `excerpt_length` / `excerpt_more` - Excerpt customization
- `upload_mimes` - Allowed upload types
- `login_redirect` - Login redirect URL
- `wp_mail` - Email modifications

## Output Format
1. Complete hook code with the callback function
2. Explanation of what the hook does and when it fires
3. File location recommendation
4. Any related hooks to be aware of
