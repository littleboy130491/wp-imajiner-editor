# Imajiner Editor: to do

Implementation and validation checklist, grouped by area. **P1** = needed for the agency to use it day to day, **P2** = important, **P3** = nice to have.
What's already done is listed under "Status" in `AGENTS.md`.

## Checkpoint and how to resume

The six feature branches are integrated and committed. Checked feature items mean implementation plus automated coverage; they do not mean browser acceptance or live provider/FTP/SSH verification. This is a saved work checkpoint, not a claim that the full Todo is finished. The unfinished checks below must stay open until their evidence is recorded.

Resume with the full WordPress PHPUnit command in `wp-content/plugins/imajiner-editor/tests/README.md`, then inspect the existing PR's PHP 7.4/8.1 CI results. Use the disposable-site Playwright harness for the three automated flows; also exercise the additional interactions listed below. Record the tested commit, commands, failures and any remaining gaps before changing checkboxes.

## AI features

- [x] **P1** Create a template from a prompt
  - "New page with AI" entry point (Pages screen and/or editor)
  - The model returns PHP + CSS; save them as `imajiner/<slug>.php` and `imajiner/css/<slug>.css` in the child theme, then assign the template to the page
  - Before saving: PHP syntax check, scanner contract check (no warnings), scoped CSS check. On failure, send the errors back to the model for one retry
  - Ask for a structured response (e.g. JSON with `slug`, `name`, `php`, `css`) instead of free text
- [x] **P1** Normalize: rewrite an existing PHP template so it follows the contract
  - Show a before/after preview and the contract warnings that remain, save only after confirmation, keep a revision
- [x] **P2** "Edit with AI" on a selected section or element (e.g. "make this a 3-column grid"), returning only the changed section
- [x] **P2** Long requests: generation can exceed the 120 s HTTP timeout and PHP's `max_execution_time`. Use streaming or a background job with progress in the UI
- [ ] **P2** Test every provider with a real key: OpenRouter, OpenAI, Claude, Gemini, xAI, Meta (documented `api.meta.ai/v1`/Bearer/models contracts verified in mocks; real account calls pending), Mistral, DeepSeek, Groq
- [ ] **P3** Complete authenticated verification of every "Get a key" destination. Claude and Meta use documented live portals; OpenRouter/Claude/Gemini/Meta/Groq public checks pass. OpenAI/xAI/DeepSeek return 403 to automation; Mistral redirects loop in this environment
- [x] **P3** Log AI usage per request (provider, model, tokens, which model answered after a fallback)

## Editor

- [x] **P1** Add, delete, duplicate and reorder elements and sections (drag and drop in the layers tree and preview)
- [x] **P1** Add a new section from a library of ready-made, contract-following sections (hero, features, CTA, …)
- [x] **P1** Undo / redo for unsaved changes
- [x] **P2** Edit text directly in the preview (inline editing) instead of only in the properties panel
- [x] **P2** Change the source of a dynamic value (e.g. swap `the_title()` for a custom field)
- [x] **P2** Code view for locked PHP blocks
- [x] **P2** Hover/focus styles (`:hover`, `:focus-visible`) in the Style tab
- [x] **P2** Mixed-content text (text next to `<strong>`, `<a>`, …) updates the preview live, not only after saving
- [x] "New blank template" without AI (Appearance → Imajiner Templates)
- [x] **P3** Show a diff in History before restoring
- [x] Template parts (header, footer, before footer, …) and single/archive templates with detected post types and taxonomies
- [x] **P2** Delete or rename templates and parts from Appearance → Imajiner Templates (safe child-only paths, paired CSS/revisions/assignments)
- [x] **P2** Display conditions for parts (e.g. "before footer" only on pages, or not on the home page); portable include/exclude/post-type headers
- [x] **P2** Test locations with a real custom post type and taxonomy (only post, page, category and tag exist on the test site)
- [x] **P3** Term-specific templates (e.g. one category) and front-page / blog-page locations
- [x] **P3** Load part CSS only on pages that render the part (including late explicit calls)
- [x] **P3** Style rules the Style tab skips today: `min-width` / other `@media` conditions, compound selectors (`.a, .b`)
- [x] **P3** Editing lock when two people open the same template (user/session-owned, renewed and enforced before staging/saving)
- [x] **P3** Breakpoint settings in the UI (filter remains supported)
- [x] **P3** Removing a `class` attribute leaves a stray space (`<p >`); harmless, comes from WordPress's HTML API
- [x] **P3** Keyboard navigation in the layers tree

## Theme

- [x] **P1** Mobile navigation: the header menu has no hamburger/toggle on small screens
- [x] **P2** Create the child theme automatically for a new client site (today it is copied by hand)
- [x] **P3** `theme.json` / editor styles so the block editor matches the front end
- [x] **P3** `screenshot.png` for both themes

## Quality and project

- [x] **P1** Central plugin/parent repository plus documented separate child-per-client repository/deploy workflow; no unspecified external client repository created
- [x] **P1** Repository PHPUnit for scanner/CSS/store/AI and cross-module contracts; dependency-free Node runtime/navigation tests; pinned runnable Playwright end-to-end harness with server-side deterministic provider (browser execution pending below)
- [x] **P2** Integration manual/regression security review of child/preview path boundaries, REST capabilities/nonces, key storage, job/proposal ownership, SSRF/image validation and rollback; not an independent external audit
- [x] **P2** `WP_Filesystem` adapter and encrypted expiring credential UI; direct and injected remote mapping/verification/rollback regression tests (live FTP/SSH host execution pending below)
- [x] **P2** Translations: the editor's JavaScript strings are hardcoded in English; generate a `.pot` file
- [x] **P3** Uninstall: also offer to remove the preview cache (`uploads/imajiner/preview/`) and template revisions

## AI design system (added scope)

- [x] Prompt, owned local screenshot bytes and public HTTPS-reference extraction using provider-native image payloads
- [x] Nonblocking owned/hash-bound jobs, progress/cancel/expiry; legacy synchronous REST compatibility
- [x] Validated CSS-token proposal, before/after review, explicit acceptance, child-only persistence, stale hashes and private revision restore
- [x] Persisted tokens reload into later prompts, editor suggestions, frontend/block editor and both static AI review iframe stylesheet contexts
- [x] SSRF/private host/symlink/MIME/size/dimension/capability tests against integrated production modules; no real provider calls

## Validation and external follow-up

- [x] WordPress 6.7.4/PHP 8.1 integrated PHPUnit and production-adapter regressions; PHP syntax, JS syntax/no-undef, whitespace, Node editor/navigation contracts
- [x] Actual local authenticated HTTP routes: sync generation; async signed-loopback completion; explicit acceptance; instrumented template render; design acceptance and token enqueue; unauthenticated/invalid-signature denial
- [x] Real WP-CLI POT extraction and reproducible translation/provider/E2E commands in plugin tests README
- [x] Playwright dependency install, three-test discovery and local fixture/provider setup/cleanup; no browser execution in this integration
- [x] Rerun the complete integrated WordPress suite in the parent checkout: passed — 224 tests / 1,813 assertions / 1 skip (`DesignSystemTest::test_partial_transport_failure_rolls_back_and_retains_revision`, needs the `Imajiner_Design_Test_Filesystem` fault-injection adapter) on WordPress 6.7.4 / PHP 8.1 via `IMAJINER_TEST_SITE=1 php -d mysqli.default_socket=/var/run/mysqld/mysqld.sock /usr/bin/phpunit --bootstrap wp-content/plugins/imajiner-editor/tests/bootstrap.php wp-content/plugins/imajiner-editor/tests`, including the six post-integration branch fixes (checkpoint `480fd4e`, PR #2)
- [ ] Execute the three Playwright browser flows: manual editing/history restore, background AI creation/review/acceptance, and design-system review/acceptance/persistence
- [ ] Additional browser acceptance: Normalize; selected-section AI review/cancel; screenshot and HTTPS-reference inputs; token revision restore and reuse after a fresh editor/session; structural edits/library/undo; mixed-content inline text; dynamic sources; CSS states/media; template/part management; two-session locks; native keyboard, touch and responsive navigation
- [x] PHP 7.4 runtime execution of the full WordPress suite: the PHP 7.4 CI job passed on code checkpoint `20f539ec6de0474af64879c1f756e399814f8468`; syntax parsing alone is insufficient
- [x] [Existing PR](https://github.com/littleboy130491/wp-imajiner-editor/pull/1): both PHP 7.4/8.1 CI jobs passed on code checkpoint `20f539ec6de0474af64879c1f756e399814f8468` (job IDs `111694831023` / `111694830507`); CI runs discovery but does not execute Playwright browsers
- [ ] Real paid-provider requests, model-list calls and authenticated key portal checks; no keys provisioned
- [ ] Live FTP/SSH host credential/write/rollback validation; only mocked transports and direct local I/O exercised
- [ ] Optional independent pre-client security audit; no external audit is claimed

## Known implementation limits to retain in deployment docs

- Remote PHP/CSS replacement is verified with backups and rollback, but is not an atomic two-file transaction. A transport failure or process crash can still require administrator recovery; real-host recovery testing remains open above.
- Delete saves a revision, but the normal History/restore route requires a discoverable existing template. There is no dedicated undelete UI; recovery of deleted PHP/CSS pairs still requires an administrator.
- Uninstall cleanup applies to the current site with bounded batches; it does not sweep all sites in a multisite network. Network-wide cleanup would need separate implementation and tests before claiming that support.
