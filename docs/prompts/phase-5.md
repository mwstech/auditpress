# CCD Prompt: AuditPress Phase 5

Attribution, autoload weight, cron, and database tables. The site-weight phase.

Paste everything below the line into CCD.

---

Phase 4 is merged and live. All three external sources are integrated. This phase adds no new external source. Everything here is read directly from WordPress and the database.

Branch: `phase-5-attribution`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## The honesty problem in this phase

Everything here depends on mapping an option name or a table name back to the plugin that created it. That mapping is a heuristic. It cannot be made perfect, and no amount of effort will change that.

The failure mode to avoid is not inaccuracy. It is confident inaccuracy. A row that says an option belongs to WooCommerce when it does not is worse than a row that says the owner is unknown, because a client reasoning over it will make a recommendation you cannot defend to a customer.

So: every attributed row carries a confidence level, the unattributed bucket is always visible and never hidden, and no total is presented as complete when part of it is guesswork.

## A clarification on the deferred taxonomy

Decision 20 deferred the **capability taxonomy** indefinitely: the map of slug to category, used for redundancy detection. That is still deferred. Do not build it.

This phase needs a different and much smaller thing: a **prefix overrides file** mapping plugin slugs to the option and table prefixes they actually use, for cases where the prefix cannot be derived from the slug. Contact Form 7 uses `wpcf7_`, which no algorithm derives from `contact-form-7`. That file is needed and is in scope.

They are separate. Record the distinction in `docs/DECISIONS.md` so a future session does not conclude both were dropped.

## Scope

### A. Attribution engine

`includes/collectors/class-attribution.php`. Shared by all three collectors in this phase.

Three tiers, tried in order, with the tier recorded on every result:

1. **high** — an entry in `includes/data/prefix-overrides.json`
2. **medium** — a prefix derived from the slug: the full slug underscored, the first word, the initialism
3. **none** — no match, reported as unattributed

Seed `prefix-overrides.json` with entries for the plugins actually present on the seeded Local site plus the most widely installed plugins on wordpress.org. Do not attempt to be comprehensive. The file grows through pull requests once the repo is public, which is the point of it being a data file rather than code.

### B. Autoload analysis

Query the options table for autoloaded options and their sizes via `LENGTH(option_value)`.

**WordPress 6.6 changed the autoload column values.** Query for `autoload IN ('yes','on','auto','auto-on')` so both the old and new schemas work. Getting this wrong silently returns nothing on modern sites, or everything on old ones.

`analyze_autoload` takes a `top` argument, default 20. Returns total autoloaded bytes, total autoloaded option count, per-plugin attributed bytes and counts with confidence, the largest individual options with their attributed owner, and an explicit `unattributed` bucket with its own byte total and option count.

Report the unattributed bucket even when it is large. Especially when it is large.

### C. Cron analysis

Read the cron array via `_get_cron_array()`.

`analyze_cron` takes no arguments. Returns total scheduled events, the `DISABLE_WP_CRON` state, per-plugin events with hook name, schedule, and next run time, and an `orphaned` array.

Orphan detection: a hook with no registered callback at runtime. This is reliable because the check runs with every active plugin loaded. But note the limit honestly in the tool output — a plugin that registers its callback conditionally, or only in admin context, can produce a false orphan. Say so in the response rather than presenting orphans as certain.

### D. Database analysis

`SHOW TABLE STATUS` filtered to the site's table prefix.

`analyze_database` takes no arguments. Returns non-core tables with name, row count, data size, index size, attributed plugin, and confidence. Separate `orphaned` array for tables whose prefix maps to no installed plugin.

Three things that will otherwise be wrong:

- **Core tables are excluded by an explicit allowlist, not by pattern guessing.** Include the multisite core tables, which differ from single-site.
- **`SHOW TABLE STATUS` row counts are estimates for InnoDB, not exact counts.** Label them as approximate in the output. Do not run `COUNT(*)` on large tables to get exact figures.
- **Some plugins create tables that do not use the WordPress table prefix.** Those will be missed entirely. State that limitation in the tool response so a client does not read the list as exhaustive.

### E. No composite scoring

This phase produces three sets of numbers that will feel like they want to be combined into a health score or a performance grade. Do not do it. Report autoload bytes, cron counts, and table sizes as separate facts. The client decides what they mean together.

### F. Update `get_capabilities`

Move all three tools into `available_now`. Document what the confidence levels mean, and document the two stated limitations: conditional-callback false orphans, and non-prefixed tables being invisible.

## Out of scope

No usage analysis. No `get_plugin_details`. No theme attribution. No capability taxonomy. No new external sources.

## Verification checklist

Report back when I can do all of these:

1. Call `analyze_autoload` on the seeded site and see per-plugin attribution with confidence levels present on every row.
2. Read the unattributed bucket as a percentage of total autoloaded bytes, and tell me that percentage. This is the real measure of whether attribution works.
3. Confirm the autoload query returns correct results, and tell me which autoload column values the Local site actually uses.
4. Call `analyze_cron` and see the seeded orphan cron event correctly identified.
5. Call `analyze_database` and see the seeded orphan table correctly identified, with core tables absent from the results.
6. Confirm row counts are labeled approximate.
7. Read the response sizes for all three tools and confirm each is under 20 KB.
8. CI passes on the pull request.

Then deploy to the live site and report what the three tools find there. Seven plugins, ten years of accumulated history, and no seeding. That is the first honest look at what this part of the product actually sees on a real site.
