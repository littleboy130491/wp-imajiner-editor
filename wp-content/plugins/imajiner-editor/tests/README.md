# AI workflow integration tests

Use PHP 8.1+, PHPUnit 9, and a **disposable WordPress 6.7+ site** with the Imajiner child theme and plugin active. The suite uses WordPress's real REST dispatcher, scanner, database, store and revision API. Provider HTTP responses are intercepted; no API credentials or paid requests are needed.

Run from the repository root:

```sh
IMAJINER_TEST_SITE=1 php -d mysqli.default_socket=/var/run/mysqld/mysqld.sock \
  /usr/bin/phpunit --bootstrap wp-content/plugins/imajiner-editor/tests/bootstrap.php \
  wp-content/plugins/imajiner-editor/tests
```

Set `WP_TEST_ROOT` if WordPress core is installed elsewhere. The explicit `IMAJINER_TEST_SITE` flag prevents accidentally running the suite against an ordinary installation. Tests create uniquely named throwaway templates, pages, users and proposals, remove them afterwards, and restore AI settings. The reference `page-example.php` is never written.

These tests exercise generation, one corrective retry, confirmation, assignments, structural changes, ordered staging, revision snapshots, stale PHP/CSS conflicts, permissions, expiry, user/theme ownership, invalid response handling and CSS scoping. They do not call real providers or drive the browser.

## Syntax and JavaScript lint

There is no production build step or static type checker. PHP syntax checks use `php -l`; JavaScript syntax checks use `node --check`. ESLint 10.11.0 uses the repository-owned no-undef configuration:

```sh
find wp-content/plugins/imajiner-editor wp-content/themes/imajiner wp-content/themes/imajiner-child \
  -name '*.php' -print0 | xargs -0 -n1 php -l
find wp-content/plugins/imajiner-editor/assets wp-content/themes/imajiner/assets wp-content/plugins/imajiner-editor/tests \
  -path '*/node_modules' -prune -o -name '*.js' -print0 | xargs -0 -n1 node --check
eslint --config wp-content/plugins/imajiner-editor/tests/eslint.config.mjs \
  wp-content/plugins/imajiner-editor/assets/js/*.js wp-content/themes/imajiner/assets/js/*.js \
  wp-content/plugins/imajiner-editor/tests/*.js wp-content/plugins/imajiner-editor/tests/e2e/*.js
node --test wp-content/plugins/imajiner-editor/tests/ThemeNavigationTest.js \
  wp-content/plugins/imajiner-editor/tests/EditorRuntimeIntegrationTest.js
git diff --check
```

## Browser E2E harness (separate opt-in run)

The three real Playwright flows in `tests/e2e/editor.spec.js` cover manual create/open/edit/stage/undo/redo/save/revision restore, background AI creation with explicit acceptance, and design-system review/accept/persistence. They use actual WordPress UI/REST/storage, not snapshots or mocked browser fetches. A local MU-plugin intercepts provider HTTP server-side with deterministic validated responses; other external WordPress HTTP requests are denied while it is enabled. Every run owns timestamp-prefixed templates/pages and restores the prior AI settings/token file in teardown. Do not use a production site or real provider constants. Browser execution is delegated to the parent session and is not claimed here.

Prerequisites: disposable WordPress 6.7.4, active plugin + child theme, WP-CLI, Node 24 and local administrator credentials. Set these **only on that disposable site**:

```sh
wp config set WP_ENVIRONMENT_TYPE local
wp config set IMAJINER_E2E_TEST_SITE true --raw
# Serve from WP root with the single-process built-in server. Do NOT set
# PHP_CLI_SERVER_WORKERS for browser runs: worker mode leaves Chromium's
# keep-alive requests unread (every page.goto times out). The single
# process still executes signed background loopbacks — they queue and run
# as soon as the current request finishes.
php -S localhost:8097 -t .
```

In a separate shell, supply `IMAJINER_E2E_URL` (e.g. `http://localhost:8097`), `IMAJINER_E2E_WP_ROOT` (absolute WP root), `IMAJINER_E2E_USERNAME` and `IMAJINER_E2E_PASSWORD` from your local environment/secrets. Optional `IMAJINER_E2E_WP_CLI` is the WP-CLI executable. Credentials are never embedded in the repository. Then:

```sh
cd wp-content/plugins/imajiner-editor/tests/e2e
npm ci --ignore-scripts
npx playwright install --with-deps chromium
npm run test:list
npm test
# Optional visible run: npm run test:headed
```

`@playwright/test` is pinned to 1.55.1, published 2025-09-23 (verified via `npm view @playwright/test time --json`); its runtime is development-only and is not shipped to WordPress. The lockfile is committed. `npm ci` and test discovery were executed here; no browser was launched.

If a run is killed before teardown, get its run ID from `wp option get imajiner_e2e_fixture --format=json`, then run `wp eval-file wp-content/plugins/imajiner-editor/tests/e2e/harness.php cleanup e2e-<timestamp>`. The harness refuses an existing fixture, non-loopback site, non-local environment, theme mismatch or missing opt-in. Run serially on a dedicated site; it deliberately changes and then restores that site's token/settings state. Generated recordings, traces, reports and node_modules are gitignored.

## Translation catalog

Generate the real plugin + theme catalog from repository root with WP-CLI's gettext extractor (tests are excluded):

```sh
wp i18n make-pot . wp-content/plugins/imajiner-editor/languages/imajiner-editor.pot \
  --domain=imajiner-editor \
  --include=wp-content/plugins/imajiner-editor,wp-content/themes/imajiner,wp-content/themes/imajiner-child \
  --exclude=wp-content/plugins/imajiner-editor/tests --skip-audit
```

JavaScript uses `wp.i18n` and explicit `imajiner-editor` domains; handles depend on `wp-i18n` and call `wp_set_script_translations()`. To ship a language, place its PO/MO and generated JS JSON catalogs in `languages/` (`wp i18n make-json <po-directory> --no-purge`). POT is the source catalog, not an assertion that a language is translated.

## Optional real provider smoke calls

No live provider calls were run. After provisioning environment keys, explicitly opt in to this standalone runner from WP root; it lists models and makes one minimal chat per selected provider, and can incur charges:

```sh
IMAJINER_AI_SMOKE=1 IMAJINER_AI_SMOKE_PROVIDERS=openai,meta,anthropic \
  wp eval-file wp-content/plugins/imajiner-editor/tests/provider-smoke.php
```

Keys must already be environment variables `IMAJINER_OPENAI_API_KEY`, `IMAJINER_META_API_KEY`, `IMAJINER_ANTHROPIC_API_KEY`, etc. They are not printed or saved. Use `IMAJINER_<PROVIDER>_MODEL` for an explicit model, especially providers without a default. Select all supported IDs with `openrouter,openai,anthropic,gemini,xai,meta,mistral,deepseek,groq,custom`; custom also needs its HTTPS `IMAJINER_CUSTOM_BASE_URL`. Missing keys/models are reported as skipped. Without `IMAJINER_AI_SMOKE=1`, it fails closed. Settings Test/Load models remain available with their capability/nonce checks.

Meta uses the documented `https://api.meta.ai/v1/models` and `/chat/completions`, Bearer auth, and key link `https://dev.meta.ai/`. Claude defaults to documented `claude-opus-5`; explicit selections remain unchanged. Supported Claude 5/fable models receive `fallbacks: "default"` and `anthropic-beta: server-side-fallback-2026-07-01`; unknown explicit IDs omit beta settings. Mock regressions verify these contracts and refusal errors. Official references: [Meta models](https://dev.meta.ai/docs/api-reference/models/list-models), [authentication](https://dev.meta.ai/docs/authentication/), [Claude fallback](https://platform.claude.com/docs/en/build-with-claude/refusals-and-fallback), [Claude models](https://platform.claude.com/docs/en/about-claude/models/overview).

## Coverage boundaries

Integrated regressions run against the production filesystem adapter, including injected transport mapping/corruption/rollback failures, not an integration shim. The only existing-test adjustments are explicit `async: true` for the background-generation test, exact attachment-byte payload verification, and atomic-write commit fault injection; assertions were not removed or weakened. Legacy synchronous generation regression cases remain intact.

One legacy `DesignSystemTest` fault test is conditional on its isolated class-alias adapter and is skipped with the real integrated adapter; `IntegrationContractsTest` covers partial token-transfer corruption, original-byte rollback, retained revision, temp cleanup and successful retry through the production adapter using an injected remote transport. The transport is local-backed and is **not** evidence of a live FTP host. Accepted token replacement uses the shared write transaction rather than unsupported remote overwrite moves.

The dependency-free Node editor runtime tests execute the real `editor.js` in a small DOM/REST contract double: unsaved proposal staging, undo/redo, selection events, lock denial, stale id/hash/stage rejection and clean saved-state reload. Server-side scanner/store behavior is exercised separately in PHP. Actual HTTP smoke on local WP verified unauthenticated REST denial, synchronous 200, asynchronous 202 then signed-loopback completion (queue response 0.031s), explicit acceptance, instrumented template rendering, token acceptance/frontend/review enqueue and invalid dispatch signature 403. These shell tests are not browser/UX validation.

CI is defined in `.github/workflows/functional.yml`: PHP 7.4/8.1 lint and disposable WP PHPUnit, JS syntax/no-undef/navigation contracts, POT generation and E2E dependency/discovery checks. Both PHP matrix jobs passed on the code checkpoint recorded in root `TODO.md`; that checklist tracks unfinished acceptance checks. Browser interaction, real providers and live FTP/SSH require separate execution. Remote transports are not globally atomic across the pair of files; backup/verification/rollback is best effort and crash recovery can require an administrator. No independent external security audit is claimed.
