# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.


## Hard rules

- **Do not add the Claude attribution to commit messages.** No `Co-Authored-By: Claude …` line.
- **`git commit` is pre-authorized; `git push` is not.** When work is complete and the diff is reviewable, you may run `git add <specific files>` + `git commit` without asking — this is durable authorization. **Never `git push`, `git push --force`, or otherwise publish commits to a remote without an explicit per-action request from the user.** Same for any other remote-publishing action (PR creation, branch deletion on origin, etc.). Use sensible commit groupings for larger changes.
- **`audits/`, `CLAUDE.md`, and `.claude/` are all gitignored.** Don't try to git-add them; treat audit files as durable scratch.
- **Never alter the production database**, no additions, alterations, deletions, unless specifically told to.
- **Never write/alter/add/delete files in `public_html/resources` or `public_html/php`** — these are synced from the Magnus-tools shared library. wikistream-specific Vue/ES6 code lives under `public_html/vue_components/` (Composition API ES modules) and imports from the shared library by relative path.
- **Keep mobile in mind.** — mobile-first design, responsive layouts, and touch-friendly interactions.
- **Assume the CDNs are safe.** `tools-static.wmflabs.org`, `cdnjs.cloudflare.com`, and `upload.wikimedia.org` do not need Subresource Integrity (`integrity=`) attributes.
- This is a web-facing product, so **keep security in mind**.
- Always keep **code readability and long-term maintenance** in mind.
- Adhere to **SOLID and DRY principles**.
- Use **best practices** and **language standards**.
- **Keep the code simple** and elegant.
- **Aim to keep code small** where possible.
- **Write tests** where it makes sense.
- **Warn me if you think what I ask of you is a bad idea.** Or just window dressing, with no improvement of functionality, UX, or code readability. Be honest.
- **YouTube must only be embedded via `youtube-nocookie.com`, never `youtube.com`.** Minimize data leakage to YouTube/Google: no Google scripts, fonts, or tracking pixels; no third-party cookies set from our origin.
- **Stay in scope when ingesting items.** WikiFlix is exclusively about creative screen works (films, episodes, and the like); WikiVibes is exclusively about creative audio works (music, currently). People, places, topics, organisations, and similar non-creative-work items are out of scope as *primary* items and must never be ingested, even when they carry a P10/P51/etc. media statement. Supporting items (actors, directors, performers, series, …) are fine via the existing person/group/section tables. **Two-stage scope enforcement:** (1) every SPARQL discovery query in `$config->sparql` / `$config->episode_sparql` must constrain via `wdt:P31/wdt:P279*` to the appropriate root class (Q11424 film, Q21191270 TV episode, Q2188189 musical work, …) — this is the authoritative gatekeeper at ingestion time; (2) `WikiStream::purge_out_of_scope_items()` runs in the default cron pipeline and deletes anything whose post-fetch `primary_type_q` isn't a P279* descendant of `WikiStreamConfig*->scope_root_qs`. Don't add a per-item SPARQL re-check between those two stages — that's what the original implementation tried, and 500-Q VALUES batches were lossy under WDQS timeouts.

## Common commands

- Run the full test suite: `vendor/bin/phpunit`
- Run a single test file: `vendor/bin/phpunit tests/unit/WikiStreamTest.php`
- Run a single test by name: `vendor/bin/phpunit --filter testMethodName`
- Local dev webserver (for the SPA + `api.php`): `php -S localhost:8000 -t public_html`
- Hourly update / data regeneration entry point: `php scripts/update.php [cmd]` — `cmd` is one of the cases in the `match` block in `scripts/update.php` (e.g. `json`, `person`, `reset`); no arg runs the default full pipeline.

There is no lint/format step configured. PHP 8.2+ is required (`composer.json`).

## Architecture

This single codebase powers two Toolforge tools — **WikiFlix** (films) and **WikiVibes** (audio) — selected at runtime.

### Mode selection
`scripts/config.php` defines `WikiStreamConfig` with a `get_config_instance()` factory that reads `config.json` (not committed; lives at the repo root in production) and returns either `WikiStreamConfigWikiFlix` or `WikiStreamConfigWikiVibes` based on `site_mode`. The two subclasses differ only in data: SPARQL queries, Wikidata property IDs (`people_props`, `file_props`, `misc_section_props`, …), database name (`tool_db`), and `add_special_sections()` behavior. **Whenever you add a config field, add it to both subclasses.**

### Major layers

1. **`scripts/wikistream.php` — the `WikiStream` class.** All DB queries and business logic. Constructor takes a config, a `ToolforgeCommon` (optional, defaults to a fresh one), and an `HttpClientInterface` (optional, defaults to `CurlHttpClient`). The HTTP client is injectable specifically so tests can avoid real network calls.
2. **`public_html/api.php` — thin JSON dispatcher.** Reads `action` from the request and calls the corresponding `WikiStream` method. Handles Widar (OAuth) auth for actions that need a user identity (`get_your_list`, `set_user_item_list`, etc.). Adding a new endpoint = adding a new `action` branch here.
3. **`public_html/index.html` + `vue_components/main.js` + `vue_components/{pages,components,composables}/*.js` — Vue 2.7 SPA, Composition API, ES modules.** `main.js` bootstraps the app (mounts on `#app`, sets up vue-router with `:key="$route.path"` so each route remounts). All AJAX goes through `api.php`. Shared Magnus-tools libraries (jQuery, `tt.js`, `wikidata.js`) are loaded as classic scripts from `tools-static.wmflabs.org`; Vue components from the shared library (`<wd-link>`, `<commons-thumbnail>`, `<widar>`, …) are ES-module imports under `public_html/resources/vue_es6/`. Wikistream-local CSS lives in `public_html/styles.css`.
4. **`public_html/config.js`** is **generated** by `scripts/update.php` (`generate_main_page_data`). Don't hand-edit it. It supplies UI-side config and main-page section data.

### Adding to the SPA

- **New page** — create `public_html/vue_components/pages/<name>.js`, then add a route in `public_html/vue_components/router.js`. Use `setup()` for state/effects; keep an Options-API `methods:` block for anything that needs `this.$router`.
- **New shared widget** — create `public_html/vue_components/components/<name>.js`, then register globally in `public_html/vue_components/main.js` (`Vue.component('my-widget', MyWidget)`).
- **New composable** — `public_html/vue_components/composables/use<Name>.js`; export a factory function.
- **Translated text** — apply `mixins: [ttMixin]` (from `resources/vue_es6/state.js`). The mixin calls `state.tt.updateInterface(this.$el)` on `mounted`/`updated`, so `tt` and `tt_title` attributes in templates are resolved automatically.
- **Shared Magnus-tools components** (`<wd-link>`, `<commons-thumbnail>`, `<widar>`, `<wd-date>`, `<typeahead-search>`, …) are already registered globally via `registerAll(Vue)` in `main.js`. Use them in templates without importing.
- **CSS** — wikistream-specific styles go in `public_html/styles.css` (loaded by `index.html`). Component-specific rules should be class-prefixed to avoid leakage.

### Toolforge dependencies and the test bootstrap

`WikiStream` requires `ToolforgeCommon`, `WikidataItem`, and `WikidataItemList` — these live in the Magnus-tools shared library deployed on Toolforge under `public_html/php/`, **not in this repo**. `tests/bootstrap.php`:

- Declares minimal stub classes for all three before loading production code, so `require_once` in `wikistream.php` is a no-op (the classes are already defined).
- Creates empty placeholder files at `public_html/php/ToolforgeCommon.php` and `public_html/php/wikidata.php` in a temp dir so the production `require_once` calls don't emit warnings.

Tests substitute these stubs per-case using PHPUnit mocks or hand-rolled fakes (see `WikidataItemList::setItem()` in the bootstrap for the pre-population hook). When writing new tests that touch HTTP, inject a fake `HttpClientInterface` via the `WikiStream` constructor — don't call out.

### Database

Schema in `scripts/db.sql`. Production uses MariaDB on Toolforge (`wikiflix_p` / `vibes_p`). Queries are written as raw SQL strings inside `WikiStream` methods using `$this->tfc->getSQL($db, $sql)`. There is no ORM. When adding queries, follow the existing pattern: cast Q-numbers with `*1` or `(int)` before interpolation since user input flows in via `getRequest`.

### Data flow for a typical request

`browser → vue_components/main.js + page component → fetch('api.php?action=...') → api.php switch → WikiStream method → ToolforgeCommon::getSQL → MariaDB → JSON response → Vue component`

Background data ingestion is the opposite direction: `scripts/update.php` (cron, hourly) → SPARQL/Wiki APIs → DB → regenerates `config.js`.
