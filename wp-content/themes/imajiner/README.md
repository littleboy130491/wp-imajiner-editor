# Imajiner parent and per-site child themes

The parent theme is shared agency code. The bundled `imajiner-child` is a reference,
not a client's content source. Keep its example files intact. Each real site should
use a uniquely named child theme with `Template: imajiner` in `style.css`.

## New site

With the editor plugin active, open **Appearance → Imajiner Site Setup**. Supply a
display name and a lowercase portable folder slug, such as `client-site`. Setup
copies only the parent theme's bundled `child-scaffold` allowlist and creates empty
`imajiner/css`, `imajiner/parts/css` and `assets/css` directories. It never copies
the active child theme, uploads, sample page, client tokens or database content.
Existing folders are refused. A failed write attempts to remove only files and
directories created during that request; inspect/remove a leftover folder before
retrying if the host also refused cleanup. Theme switching is a separate,
nonce-protected action with an explicit confirmation checkbox. Create on staging,
review the result, then confirm activation; setup never silently changes a live site.

For WP-CLI (administrator identity is required):

```sh
wp imajiner child-theme create client-site --name="Client Site" --user=administrator
wp theme get client-site
# Separate, explicit decision after review:
wp theme activate client-site
```

Creation respects `install_themes`, `edit_themes`, `DISALLOW_FILE_EDIT`, and the
WordPress file-modification policy. It uses `Imajiner_Filesystem` exclusively for
theme files. Hosts needing FTP/SSH must provision the filesystem service through
their normal WordPress credential workflow; setup does not collect/store secrets.
Multisite creation/activation is intentionally delegated to network deployment.
An interrupted request can leave an `imajiner_child_setup_<md5-of-full-path>` option;
an administrator may remove that lock only after verifying no setup is running.

When integrating the filesystem service, verify setup-specific path support: reads
of the bundled parent scaffold allowlist, creation inside the requested new child
folder, and deletion of newly created **empty** directories during rollback. A
service restricted to the active child theme cannot perform this workflow. Keep
any setup scope limited to that allowlist and validated destination for the current
request; do not globally broaden editor write access, fall back to direct writes,
or temporarily activate a theme to bypass path restrictions.

## Independent client repository (not a nested repository here)

Use a **separate checkout outside this demo/project repository**. Export only the
new client theme folder to an empty directory, then initialize it after checking
the contents. Do not copy `.git`, WordPress core, `wp-config.php`, uploads, provider
keys or credentials. Setup never runs git, creates a remote, or chooses a client URL.

```sh
# Run outside the project checkout. Use your actual source path and new directory.
mkdir client-site-theme
rsync -a --exclude=.git --exclude=.env --exclude=wp-config.php /path/to/site/wp-content/themes/client-site/ client-site-theme/
cd client-site-theme
git init
git add .
git commit -m "Initialize client child theme"
# Add an agency-approved remote separately, if wanted. No remote is hardcoded.
```

The **child repository root is the theme folder**, containing `style.css`,
`functions.php`, `theme.json`, `editor.css`, `screenshot.png`, `imajiner/`, and
`assets/`. Commit templates, scoped CSS, assets and accepted design tokens. Empty
directories are not tracked by git; they are recreated on demand by the editor.
The child relies on the separately deployed `imajiner` parent and editor plugin.

## Deployment package layout

Deploy the three packages independently; do not upload this repository as one theme:

```text
wp-content/plugins/imajiner-editor/     plugin package
wp-content/themes/imajiner/             shared parent package (including child-scaffold/)
wp-content/themes/client-site/          client child-repository contents
```

To export a reviewed child commit as a theme zip, from the independent repository:

```sh
git archive --format=zip --prefix=client-site/ HEAD > ../client-site.zip
```

Use **Appearance → Themes → Add New → Upload Theme** on staging, or your normal
deployment pipeline. Review diffs, back up the site/database, and preserve the
existing client theme when deploying parent/plugin updates. A child package has
no database, menu assignments or media-library files: migrate those through your
site workflow separately. Never initialize `.git` in the deployed/demonstration
theme directory or expose repository metadata through the web server.

## Design-system contract (works with the plugin off)

- Base tokens: `imajiner/assets/css/base.css` (`--imj-*`).
- Site overrides: active child `style.css`.
- Accepted extracted tokens: active child `assets/css/design-tokens.css`.
- `imajiner_design_tokens_file()` returns that fixed active-child token path.
- `imajiner_design_token_sources()` returns the three paths in cascade order;
  the `imajiner_design_token_sources` filter can augment **reader sources**, not
  change the persisted/enqueued token destination. Readers should skip missing files.
- `imajiner_design_tokens_enqueued($path, $handle)` fires after token enqueue.
- Front-end handle `imajiner-design-tokens` depends on `imajiner-child` (or base for
  a parent-only site), and is versioned by file modification time. Block-editor
  content receives base → child → tokens → parent editor styles → child editor styles.
- Theme JSON palette/font/spacing presets refer to tokens rather than duplicate
  values. Parent settings are inherited by client child themes. Customize a child's
  `editor.css` only for editor-specific additions.

Token extraction, validation, stale hashes/revisions and explicit AI acceptance
belong to the editor/design module. This theme only renders already-persisted CSS.

## Navigation

The default header provides progressive navigation with a mobile disclosure button,
submenu disclosures, visible keyboard focus, ArrowDown to enter a submenu and Escape
to close it (returning focus to its button). Escape from the mobile menu returns focus
to the main toggle. Tab follows ordinary document order; there is no focus trap.
Viewport changes move focus out of controls that become hidden, and closing a
parent submenu resets its nested disclosures.
Without JavaScript every navigation link/submenu remains visible, and the inert
mobile button stays hidden. Replacing the header through a located template part
still bypasses the default header entirely. Custom headers are not rewritten;
they may opt in with a `[data-imajiner-navigation]` nav with a unique ID and a matching
button `aria-controls`, following the default markup.
