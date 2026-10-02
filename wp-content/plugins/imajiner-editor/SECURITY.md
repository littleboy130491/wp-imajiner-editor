# File access and storage security

## Files in this unit

- `includes/class-imajiner-filesystem.php`: bounded direct/FTP/FTPS/SSH adapter, verified private staging and recovery.
- `includes/class-imajiner-filesystem-settings.php`: core credential form, encrypted expiring connection and opt-in history retention.
- `includes/class-imajiner-template-store.php`: paired transactions, revisions, stale hashes, rename/delete and design CSS writes.
- `uninstall.php`: bounded optional history/cache cleanup, credential/settings removal.
- `tests/StorageSecurityTest.php`: isolated storage, permissions, credential, transport failure and uninstall regressions.
- `SECURITY.md`: deployment prerequisites, integration instructions and review findings.

## Integration

- Include `includes/class-imajiner-filesystem.php` before the template store. Include `includes/class-imajiner-filesystem-settings.php` after the secrets class and call `Imajiner_Filesystem_Settings::init()` once. No scripts, enqueue dependencies or localization objects are required by these classes.
- `Imajiner_Filesystem::init()` connects lazily and returns `true|WP_Error`. The common `read`, `write`, `mkdir`, `exists`, `delete`, `move` API is static. Paths are absolute local paths inside the active child theme; remote paths are resolved with WordPress `find_folder()`. `exists()` returns false on errors by contract; callers must use `init()` and `check_path()` before interpreting false as absence. `validate_path()` checks local containment without connecting. `reset()` discards the request's cached connection.
- Missing credentials and connection errors include `data.settings_url`. Display a link to that URL beside REST errors so editors can open **Settings → Imajiner File Access**, connect, and retry. A configured FTP/SSH method never falls back to direct file access.
- Settings access requires `manage_options` and `edit_themes`; core verifies the filesystem form nonce, and the plugin additionally verifies its own connection nonce. Retention changes have a separate nonce. Credentials are encrypted with `Imajiner_Secrets` in a non-autoloaded ten-minute transient, scoped to user, login session and child theme, removed on logout/uninstall. WordPress's credential form may retain hostname/username in its own core option, but not passwords or key contents. No credential values or backend errors are returned to REST or sent to AI.
- Store `delete($path, $hash)` and `rename($path, $new_path, $hash)` preserve a private PHP/CSS revision and recover changed files on failure. Rename preserves the original source; management must update CSS scope, page assignments, header metadata and caller-facing references as appropriate. Database updates should happen only after file success. Revisions stay under the original key.
- Store `write_css($path, $base_hash, $css)` saves a child-theme CSS file with a private CSS revision. Its hash is `md5($current_css)` (missing file is `md5('')`). Use it for explicitly accepted design tokens. CSS contract/safety validation belongs to the design module; this helper restricts the destination and provides version/conflict/recovery checks. CSS revisions use the CSS file's theme-relative key; `get_revision_files()` returns an empty PHP string plus the old CSS.
- Store mutation methods enforce `edit_themes` and `DISALLOW_FILE_EDIT` independently of REST. The REST routes still need cookie authentication, REST nonces, and permission callbacks. Unprivileged callers cannot read revision content.
- Supplemental `get_permissions($path)` returns `int|WP_Error` and `set_permissions($path, $mode)` returns `true|WP_Error`; the common API is unchanged. The store uses these to retain permissions across rename and recovery of deleted files. Permission changes are limited to regular child-theme files and modes up to `0777`.

## Staging and remote methods

Staging source must not be accessible over HTTP. Direct access uses WordPress's temporary directory **outside** the installation, theme root and uploads. Hosts whose `WP_TEMP_DIR` points inside the public site must configure `IMAJINER_FILESYSTEM_TEMP_DIR` to an existing private directory. The host must ensure this directory is outside its actual web document root, including aliases; PHP cannot infer web-server aliases.

FTP/FTPS/SSH writes require `IMAJINER_FILESYSTEM_TEMP_DIR` set to the local absolute path of a private directory that is also accessible through the configured remote account. WordPress maps that directory through `find_folder()`. Do not use a served directory, a symbolic link, or a filesystem root. If mapping/access/permission preservation cannot be verified, saving fails with an actionable settings URL. Staged files have random names and are removed after each attempt.

The direct backend uses a strict `rename()` implementation inside a `WP_Filesystem_Direct` subclass. It does not invoke WordPress's destructive overwrite/delete or copy fallback. Put the private staging directory on the same filesystem as the child theme; cross-device rename failures are rejected and recovered. Individual direct replacements are atomic where the OS supports rename replacement. A PHP/CSS pair is **not** one atomic operation: the stylesheet is promoted first and a later failure triggers recovery.

WordPress FTP/SSH implementations can copy or delete the destination before moving. They provide no universal atomicity or crash durability. The adapter verifies staging and final bytes and attempts to restore the in-memory snapshot when a move fails. The store snapshots the whole operation and keeps a private database revision before editing. If the remote server disconnects during both writing and recovery, the error is `imajiner_rollback_failed`; reconnect and restore the saved revision. No implementation can promise recovery during an unavailable transport. New templates have no prior content to restore.

Traversal, stream wrappers, control characters, parent/sibling paths, and local symlinks (including broken links and directory ancestors) are rejected. FTP listing symlinks are rejected; SSH additionally uses SFTP `lstat`, because WordPress's SSH directory listing follows links. Administrators controlling the remote server/web root remain trusted. Concurrent filesystem writers outside the plugin can race path checks or stale hashes; the integration's editing locks reduce this risk, but are not OS-level isolation.

## Uninstall

History/cache removal is off by default. The opt-in checkbox only removes `imajiner_revision` posts and recognized instrumented cache filenames directly inside the exact uploads `imajiner/preview` directory. It skips symlinks, nested directories, unknown files, failed connections and unverified paths. Cache access guards (`index.php` and `.htaccess`) remain to protect any skipped files. Client theme PHP/CSS files are never removed. If remote credentials are unavailable during uninstall, cache files remain for an administrator to remove. Database filesystem transients, the current connection and AI settings are removed. Credentials held exclusively by an external object cache expire within ten minutes; WordPress provides no enumeration of those transient keys. This script acts on the current WordPress site; multisite-wide removal requires integration to iterate sites deliberately.

## Review findings outside this unit

- REST permission callbacks use `edit_themes`, which WordPress denies under `DISALLOW_FILE_EDIT`; retain that check on every new AI/job/design route. Store boundaries add defense in depth.
- Preview cache currently writes executable PHP into uploads with an `ABSPATH` guard and an Apache `.htaccess` only when the folder is first created. The guard prevents direct standalone execution, but the web server must deny HTTP access to this directory (including Nginx and already-existing folders). Preview file lookup/write/delete should reject directory/file symlinks, validate source through the bounded store, and use the filesystem adapter with a separately bounded cache API. These changes belong to the preview owner/integration, not this unit.
- Existing editor/template discovery reads local files directly. Integration must reject symlinked paths before previewing or handing source to AI; store rejection alone does not protect those earlier reads.
- `Imajiner_Secrets` authenticates ciphertext and derives its key from configuration/salts. It requires Sodium: existing AI settings paths should show a translated error when encryption support is missing rather than invoking missing functions. The new credential UI refuses to retain credentials without Sodium.

## Regression tests

Use disposable WordPress 6.7.4 core, a disposable database, active Imajiner child theme and plugin. Include the adapter/settings classes as above (an ignored local MU loader is sufficient while integration owns bootstrap).

```sh
IMAJINER_TEST_SITE=1 php -d mysqli.default_socket=/var/run/mysqld/mysqld.sock \
  /usr/bin/phpunit --bootstrap wp-content/plugins/imajiner-editor/tests/bootstrap.php \
  wp-content/plugins/imajiner-editor/tests/StorageSecurityTest.php
```

Existing `GenerationTest::setUpBeforeClass()` creates fixtures before setting a user. A disposable test bootstrap must set an administrator after loading WordPress to exercise the newly enforced store permission boundary. Do not remove this boundary or weaken the existing tests.

The suite uses generated fixture names and controlled remote doubles; it never touches the reference template, uses production examples, or calls an AI provider. Real external FTP/SSH hosts and real providers require separate provisioning and are not claimed as tested.

Validated locally with WordPress 6.7.4 and PHP 8.1.2: storage 24 tests/171 assertions, generation 44 tests/99 assertions, structure 28 tests/182 assertions. PHP lint across the plugin/themes, JavaScript syntax checks and the documented ESLint `no-undef` command pass. PHP 7.4 syntax compatibility was reviewed; a PHP 7.4 runtime and real external FTP/SSH servers were not exercised.
