# CCD Prompt: PluginLens Phase 6

Usage detection and the deep-dive tool. The last feature phase.

Paste everything below the line into CCD.

---

Phase 5 is merged and live. The live test confirmed the model reconstructs a site's deletion history from the three analyzers unprompted, so no additional reporting structure is being added. Record that as a decision, matching the reasoning behind decision 20 on the capability taxonomy: the server supplies facts, the client supplies the connections.

Branch: `phase-6-usage`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## The honesty problem in this phase

This phase answers "does anything on this site actually use this plugin." That question is genuinely useful and genuinely dangerous, because the obvious reading of a zero result is "safe to delete," and that reading is often wrong.

A plugin can register no shortcodes, no blocks, and no post types and still be essential. AIOSEO on the live site does exactly that: it works through hooks, filters, and admin screens, and would show zero content usage while being load-bearing for the entire site's SEO.

So the flag must be narrowly and literally defined, and its name must not imply more than it measures:

- `zero_content_usage` — the plugin registers shortcodes, blocks, or post types, and none of them appear anywhere in post content.
- A plugin registering **no** content features at all gets no flag. It is not unused. It is simply not measurable this way, and the response must say so rather than defaulting to zero.

State plainly in the tool response and in `get_capabilities`: this measures content usage only. It cannot see functionality delivered through hooks, filters, admin screens, REST endpoints, or template code.

## A second limitation to state

Usage counting scans `post_content`. Shortcodes can also live in widgets, options, post meta, template files, and theme code. A shortcode used in a theme template will count as zero occurrences while being on every page of the site.

Say this in the response. A client must not read zero occurrences as proof of absence.

## Scope

### A. Usage collector

`includes/collectors/class-usage.php`. No network calls.

**Shortcodes.** The global `$shortcode_tags` array holds every registered tag and its callback. Use `ReflectionFunction` or `ReflectionMethod` to get the callback's declaring file, then match that path against plugin directories. This gives accurate attribution, unlike prefix matching, and it is the same reflection approach that will work for closures and class methods alike. Handle callbacks that cannot be reflected without fataling.

**Blocks.** `WP_Block_Type_Registry::get_instance()->get_all_registered()`. Attribute by block namespace, which is a weaker signal than reflection. Record the confidence accordingly.

**Post types and taxonomies.** Hook `registered_post_type` and `registered_taxonomy` during `plugins_loaded` and capture the calling file with `debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS )`. Gate the listener behind a check that the current request URI contains the plugin's REST namespace, so ordinary site traffic pays nothing for this.

**Counting.** For shortcodes, count occurrences in `post_content`. For blocks, count occurrences of the block comment delimiter. For post types, use `wp_count_posts()`. For taxonomies, count terms.

All queries through `$wpdb->prepare` with `$wpdb->esc_like` on any pattern. Exclude revisions and auto-drafts. Batch the counts into as few queries as possible rather than one query per shortcode.

### B. Performance guard

`LIKE '%[tag%'` against `post_content` cannot use an index. On a large site this is slow.

`analyze_usage` takes a `max_posts` argument, default 20000. If the site exceeds it, return counts as `null` with an explicit `skipped_reason` rather than running the scan.

**Never return zero for a check that did not run.** A silent zero here would read as "nothing uses this plugin" and could get a working plugin deleted. That is the single worst failure this product could produce.

### C. `analyze_usage`

Arguments: `slugs` (array, optional), `max_posts` (default 20000).

Per plugin: shortcodes registered with occurrence counts, block namespaces registered with occurrence counts, post types registered with published counts, taxonomies registered with term counts, and the `zero_content_usage` flag where it applies.

Include in the response the two limitation statements above. They belong in the payload, not only in the documentation, because the client reads the payload.

### D. `get_plugin_details`

Arguments: `slugs` (array, required, maximum 5).

Everything known about each named plugin, composed from every collector and enrichment source already built: inventory record, wordpress.org data, vulnerability findings matched to the installed version, autoload contribution, cron events, database tables, and usage counts.

The five-slug cap is what keeps this from blowing the context window. Enforce it server-side. Measure the response for five real plugins and report the size. If it exceeds 20 KB, cut fields rather than raising the cap.

Coverage reporting applies here too: if any source is unavailable, say which, per plugin.

### E. Update `get_capabilities`

Move both tools into `available_now`. Document the `zero_content_usage` threshold precisely, and document both limitations.

## Out of scope

No new external sources. No theme usage analysis. No widget or template scanning, despite being named as a limitation. No write operations.

## Verification checklist

Report back when I can do all of these:

1. Call `analyze_usage` on the seeded site and see the deliberately zero-usage plugin correctly flagged.
2. Confirm a plugin that registers no content features at all is **not** flagged, and that the response explains why rather than reporting zero.
3. Confirm shortcode attribution via reflection correctly identifies the owning plugin, including for a shortcode registered as a class method.
4. Set `max_posts` below the site's post count and confirm counts return as null with a stated reason, never as zero.
5. Call `get_plugin_details` on five real plugins and report the response size.
6. Confirm both limitation statements appear in the `analyze_usage` payload itself.
7. Read the wall-clock time for `analyze_usage` across all plugins on the seeded site.
8. CI passes on the pull request.

Then deploy to the live site. It has ten years of content and seven plugins, several of which work entirely through hooks. That is the real test of whether the zero-usage flag stays honest or starts implying things it cannot know.
