# PluginLens: Build Specification v1.0

**Product identity:** A WordPress plugin that turns the site it is installed on into a read-only MCP server, so any MCP client (Claude, ChatGPT, Cursor) can inspect and reason about that site's plugin estate.

**One thing built, one thing installed, one thing distributed.** The MCP server and the WordPress plugin are the same artifact.

**Status:** Frozen for v1. Anything not in this document goes to `docs/PHASE-2.md`.

---

## 1. Non-negotiables

These are architectural invariants. An agent must not "improve" past them.

| Rule | Reason |
|---|---|
| Read-only. Zero write operations against WordPress state. | A free tool connecting to production earns trust by being structurally incapable of breaking it. |
| The endpoint is **disabled by default**. Admin must explicitly enable it and generate a token. | Required posture for wp.org review, and correct on its own merits. |
| No verdicts in the server. Facts out only. | The AI client does the judgment. Analysis improves without shipping plugin updates, and the same server works in Claude and ChatGPT. |
| No fabricated metrics. No composite "performance score". | Per-plugin runtime cost cannot be measured without a profiler drop-in. Report raw facts, or report that a thing is unmeasurable. |
| Every tool works with zero outbound network access. | Enrichment is additive. A firewalled site still gets a full inventory and usage analysis. |
| No central Macronimous service. Each install is self-contained. | Near-zero marginal cost is what makes "free forever" honest rather than a bill to resent. |
| All external calls degrade silently and report their own absence. | Five optional extras must not become five ways to break. |

---

## 2. Pre-flight setup (Benny, before CCD starts)

### 2.1 Name and slug

Working name **PluginLens**, slug `pluginlens`. Before anything is written, confirm the slug is free at `https://wordpress.org/plugins/pluginlens/` (a 404 means available). Fallbacks in order: `plugin-lens`, `pluginscope`, `plugin-radar`.

The slug decides the text domain, all function prefixes, all option keys, and the SVN path. Changing it later is a full rename. Settle it first.

wp.org rules: the slug and display name may not contain "WordPress" or "WP" as a leading trademark-style token, and may not lead with another brand name.

### 2.2 GitHub

1. Create repo `macronimous/pluginlens`. **Private for now**, flip to public at Phase 9 before wp.org submission. A community tool needs a public repo; it does not need one while the auth layer is still a guess.
2. Default branch `main`. Enable branch protection: no direct pushes, PR required. CCD works on feature branches only.
3. Add `LICENSE` (GPL-2.0-or-later, required by wp.org).
4. Add topics: `wordpress`, `wordpress-plugin`, `mcp`, `model-context-protocol`.
5. Repo secrets, added at Phase 10 only: `SVN_USERNAME`, `SVN_PASSWORD`.

### 2.3 Local environment (this is what makes CCD autonomous)

CCD needs a WordPress it can break freely and inspect without asking you anything.

Install on the Mac:
- Docker Desktop
- Node 20+ (for `@wordpress/env`)
- PHP 8.2 CLI and Composer (for PHPCS)
- WP-CLI

CCD then runs `wp-env` from inside the repo. The repo ships a `.wp-env.json` and a seed script that builds a deliberately filthy test site. **Without a bad site, the interesting tools are unverifiable.** The seed must produce:

- 40+ plugins installed, roughly half active
- Two competing caching plugins active at once
- Two competing SEO plugins active at once
- At least three plugins with a last-update date older than three years
- One plugin whose wp.org listing is closed
- One premium/custom plugin with no wp.org record at all
- A plugin that was deleted after creating a custom table (drop the plugin, keep the table) to produce an orphan table
- A scheduled cron event whose plugin has been removed, to produce an orphan cron
- 500+ posts, some containing shortcodes from active plugins, and at least one active plugin whose shortcodes appear zero times
- A single-file plugin (not in a folder), an mu-plugin, and an `object-cache.php` drop-in

### 2.4 Real-site testing (Benny, after Phase 2)

Your own sites are the real test surface. For each, CCD needs nothing; you install the built plugin, enable the endpoint, and connect. Provide CCD with a staging or dead-but-live site URL plus admin login only if you want it to debug a live failure. It should never need production access to build.

### 2.5 Accounts

None. WPVulnerability and endoflife.date are keyless. wp.org API is keyless. An account at wordpress.org is needed only at Phase 10 for submission.

---

## 3. Repository layout

```
pluginlens/
  pluginlens.php              Main plugin file, header, bootstrap
  uninstall.php               Delete all options and transients
  readme.txt                  wp.org readme (canonical)
  README.md                   GitHub readme
  LICENSE
  .wp-env.json
  composer.json               PHPCS dev dependency only, no runtime deps
  phpcs.xml.dist
  includes/
    class-plugin.php          Bootstrap, hooks, singleton
    class-settings.php        Admin page, token lifecycle, enable toggle
    class-security.php        Token verify, rate limit, auth log
    mcp/
      class-mcp-server.php    Route registration, JSON-RPC envelope
      class-tool-registry.php Tool definitions and dispatch
      tools/                  One file per tool
    collectors/
      class-inventory.php
      class-site-context.php
      class-autoload.php
      class-cron.php
      class-database.php
      class-usage.php
      class-attribution.php   Shared slug-to-prefix mapping
    enrichment/
      class-enrichment-manager.php   Orchestrates, caches, degrades
      class-wporg-client.php
      class-wpvulnerability-client.php
      class-endoflife-client.php
    data/
      prefix-overrides.json   Curated slug to option/table prefix map
  admin/
    css/ js/                  Local assets only, never a CDN
  languages/
  tests/
    seed-messy-site.sh
    mcp-client.php            CLI harness that speaks JSON-RPC to the endpoint
  .github/workflows/
    ci.yml
    deploy.yml                Phase 10 only
  docs/
    SPEC.md                   This file
    DECISIONS.md
    PHASE-2.md
```

**No Composer runtime dependencies.** WordPress ships everything needed, including an HTTP client with parallel request support. Vendor directories complicate wp.org review for no gain here.

---

## 4. Architecture

Three layers inside one plugin.

**Transport layer.** Registers one REST route, speaks MCP over JSON-RPC 2.0, advertises the tool list, dispatches calls, formats responses. Knows nothing about WordPress internals.

**Collector layer.** Reads WordPress directly: `get_plugins()`, the options table, the cron array, `$wpdb`, post content. Returns plain PHP arrays. Makes no network calls. Knows nothing about MCP.

**Enrichment layer.** Optional outbound HTTP to three public APIs. Caches in transients. Every method returns either data or null, never throws. Knows nothing about MCP.

Tools compose collectors plus enrichment. This separation is what lets a tool work identically on a firewalled site with the enrichment fields simply absent.

---

## 5. Transport specification

### 5.1 Endpoint

```
POST /wp-json/pluginlens/v1/mcp/{token}
```

Registered with `permission_callback` returning `true`; authentication happens inside the handler so error shapes stay under our control.

`GET` on the same route returns `405`. There is no SSE stream and no server-initiated messaging in v1.

### 5.2 Protocol

JSON-RPC 2.0 over Streamable HTTP, responding with a single `application/json` body rather than an event stream. The MCP spec permits this and it is dramatically simpler in PHP.

Methods to implement:

| Method | Response |
|---|---|
| `initialize` | `protocolVersion`, `capabilities: {tools: {}}`, `serverInfo: {name, version}` |
| `notifications/initialized` | HTTP 202, empty body, no JSON-RPC response |
| `tools/list` | Array of `{name, description, inputSchema}` |
| `tools/call` | `{content: [{type: "text", text: "<json string>"}], isError: bool}` |
| `ping` | `{}` |

Protocol version: echo the client's requested version if supported, otherwise return the server's latest supported version. Target `2025-06-18` and accept `2025-03-26`. **Verify the current version string against the live MCP specification at build time rather than trusting this document.**

Run stateless. Do not issue `Mcp-Session-Id`.

JSON-RPC errors: `-32700` parse, `-32600` invalid request, `-32601` method not found, `-32602` invalid params, `-32603` internal. Tool execution failures are **not** JSON-RPC errors; they return a normal result with `isError: true` and an explanatory text block.

### 5.3 WordPress-specific traps

- Return the JSON-RPC object as the `WP_REST_Response` data so the body is exactly that object with no envelope.
- Some plugins emit stray output on REST requests. Buffer and discard before writing the response.
- No CORS handling. Anthropic's servers call the endpoint, not a browser.

### 5.4 Phase 0 auth spike (go/no-go)

Before any tool logic is written, prove a connection from Claude Chat.

Build the smallest thing that answers `initialize`, `tools/list`, and one hardcoded tool returning `{"ok": true}`. Expose it on a publicly reachable URL. Add it in Claude Chat as a custom connector. Confirm the tool appears and can be called.

Token-in-path is the primary approach and matches the pattern already working in the MWT and Zoho connectors. If Claude Chat rejects it, the fallback is OAuth 2.1 with PKCE and dynamic client registration, which is a substantially larger build. **Design `class-security.php` behind an interface from day one so the auth mechanism is swappable without touching the transport layer.**

Do not proceed to Phase 1 until this connects.

---

## 6. Tool catalog

Nine tools. Every response is a JSON string inside a text content block.

Every response carries:

```json
"_meta": {
  "total": 47,
  "returned": 25,
  "truncated": true,
  "sources_unavailable": ["wpvulnerability"],
  "generated_at": "2026-07-27T09:14:22Z"
}
```

### 6.1 `get_capabilities`

No arguments. Returns what this server can and cannot answer, which enrichment sources are currently reachable, and the explicit list of things it does not measure (per-plugin runtime, front-end asset weight, write operations). Lets the client orient itself and stops it inventing capabilities.

### 6.2 `get_site_overview`

No arguments. WordPress version, PHP version, MySQL/MariaDB version and flavor, active theme and parent, multisite status and site count, external object cache in use, `WP_DEBUG`, memory limit, max execution time, `DISABLE_WP_CRON`, counts of active/inactive/mu/drop-in plugins, total posts. Enriched with endoflife.date support status for PHP, WordPress, and the database engine.

### 6.3 `list_plugins`

Arguments: `status` (`all`|`active`|`inactive`|`mu`|`dropin`), `has_update` (bool), `health` (`all`|`abandoned`|`untested`|`no_wporg_record`), `limit` (default 25, max 100), `offset`, `detail` (bool, default false).

Compact rows by default: `slug`, `name`, `version`, `status`, `update_available`, `latest_version`, `flags[]`.

`flags` is a short array of machine-readable strings: `abandoned`, `untested_current_wp`, `closed_on_wporg`, `no_wporg_record`, `requires_newer_php`, `requires_newer_wp`, `has_vulnerability`, `network_active`, `single_file`.

With `detail: true`: adds author, description (first 200 chars), plugin URI, text domain, requires WP, requires PHP, auto-update setting, disk size, file count, last updated on wp.org, tested up to, active installs, rating, support resolution ratio.

### 6.4 `get_plugin_details`

Arguments: `slugs` (array, max 5, required).

Everything known about each named plugin: full inventory record, full wp.org record, vulnerability records matched to the installed version, autoload contribution, cron events, database tables, registered shortcodes/blocks/CPTs and their usage counts. This is the drill-down after `list_plugins`, and the cap of five is what keeps it from blowing the context window.

### 6.5 `check_vulnerabilities`

Arguments: `slugs` (array, optional; all installed plugins if omitted), `include_core` (bool, default true).

Per finding: affected slug, installed version, source, CVE where present, CVSS score and severity, affected version range, fixed-in version, whether the **installed** version falls inside the affected range. Never report a slug-level match as a version-level match.

### 6.6 `analyze_autoload`

Arguments: `top` (default 20).

Total autoloaded bytes, total autoloaded option count, per-plugin attributed bytes and option counts, the largest individual options with their owning plugin, and an explicit `unattributed` bucket. Attribution confidence per row: `high` (curated override), `medium` (prefix derived from slug), `low` (heuristic). Do not hide the unattributed bucket.

### 6.7 `analyze_cron`

Arguments: none.

Total scheduled events, `DISABLE_WP_CRON` state, per-plugin event list with hook name, schedule, and next run, plus an `orphaned` array of hooks that have no registered callback at runtime. Runtime callback absence is a reliable orphan signal because the check runs with all active plugins loaded.

### 6.8 `analyze_database`

Arguments: none.

Non-core tables with prefix matching the site's table prefix: name, row count, data size, index size, attributed plugin, attribution confidence. Separate `orphaned` array for tables whose prefix maps to no installed plugin. Core WordPress tables excluded by an explicit allowlist, not by guesswork.

### 6.9 `analyze_usage`

Arguments: `slugs` (array, optional), `max_posts` (default 20000).

For each plugin: shortcodes registered and occurrence count in post content, block namespaces registered and occurrence count, custom post types registered and published count, custom taxonomies registered and term count. Flag `zero_usage: true` where a plugin registers content features that appear nowhere.

If the site exceeds `max_posts`, return counts as `null` with a `skipped_reason` rather than running an expensive scan. Never silently return zero for a check that did not run.

---

## 7. Collector implementation notes

These are the parts where a wrong assumption costs a day.

**Inventory.** `get_plugins()` needs `wp-admin/includes/plugin.php` loaded manually in a REST context. Use `get_mu_plugins()` and `get_dropins()` for the two categories that never appear on the plugins screen. Updates come from `get_site_transient('update_plugins')`; do not trigger a fresh check on every call. Multisite needs `get_site_option('active_sitewide_plugins')` in addition to per-site `active_plugins`. Slug derivation: directory name, or basename minus `.php` for single-file plugins.

**Autoload.** WordPress 6.6 changed the `autoload` column values. Query for `autoload IN ('yes','on','auto','auto-on')` so both old and new schemas are handled. Size via `LENGTH(option_value)`.

**Attribution.** Mapping option names and table names back to plugins is the genuinely hard part and it cannot be perfect. Three tiers, in order: the curated `prefix-overrides.json`, then prefixes derived from the slug (full slug underscored, first word, initialism), then no match. Report the confidence tier on every attributed row. **Do not guess and present the guess as fact.** The overrides file starts with the fifty most common plugins and grows through pull requests, which is exactly the kind of asset a community repo accumulates for you.

**Shortcode attribution.** The global `$shortcode_tags` array holds every registered tag and its callback. Use `ReflectionFunction` or `ReflectionMethod` to get the callback's declaring file, then match that path against plugin directories. This gives accurate attribution, unlike prefix matching.

**CPT and taxonomy attribution.** Hook `registered_post_type` and `registered_taxonomy` during `plugins_loaded` and capture the calling file with `debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)`. Gate this listener behind a check that the current request URI contains the plugin's REST namespace, so normal site traffic pays nothing.

**Usage counting.** `$wpdb->prepare` with `$wpdb->esc_like` on every pattern. Exclude revisions and auto-drafts. Batch the counts into as few queries as possible rather than one query per shortcode.

**Disk footprint.** Cache aggressively in a 24 hour transient. Cap the recursive scan at a file count ceiling and report `null` past it rather than timing out.

---

## 8. Enrichment specification

All three clients implement the same interface: return data or `null`, never throw, always cache, always record their own availability into `_meta.sources_unavailable`.

Use WordPress's bundled Requests library for parallel fetches. Serial requests across sixty plugins will time out.

### 8.1 wp.org plugin API

```
https://api.wordpress.org/plugins/info/1.2/
  ?action=plugin_information
  &request[slug]={slug}
  &request[fields][sections]=false
```

Fields used: `last_updated`, `tested`, `requires`, `requires_php`, `active_installs`, `rating`, `num_ratings`, `support_threads`, `support_threads_resolved`, `version`.

A non-success response means the plugin is closed, removed, or was never on wp.org. These cases are **not reliably distinguishable**. Flag as `no_wporg_record` and let the AI client reason about it. Do not assert "abandoned" from a failed lookup.

Cache 24 hours per slug.

### 8.2 WPVulnerability

```
https://www.wpvulnerability.net/plugin/{slug}/
https://www.wpvulnerability.net/core/{version}/
```

Keyless. Response contains a vulnerability array with source, severity, CVSS data, and version operators. Match the installed version against the operator ranges using `version_compare`. A slug appearing in the database is not a finding; only a version inside an affected range is.

Cache 12 hours.

**Verify the exact response shape against the live API before writing the parser.** This document describes it from memory and the schema may have changed.

### 8.3 endoflife.date

```
https://endoflife.date/api/php.json
https://endoflife.date/api/wordpress.json
https://endoflife.date/api/mysql.json
https://endoflife.date/api/mariadb.json
```

Match the running version to its cycle, return EOL date, security support status, and latest version in cycle. Cache 7 days.

endoflife.date has been migrating to a versioned API path. Check whether `/api/v1/products/{product}/` is live and prefer it if so, keeping the legacy path as fallback.

---

## 9. Security requirements

Non-optional. wp.org review will scrutinize a plugin that exposes site data over a token-authenticated endpoint, and they are right to.

1. **Disabled by default.** A fresh install exposes nothing. The admin must toggle the endpoint on.
2. **Token generation:** `random_bytes(32)`, hex encoded. Stored in a non-autoloaded option. Compared with `hash_equals`, never `==`. Displayed on the settings page with a copy button and a "Revoke and regenerate" action that invalidates the old token immediately.
3. **Rate limiting:** transient-backed counter per IP. Default 60 requests per minute, filterable. Exceeding it returns HTTP 429.
4. **Auth log:** last 50 failed attempts with timestamp, IP, and user agent, visible on the settings page. Capped so it cannot grow unbounded.
5. **Zero write capability.** The codebase must contain no call to `activate_plugin`, `deactivate_plugins`, `delete_plugins`, `wp_update_plugins`, any `*_Upgrader` class, `update_option` outside the plugin's own settings keys, or any `INSERT`/`UPDATE`/`DELETE`/`DROP`/`ALTER` SQL. Add a CI grep check that fails the build on any of these.
6. **All SQL** through `$wpdb->prepare` with `esc_like` on user-influenced patterns.
7. **Settings page** requires `manage_options`, or `manage_network_options` on multisite. Nonce on every form. Escape every output.
8. **Never return** raw post content, full changelogs, raw API payloads, database credentials, salts, or file paths outside the plugins directory.
9. `uninstall.php` removes every option and transient the plugin created.

---

## 10. Response size discipline

Design this in at Phase 2. Retrofitting it means rewriting every tool.

- Default responses are compact. Verbosity is opt-in through `detail: true`.
- Default `limit` 25, hard maximum 100.
- `get_plugin_details` accepts at most 5 slugs.
- Truncate every free-text field: descriptions at 200 characters, no exceptions.
- Round all byte values to two significant figures and label the unit.
- Every response reports `total`, `returned`, and `truncated` so the client knows to paginate.

A sixty-plugin site returning full metadata will exhaust the context window and make the product useless. This constraint is the difference between a tool that works and one that technically functions.

---

## 11. Testing

**Harness.** `tests/mcp-client.php` is a CLI script that speaks raw JSON-RPC to the local endpoint. CCD uses it to verify every tool without needing an AI client in the loop. This is what makes autonomous iteration possible.

**Per-phase gate.** Every tool must be verified against the messy seed site, and the response byte size logged. Any response over 20 KB gets trimmed before the phase closes.

**Edge cases that must not crash anything:** zero plugins active; a plugin directory with no readable main file; a site with no posts; a multisite network; a site with outbound HTTP blocked; a plugin with a slug containing unusual characters; 200,000 posts.

---

## 12. wp.org submission checklist (Phase 10)

- `readme.txt` in the official format with `Stable tag`, `Tested up to`, `Requires at least`, `Requires PHP`, `License: GPLv2 or later`.
- **External services disclosure section in `readme.txt`.** Mandatory. Must name api.wordpress.org, wpvulnerability.net, and endoflife.date, state exactly what data is sent to each (plugin slugs and version strings, nothing else), state that no site content or personal data leaves the site, and link each service's terms and privacy policy.
- A second disclosure paragraph stating plainly what the MCP endpoint exposes and that it is disabled until an admin enables it.
- Text domain identical to the slug. Every user-facing string wrapped in a translation function.
- Every function, class, option, transient, and hook prefixed `pluginlens_` or `PluginLens_`.
- No minified or obfuscated code. No assets loaded from a CDN. No tracking or analytics of any kind.
- PHPCS clean against `WordPress-Extra`. PHP 7.4 through 8.4 lint clean.
- `.wordpress-org/` with `banner-1544x500.png`, `icon-256x256.png`, and screenshots.
- Repo flipped to public.
- Submit through the plugin developer portal. Review currently runs several weeks. Do not resubmit or chase; respond to reviewer email promptly and completely.
- After approval, `deploy.yml` using the 10up WordPress plugin deploy action pushes tagged releases from Git to SVN.

---

## 13. Explicitly out of scope for v1

Do not build these. They go in `docs/PHASE-2.md`.

Write operations of any kind. Theme analysis. Core file integrity checks. Multi-site fleet aggregation. Scheduled scans or drift history. Report generation inside the plugin. PageSpeed or Core Web Vitals integration. WPScan or any keyed vulnerability provider. Front-end asset attribution. Per-plugin execution timing. A hosted Macronimous service of any description. User accounts. Telemetry.

---

## 14. Phase plan

Each phase is one branch, one PR, independently useful. No chained tasks.

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | Repo scaffold, plugin header, settings page with enable toggle and token, minimal MCP transport, one hardcoded tool | Connects successfully as a custom connector in Claude Chat |
| 1 | Full transport: initialize, tools/list, tools/call, ping, error handling, tool registry | `mcp-client.php` exercises all methods |
| 2 | Inventory collector, `list_plugins`, `get_capabilities`, response size discipline | Correct output against the messy seed site, under 20 KB |
| 3 | Site context collector, endoflife client, `get_site_overview` | Correct EOL status for PHP, WP, and MySQL |
| 4 | wp.org client with parallel fetch and caching, health flags on `list_plugins` | Abandoned and closed plugins flagged correctly on seed site |
| 5 | WPVulnerability client, `check_vulnerabilities` | Version range matching verified against a known vulnerable version |
| 6 | Attribution engine, autoload, cron, and database collectors, three analyze tools | Orphan table and orphan cron both detected on seed site |
| 7 | Usage collector, `analyze_usage`, `get_plugin_details` | Zero-usage plugin correctly identified on seed site |
| 8 | Rate limiting, auth log, revoke flow, security hardening, admin UI polish | CI write-operation grep check passes |
| 9 | readme.txt, i18n, PHPCS clean, CI matrix, banner and icon assets, repo public | Full checklist in section 12 satisfied |
| 10 | wp.org submission, SVN deploy workflow | Approved and live |

---

## 15. Decisions to record

CCD creates `docs/DECISIONS.md` at Phase 0 and appends an entry for every choice a future agent might reverse. Seed it with:

- Read-only is a product decision, not a limitation. Do not add write tools "for convenience".
- The server returns facts, never verdicts. Do not add scoring, grading, or recommendation strings.
- Endpoint disabled by default. Do not change to enabled for a smoother onboarding.
- No Composer runtime dependencies. Do not introduce a package to save fifty lines.
- Attribution confidence is always reported. Do not drop the field to tidy the output.
- `sources_unavailable` is always reported. Do not silently omit failed enrichment.
- No central hosted service. Every install is self-contained.
