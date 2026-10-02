# This site's Imajiner child theme

The `imajiner` parent theme and Imajiner Editor plugin are installed separately.
This child contains only this site's templates, parts, assets and token overrides.
`assets/css/design-tokens.css` is the persistent design system; it is loaded by
the parent on the front end and in the block editor, even with the plugin disabled.

Keep `Template: imajiner` in `style.css`. Add templates to `imajiner/`, page CSS
to `imajiner/css/`, parts to `imajiner/parts/` and part CSS to `imajiner/parts/css/`.
The scaffold intentionally includes no example page or another site's content.

## Independent version control

Copy this entire child directory to a workspace **outside** the agency/demo
repository. In that copy, run:

```sh
git init
git add style.css functions.php theme.json editor.css README.md screenshot.png assets imajiner
git commit -m "Initialize site child theme"
# Optional: add the private remote you created for this site.
git remote add origin YOUR_PRIVATE_REPOSITORY_URL
git push -u origin HEAD
```

No setup action runs git or creates an external repository for you. Do not add
a nested `.git` directory inside the agency/demo repository. Do not version
WordPress core, wp-config.php, database exports, uploads, or credentials.

## Package and deploy

From the repository's parent directory, create a ZIP with exactly one top-level
folder matching the child theme directory: `zip -r site-child.zip CHILD_DIRECTORY
-x '*/.git/*'`. Deploy that folder as `wp-content/themes/CHILD_DIRECTORY/`, with
`wp-content/themes/imajiner/` alongside it. Deploy the plugin separately as
`wp-content/plugins/imajiner-editor/`. Theme activation, content/database
migration and media deployment are separate, explicit operations. Back up the
existing child before updating it. The setup action never overwrites a theme.
