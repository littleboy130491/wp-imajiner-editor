# AI workflow integration tests

Use PHP 8.1+, PHPUnit 9, and a **disposable WordPress 6.7+ site** with the Imajiner child theme and plugin active. The suite uses WordPress's real REST dispatcher, scanner, database, store and revision API. Provider HTTP responses are intercepted; no API credentials or paid requests are needed.

Run from the repository root:

```sh
IMAJINER_TEST_SITE=1 php -d mysqli.default_socket=/var/run/mysqld/mysqld.sock \
  /usr/bin/phpunit --bootstrap wp-content/plugins/imajiner-editor/tests/bootstrap.php \
  wp-content/plugins/imajiner-editor/tests/GenerationTest.php
```

Set `WP_TEST_ROOT` if WordPress core is installed elsewhere. The explicit `IMAJINER_TEST_SITE` flag prevents accidentally running the suite against an ordinary installation. Tests create uniquely named throwaway templates, pages, users and proposals, remove them afterwards, and restore AI settings. The reference `page-example.php` is never written.

These tests exercise generation, one corrective retry, confirmation, assignments, revision snapshots, stale PHP/CSS conflicts, permissions, expiry, user/theme ownership, invalid response handling and CSS scoping. They do not call real providers or drive the browser.

## Syntax and JavaScript lint

There is no build step or static type checker. PHP syntax checks use `php -l`; JavaScript syntax checks use `node --check`. ESLint 10.11.0 can check undefined names without requiring a project configuration:

```sh
find wp-content/plugins/imajiner-editor wp-content/themes/imajiner wp-content/themes/imajiner-child \
  -name '*.php' -print0 | xargs -0 -n1 php -l
find wp-content/plugins/imajiner-editor/assets/js -name '*.js' -print0 | xargs -0 -n1 node --check
eslint --no-config-lookup --rule 'no-undef:error' \
  --global window --global document --global Node --global fetch --global URL \
  --global setTimeout --global clearTimeout --global requestAnimationFrame \
  --global console --global HTMLElement --global getComputedStyle \
  wp-content/plugins/imajiner-editor/assets/js/{builder-ai,block-editor,editor}.js
```
