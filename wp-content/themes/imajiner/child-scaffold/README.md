# Client child theme

This folder is a standalone theme package. The Imajiner parent loads `style.css`,
template styles and accepted `assets/css/design-tokens.css` when present, even if
the editor plugin is off. Add templates under `imajiner/` and CSS under `imajiner/css/`;
add parts under `imajiner/parts/` and their CSS under `imajiner/parts/css/`.
Empty folders may not survive a git archive; the editor recreates them when needed.

Keep this child's repository independent: export this folder to a new directory
**outside** the agency project/site tree, inspect it, then run `git init`, `git add .`
and `git commit -m "Initialize client child theme"`. Choose a client-approved remote
separately; never add credentials, `.git` from another repository, core, uploads,
`wp-config.php` or provider keys. Do not initialize git in the served theme folder.

From the independent repository, package a reviewed commit:

```sh
git archive --format=zip --prefix=YOUR-SITE-SLUG/ HEAD > ../child-theme.zip
```

Replace `YOUR-SITE-SLUG` with the installed folder slug. Deploy its contents to
`wp-content/themes/YOUR-SITE-SLUG/` separately from `wp-content/themes/imajiner/`
and `wp-content/plugins/imajiner-editor/`. Test on staging, back up, and review
activation explicitly. Parent/plugin deployments must not replace client files.
Menus, pages, media and assignments in the database require separate site migration.
See the parent theme README for setup permissions and the design-token hook API.
