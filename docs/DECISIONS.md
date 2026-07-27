# Decisions

Append-only. Every entry records a choice a future agent might be tempted to reverse, with the reason it must not be.

## Seeded from SPEC.md section 15

1. **Read-only is a product decision, not a limitation.** Do not add write tools "for convenience". A free tool connecting to production earns trust by being structurally incapable of breaking it.
2. **The server returns facts, never verdicts.** Do not add scoring, grading, or recommendation strings. The AI client does the judgment, so analysis improves without shipping plugin updates.
3. **Endpoint disabled by default.** Do not change to enabled for a smoother onboarding. Required posture for wp.org review and correct on its own merits.
4. **No Composer runtime dependencies.** Do not introduce a package to save fifty lines. WordPress ships everything needed, and a dependency-free plugin deploys as one rsync.
5. **Attribution confidence is always reported.** Do not drop the field to tidy the output. A guess presented as fact is worse than no answer.
6. **`sources_unavailable` is always reported.** Do not silently omit failed enrichment. A firewalled site must know its data is partial.
7. **No central hosted service.** Every install is self-contained. Near-zero marginal cost is what makes "free forever" honest.

## Phase 0

8. **Protocol versions supported: `2025-11-25`, `2025-06-18`, `2025-03-26`.** Verified against the live MCP specification on 2026-07-27; the latest version is `2025-11-25`, newer than the `2025-06-18` the spec document remembered. The server echoes the client's requested version when supported, otherwise returns `2025-11-25`. Plain `application/json` responses to POST are still explicitly permitted, and sessions remain optional, so the stateless no-SSE design stands.
9. **The token route pattern accepts any URL-safe token-shaped segment (`[A-Za-z0-9_-]+`), not strictly 64 hex characters.** A near-miss token must reach our handler and get a deliberate 401, not fall through to WordPress's generic REST 404. Do not "tighten" the pattern.
10. **JSON-RPC protocol errors on well-formed requests return HTTP 200 with an error object; only unparseable or structurally invalid bodies return HTTP 400.** This matches JSON-RPC-over-HTTP convention and the MCP transport spec's allowance. Do not convert method-not-found into an HTTP error status.
11. **Stray-output defense lives in the transport, in two parts.** An output buffer opens at `rest_api_init` for MCP requests, and all buffers are discarded in `rest_pre_serve_request` just before the JSON body is written. Other plugins do emit notices on REST requests; do not remove this because the local site happens to be clean.
12. **The local development site is a fresh WordPress install, not the planned clone of outsourcewebdesign.com.** Discovered during the Phase 0 survey on 2026-07-27. All test conditions from ENVIRONMENT-SETUP.md section 7 therefore come from `tests/seed-conditions.sh` until a real clone is imported. If a clone is imported later, re-run the survey before trusting seed assumptions.
13. **`composer.lock` is not committed.** Dev dependencies are two mature, stable packages (PHPCS, WPCS) resolved fresh in CI; a lock file in a dependency-free plugin repo invites noise PRs. Revisit only if CI ever breaks on a resolver change.

## Phase 1

14. **Token-in-path authentication is permanent; OAuth is off the table.** Confirmed working end-to-end on 2026-07-27: the connector is live in Claude Chat against `https://www.outsourcewebdesign.com` and `get_capabilities` returns correctly over public HTTPS. Do not build OAuth 2.1/PKCE "for completeness" — the swappable auth interface stays as insurance, nothing more.
15. **Tools are auto-discovered from `includes/mcp/tools/`.** Adding a tool means dropping one `class-tool-{name}.php` file there; the registry derives the class name from the file name and calls its static `register()`. Do not add a manual registration list that has to be kept in sync.
16. **`truncated` means rows were cut, never that a limit was clamped.** Confirmed with Benny after the Phase 1 checklist: a clamped limit shows as `limit` in the payload; `truncated: true` only when `returned < total`. A paginating client must be able to trust it.
17. **Detail mode caps at 10 rows per page; compact stays at default 25, max 100.** Detail mode is for looking closely at a handful of plugins, not for dumping the estate. The Phase 1 full-site detail response measured 19.3 KB with zero enrichment attached — the 20 KB budget has no headroom for Phase 3 without this cap.
18. **wordpress.org enrichment adds flags to rows, not numbers.** `abandoned`, `closed_on_wporg`, `untested_current_wp`, `no_wporg_record` go into the existing `flags` array on `list_plugins` rows — a few bytes each, and what the AI actually reasons over. The raw figures (install counts, ratings, support thread ratios, last-updated dates) live only in `get_plugin_details`, which is capped at five slugs. Anyone who wants the numbers is already drilling down. Do not add numeric wp.org fields to `list_plugins` rows.
19. **PHPCS standards are pinned to exact versions in composer.json** (php_codesniffer 3.13.5, wpcs 3.4.0) so local and CI can never resolve different rulesets. Bump both deliberately, together, in their own commit.

## Phase 2

20. **The capability taxonomy is deferred indefinitely.** The spec planned a curated JSON mapping ~500 slugs to capability categories and conflict pairs for Phase 6 redundancy detection. In the live Phase 1 connector test, the model detected a real sitemap conflict between All in One SEO and Google Sitemap Generator from plugin names alone — and correctly advised checking which sitemap Search Console reads before deactivating either. Better than a category lookup would have produced. Build Phase 6 without a taxonomy; revisit only if real audits show the model failing on plugins whose names give nothing away.
21. **Enrichment build order: endoflife (Phase 2) → vulnerabilities (Phase 3) → wordpress.org health (Phase 4).** Vulnerability matching moved ahead of wp.org health enrichment: on a live site, whether an active security plugin has a published CVE matters more than whether something looks abandoned. Site overview stays first because endoflife.date is the simplest API and the right place to prove the enrichment interface.

## Phase 3

22. **Two cache backends by design, and the split is deliberate.** Bulk enrichment (WPVulnerability now, wordpress.org in Phase 4) uses a single non-autoloaded option holding a keyed map of `{data, fetched_at}` with expiry checked in PHP. Options always hit the database, so persistence is guaranteed regardless of object cache state — a memory-only object cache must never turn 45 cache misses into 45 outbound calls per MCP request against a volunteer-run API. The endoflife client stays on transients: three or four keys with a seven-day TTL is exactly what transients are for. Do not "unify" these; the split is the point.
23. **Failed lookups are negative-cached for 15 minutes.** A down API must not be hammered on every request. Blocked-by-configuration (`WP_HTTP_BLOCK_EXTERNAL`) is not cached — configuration is not failure.
24. **The WPVulnerability core endpoint returns records pre-matched to the queried version; plugin and PHP endpoints return operator ranges to match locally.** Verified against the live API on 2026-07-27. Do not add operator matching to core findings — there is no operator to match — and never report plugin findings without version matching.
