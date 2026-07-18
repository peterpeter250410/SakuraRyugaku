Create a new custom WordPress plugin scaffold in `wp-content/plugins/`.

Ask me for the following information before creating the plugin:
1. Plugin name (e.g., "Contact Form", "Custom Post Types")
2. Plugin slug (e.g., "wpbase-contact-form")
3. Brief description of what the plugin should do

## Plugin Structure to Generate

```
wp-content/plugins/{plugin-slug}/
├── {plugin-slug}.php          # Main plugin file with header
├── includes/
│   └── class-{plugin-slug}.php  # Main plugin class
├── admin/
│   ├── class-{plugin-slug}-admin.php  # Admin-specific functionality
│   └── views/                  # Admin page templates
├── public/
│   └── class-{plugin-slug}-public.php # Frontend functionality
├── languages/
│   └── .gitkeep
└── README.md
```

## Coding Standards

Follow these WordPress coding standards:
- Use WordPress coding standards for PHP
- Prefix all functions, classes, and hooks with the plugin slug
- Use proper WordPress hooks (actions and filters)
- Use `$wpdb->prepare()` for ALL database queries
- Escape all output with `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- Validate and sanitize all input with `sanitize_text_field()`, `absint()`, etc.
- Use nonces for all form submissions
- Support internationalization with `__()` and `_e()`
- Compatible with PHP 7.4+

## Main Plugin File Header

```php
<?php
/**
 * Plugin Name: {Plugin Name}
 * Plugin URI:
 * Description: {Description}
 * Version: 1.0.0
 * Author: WordPressBase
 * License: GPL v2 or later
 * Text Domain: {plugin-slug}
 * Domain Path: /languages
 * Requires PHP: 7.4
 */
```

## Important
- After creating the plugin, remind me to run `git add -f wp-content/plugins/{plugin-slug}` since plugins are gitignored by default.
