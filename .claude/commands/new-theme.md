Create a new custom WordPress theme scaffold in `wp-content/themes/`.

Ask me for the following information before creating the theme:
1. Theme name (e.g., "Corporate starter theme")
2. Theme slug (e.g., "wpbase-corporate")
3. Type of site (corporate, portfolio, blog, etc.)

## Theme Structure to Generate

```
wp-content/themes/{theme-slug}/
├── style.css                  # Theme header + base styles
├── functions.php              # Theme setup, enqueue scripts/styles, custom functions
├── index.php                  # Main template (required)
├── header.php                 # Site header
├── footer.php                 # Site footer
├── sidebar.php                # Sidebar
├── single.php                 # Single post template
├── page.php                   # Page template
├── archive.php                # Archive template
├── search.php                 # Search results
├── 404.php                    # 404 page
├── front-page.php             # Homepage template
├── template-parts/
│   ├── content.php            # Default content partial
│   ├── content-page.php       # Page content partial
│   └── content-none.php       # No results partial
├── assets/
│   ├── css/
│   │   └── main.css           # Custom styles
│   ├── js/
│   │   └── main.js            # Custom scripts
│   └── images/
│       └── .gitkeep
├── inc/
│   ├── customizer.php         # Theme customizer settings
│   └── template-functions.php # Template helper functions
└── screenshot.png             # Theme screenshot (placeholder)
```

## Coding Standards

- WordPress coding standards for PHP
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- Use WordPress template tags properly
- Support internationalization
- Compatible with PHP 7.4+
- Mobile-responsive base styles
- Properly enqueue all CSS/JS via `wp_enqueue_style()` / `wp_enqueue_script()`
- Register nav menus, widget areas, and theme support features in `functions.php`

## functions.php Must Include

- `after_setup_theme` hook with: title-tag, post-thumbnails, html5, custom-logo, menus
- Proper script/style enqueuing
- Navigation menu registration
- Widget area registration
- A clean, well-organized structure with includes

## style.css Header

```css
/*
Theme Name: {Theme Name}
Theme URI:
Author: WordPressBase
Description: {Description}
Version: 1.0.0
Requires PHP: 7.4
License: GNU General Public License v2 or later
Text Domain: {theme-slug}
*/
```
