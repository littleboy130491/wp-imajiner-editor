# Theme and per-site setup

Install `imajiner` as the shared parent, the Imajiner Editor plugin separately,
and one child theme per client. Appearance → Imajiner Site Setup creates an
empty child after an explicit form submission. It never activates a theme,
overwrites an existing directory, copies the sample child, or runs git.
Click the separate activation confirmation only when the site is ready.

WP-CLI (after the setup class is wired into the plugin) provides the same flow:

```sh
wp imajiner child create client-slug --name="Client theme" --user=ADMIN_LOGIN
# Separate, intentional activation:
wp theme activate imajiner-client-slug --user=ADMIN_LOGIN
```

Requires `install_themes` and `edit_themes`; respects file modification policies.
Multisite provisioning is intentionally refused because network theme enablement
needs a separate workflow. The filesystem service must be initialized with an
available WordPress filesystem transport; setup surfaces its errors. No FTP/SSH
transport was validated as part of this theme work.
If a scaffold write fails, setup removes the files it attempted to create but
leaves directories for inspection. It never recursively deletes a directory
that another process might have populated; remove an incomplete directory
manually before retrying that slug.

## Deployment and git

See `child-scaffold/README.md` for independent child repository initialization and
ZIP packaging. Copy the generated child to a workspace outside this repository
before running `git init`. This project is already tracked in git; no nested
repository, client remote, or external repository is created by setup.

Deployment layout:

```
wp-content/plugins/imajiner-editor/    agency plugin release
wp-content/themes/imajiner/           agency parent release (includes child-scaffold/)
wp-content/themes/imajiner-SITE/       independent site's child release
```

The existing `imajiner-child` demo remains a reference implementation. Setup
copies only `child-scaffold/{functions.php,editor.css,README.md}`, the parent's
`theme.json` and PNG, and generates a fresh style header, empty template folders
and empty token overrides. Never package another site's PHP/content into a new
client scaffold.

## Design-system integration

- Write accepted tokens to the active child `assets/css/design-tokens.css`.
- `imajiner_design_token_file()` returns that canonical destination.
- `imajiner_design_token_sources()` returns parent base CSS, child style CSS and
  persistent tokens. Filter `imajiner_design_token_sources` can add sources for
  extraction; it does not change the destination or load additional styles.
- The parent enqueues tokens after base and child CSS, versioned by file mtime.
  Block-editor styles import the same versioned file after theme styles.
- Action `imajiner_design_tokens_loaded($absolute_path, $context)` fires for
  `front` or `editor` loading. Persistent styles do not depend on the plugin.
- `theme.json` presets use `var(--imj-*)`, so accepted overrides update the
  front end and editor. Per-site editor adjustments belong in `editor.css`.

The default header alone opts into navigation enhancement with
`data-imajiner-navigation`. Custom header parts retain their markup and behavior;
they can explicitly opt in with a `.site-nav__toggle` whose `aria-controls`
targets their menu. JavaScript requires WordPress's `wp-i18n`; without JavaScript
the menu stays visible, and mobile submenus remain accessible. The theme PNGs
are representative theme previews, not client-site screenshots.

## Plugin integration

Include `includes/class-imajiner-site-setup.php` after the filesystem service and
call `Imajiner_Site_Setup::init()` once during plugin loading. It registers the
Appearance page, nonce-checked admin action and optional WP-CLI command. No
plugin-side script enqueue/localization is required. The theme owns its new
navigation enqueue (`wp-i18n`) and `wp_set_script_translations()` registration.
Integration should provide plugin/theme translation catalogs as usual.

## Regression tests

On a disposable WordPress 6.7.4 installation with the demo child/plugin active
and the shared filesystem class loaded:

```sh
IMAJINER_TEST_SITE=1 php -d mysqli.default_socket=/var/run/mysqld/mysqld.sock \
  /usr/bin/phpunit --bootstrap wp-content/plugins/imajiner-editor/tests/bootstrap.php \
  wp-content/plugins/imajiner-editor/tests/ThemeSetupTest.php
node --test wp-content/plugins/imajiner-editor/tests/ThemeNavigationTest.js
```

The navigation tests use Node's built-in test runner and a small DOM fixture;
they require no dependency install or build. They check interaction state and
focus behavior, not browser rendering. The PHP suite creates throwaway themes,
users and menus and preserves the reference example. Re-run with the integrated
filesystem service: the theme unit's initial local run used an ignored adapter
backed by WordPress's direct WP_Filesystem transport while that unit was pending.
