# CCD Prompt: PluginLens Phase 2

Site context collector, the enrichment layer, and end-of-life data.

Paste everything below the line into CCD.

---

Phase 1 is merged and deployed. The connector was tested from Claude Chat with a plain-language question and `list_plugins` was selected on the strength of its description alone, then answered correctly against the live site.

Two decisions came out of that test. Record both in `docs/DECISIONS.md` before starting.

**1. The capability taxonomy is deferred indefinitely.** The spec plans a curated JSON mapping around 500 slugs to capability categories and known conflict pairs, for redundancy detection in Phase 6. In the live test, the model detected a real sitemap conflict between All in One SEO and Google Sitemap Generator from plugin names alone, with no taxonomy, and correctly advised checking which sitemap Search Console reads before deactivating either. That is better than a category lookup would have produced. Build the rest of Phase 6 without a taxonomy and only revisit if real audits show the model failing on plugins whose names give nothing away.

**2. Enrichment order changed.** Vulnerability matching now comes before wordpress.org health enrichment. On a live site, whether an active security plugin has a published CVE matters more than whether some plugin looks abandoned. Site overview stays first because endoflife.date is the simplest of the three APIs and is the right place to prove the enrichment interface.

Branch: `phase-2-site-overview`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## Scope

### A. Enrichment manager

`includes/enrichment/class-enrichment-manager.php`, plus the interface every enrichment client implements. This scaffolding is the real deliverable of the phase. The endoflife client is just the first thing to use it.

Requirements:

- Every client returns data or `null`. Never throws, never fatals, never blocks the response.
- Every client caches in a transient with its own TTL.
- Every failure — unreachable host, timeout, malformed response, missing data — appends the client's name to `_meta.sources_unavailable` and the tool returns its remaining fields normally.
- Use WordPress's bundled Requests library. Design for parallel fetches now, even though endoflife only needs a handful, because the wordpress.org client will need to fetch across every installed plugin.
- Set a short timeout. A slow third-party API must never make the MCP endpoint appear hung.

Verify by blocking outbound HTTP locally and confirming `get_site_overview` still returns every non-enriched field with `sources_unavailable: ["endoflife"]`.

### B. endoflife.date client

`includes/enrichment/class-endoflife-client.php`.

Endpoints:

```
https://endoflife.date/api/php.json
https://endoflife.date/api/wordpress.json
https://endoflife.date/api/mysql.json
https://endoflife.date/api/mariadb.json
```

Each returns an array of cycles. Match the running version to its cycle and return the cycle, its end-of-life date, whether active support has ended, and the latest release in that cycle.

endoflife.date has been migrating to a versioned path at `/api/v1/products/{product}/`. Check whether it is live and prefer it, keeping the legacy path as fallback. Verify the actual response shape against the live API rather than trusting any description of it.

Cache 7 days.

### C. Site context collector

`includes/collectors/class-site-context.php`. No network calls.

Collect: WordPress version, PHP version, database version and flavor (MySQL versus MariaDB, which differ in endoflife.date), active theme and parent theme with versions, multisite status and site count, whether an external object cache is in use, `WP_DEBUG` state, memory limit both from constant and from PHP, max execution time, `DISABLE_WP_CRON` state, counts of active, inactive, mu, and drop-in plugins, and total published posts.

### D. `get_site_overview`

No arguments. Returns the site context enriched with end-of-life status for PHP, WordPress, and the database engine.

Report end-of-life as facts, not warnings. Give the version, the cycle, the EOL date, and whether that date has passed. Do not add severity labels, risk scores, or advice. The client does the judgment.

### E. Update `get_capabilities`

Move `get_site_overview` into `available_now` with an accurate description.

Add a line to the document stating that if a tool listed in `available_now` does not appear in the client's tool list, the client's tool list is stale and should be refreshed by reconnecting. During the Phase 1 test this exact situation occurred and the capabilities document was what made it diagnosable rather than looking like a broken tool.

## Out of scope

No wordpress.org lookups. No vulnerability data. No autoload, cron, database, or usage analysis. No `get_plugin_details`. No health flags on `list_plugins`.

## Verification checklist

Report back when I can do all of these:

1. Call `get_site_overview` on the Local site and see every field populated correctly.
2. See the database correctly identified as MySQL or MariaDB, matched against the right endoflife product.
3. Block outbound HTTP locally, call the tool again, and see every non-enriched field still returned with `sources_unavailable: ["endoflife"]` and no error.
4. Call it twice in succession and confirm the second call hits the transient cache rather than the network.
5. Confirm the response stays well under 20 KB.
6. CI passes on the pull request.

Then deploy to the live site and confirm the real result. That site runs PHP 7.4.33, which reached end of life in November 2022, so `get_site_overview` should report the EOL date as passed. That is the check that proves the enrichment path works over public HTTPS, not just locally.
