# Imajiner Editor: to do

Unfinished work, grouped by area. **P1** = needed for the agency to use it day to day, **P2** = important, **P3** = nice to have.
What's already done is listed under "Status" in `AGENTS.md`.

## AI features

- [ ] **P1** Create a template from a prompt
  - "New page with AI" entry point (Pages screen and/or editor)
  - The model returns PHP + CSS; save them as `imajiner/<slug>.php` and `imajiner/css/<slug>.css` in the child theme, then assign the template to the page
  - Before saving: PHP syntax check, scanner contract check (no warnings), scoped CSS check. On failure, send the errors back to the model for one retry
  - Ask for a structured response (e.g. JSON with `slug`, `name`, `php`, `css`) instead of free text
- [ ] **P1** Normalize: rewrite an existing PHP template so it follows the contract
  - Show a before/after preview and the contract warnings that remain, save only after confirmation, keep a revision
- [ ] **P2** "Edit with AI" on a selected section or element (e.g. "make this a 3-column grid"), returning only the changed section
- [ ] **P2** Long requests: generation can exceed the 120 s HTTP timeout and PHP's `max_execution_time`. Use streaming or a background job with progress in the UI
- [ ] **P2** Test every provider with a real key: OpenRouter, OpenAI, Claude, Gemini, xAI, Meta (`api.meta.ai/v1`: confirm it is OpenAI-compatible and that `/models` works), Mistral, DeepSeek, Groq
- [ ] **P3** Confirm the "Get a key" links (Claude, Meta has none yet)
- [ ] **P3** Log AI usage per request (provider, model, tokens, which model answered after a fallback)

## Editor

- [ ] **P1** Add, delete, duplicate and reorder elements and sections (drag and drop in the layers tree and preview)
- [ ] **P1** Add a new section from a library of ready-made, contract-following sections (hero, features, CTA, …)
- [ ] **P1** Undo / redo for unsaved changes
- [ ] **P2** Edit text directly in the preview (inline editing) instead of only in the properties panel
- [ ] **P2** Change the source of a dynamic value (e.g. swap `the_title()` for a custom field)
- [ ] **P2** Code view for locked PHP blocks
- [ ] **P2** Hover/focus styles (`:hover`, `:focus-visible`) in the Style tab
- [ ] **P2** Mixed-content text (text next to `<strong>`, `<a>`, …) updates the preview live, not only after saving
- [x] "New blank template" without AI (Appearance → Imajiner Templates)
- [ ] **P3** Show a diff in History before restoring
- [x] Template parts (header, footer, before footer, …) and single/archive templates with detected post types and taxonomies
- [ ] **P2** Delete or rename templates and parts from Appearance → Imajiner Templates (today: delete the files by hand)
- [ ] **P2** Display conditions for parts (e.g. "before footer" only on pages, or not on the home page); today a located part shows on every page that fires its hook
- [ ] **P2** Test locations with a real custom post type and taxonomy (only post, page, category and tag exist on the test site)
- [ ] **P3** Term-specific templates (e.g. one category) and front-page / blog-page locations
- [ ] **P3** Load part CSS only on pages that render the part (today all parts' CSS loads everywhere)
- [ ] **P3** Style rules the Style tab skips today: `min-width` / other `@media` conditions, compound selectors (`.a, .b`)
- [ ] **P3** Editing lock when two people open the same template (today the second save just gets a conflict error)
- [ ] **P3** Breakpoint settings in the UI (today only the `imajiner_editor_breakpoints` filter)
- [ ] **P3** Removing a `class` attribute leaves a stray space (`<p >`); harmless, comes from WordPress's HTML API
- [ ] **P3** Keyboard navigation in the layers tree

## Theme

- [ ] **P1** Mobile navigation: the header menu has no hamburger/toggle on small screens
- [ ] **P2** Create the child theme automatically for a new client site (today it is copied by hand)
- [ ] **P3** `theme.json` / editor styles so the block editor matches the front end
- [ ] **P3** `screenshot.png` for both themes

## Quality and project

- [ ] **P1** Put the project under git (it isn't a repository yet), with the child theme per site as its own repo
- [ ] **P1** Automated tests in the repo: PHPUnit for the scanner, CSS editor, store and AI client; the browser test scripts used during development are temporary and should become real end-to-end tests
- [ ] **P2** Security review before using it on client sites (file writes, REST endpoints, key storage)
- [ ] **P2** Hosts that need FTP/SSH for file writes: use the `WP_Filesystem` API instead of direct file functions
- [ ] **P2** Translations: the editor's JavaScript strings are hardcoded in English; generate a `.pot` file
- [ ] **P3** Uninstall: also offer to remove the preview cache (`uploads/imajiner/preview/`) and template revisions
