# CCD Prompt: Auditra Phase 8.6

Final audit before submission. Adversarial, not checklist-driven.

Paste everything below the line into CCD.

---

Phase 8.5 audited this plugin against a checklist. This pass is different: read the code as an attacker, and as someone who will have to support it on ten thousand sites you cannot see.

WordPress plugins have a long history of catastrophic vulnerabilities, and this one exposes site data over an HTTP endpoint. That combination deserves one more read before it goes public.

Branch: `phase-8-6-final-audit`. Standing rules from Phase 0 still apply in full.

**Report everything you find, including issues you judge acceptable and why. A finding you dismissed with reasoning is more useful to me than a clean report.**

## A. The two highest-risk paths

Start here. These are specific, named, and likely.

**A1. Stored XSS through the authentication log.**

The auth log records IP and User-Agent from failed authentication attempts and renders them in wp-admin. User-Agent is entirely attacker-controlled, and writing to the log requires no valid token — an attacker only needs to fail.

Verify: every field is escaped on output with the correct function for its context; the User-Agent is length-capped before storage; the stored value cannot break out of its HTML context in any admin rendering path. Then actually test it. Send a failed auth request with a User-Agent containing a script tag and confirm it renders inert.

Also check the IP field. `REMOTE_ADDR` is not attacker-controlled, but confirm nothing else is being read as an IP source.

**A2. PHP object injection through deserialization.**

`_get_cron_array()` returns unserialized data. The options table contains serialized values. Any path where data not fully controlled by this plugin reaches `unserialize()` is a potential remote code execution vector, and this is the classic WordPress vulnerability class.

Audit every `unserialize()` and `maybe_unserialize()` call. For each, state what the data source is and why it is trusted. Where it can be avoided, avoid it. Where it cannot, document why the source is safe.

Also check for PHAR deserialization: any file operation where a path could be influenced by input.

## B. Injection and request-forgery surfaces

- **SSRF.** Plugin slugs are interpolated into outbound URLs for all three external services. Verify a slug can never contain a scheme, host, path traversal, CRLF, or query separator that would redirect or split the request. Confirm slugs are validated against the installed inventory before use, and that validation cannot be bypassed on any code path.
- **SSL verification.** Confirm no outbound request sets `sslverify` to false.
- **Redirect following.** Confirm outbound requests do not follow redirects to arbitrary hosts, or that following them is safe.
- **SQL.** Re-verify every query is prepared, with `esc_like` on every pattern. Pay attention to any query built through string concatenation or chunking, where prepare can be applied to a fragment and still leave the assembled query unsafe.
- **CSRF.** Every state-changing admin action nonce-verified and capability-checked. Confirm nonce verification happens before any side effect, not after.
- **Capability escalation.** Confirm no endpoint or admin action can be reached by a subscriber-level user or an unauthenticated request.

## C. Memory and resource limits

The plugin runs on shared hosting with 128 MB limits and 30-second execution caps.

Measure peak memory for every tool on the seeded site with `memory_get_peak_usage(true)` and report the numbers. Then examine:

- Does any query load an unbounded result set into memory before filtering? The options table read is the obvious candidate — 98 KB on your test site, but what happens with a 50 MB options table?
- Does `SHOW TABLE STATUS` behave on a site with thousands of tables?
- Does the persistent enrichment cache option grow without bound? On a site where plugins are installed and removed repeatedly over years, is anything ever pruned? Report the option's size after a full enrichment run and state the growth ceiling.
- Is any array built by appending in a loop without a cap?
- What happens on a site with 500 plugins, 200,000 posts, and 300 custom tables? Reason it through per tool and state which ones would fail and how.

## D. PHP version compatibility, actually tested

The plugin declares PHP 7.4 minimum. Lint confirms syntax, not function availability. A call to `str_contains`, `str_starts_with`, `array_is_list`, or any other PHP 8 function is syntactically valid on 7.4 and fatals only at runtime.

Grep the entire codebase for functions and language features introduced after 7.4 — named arguments, constructor promotion, match expressions, nullsafe operator, enums, readonly properties, first-class callables. Report anything found.

Then run all nine tools on PHP 7.4 and confirm no fatals. The live test site runs 7.4.33, which makes this directly testable.

## E. Concurrency

Two MCP requests can arrive simultaneously.

- The persistent cache is a single option read, modified, and written. Two concurrent enrichment runs will read-modify-write the same option. Can one lose the other's data, or corrupt the structure?
- The rate-limit counter has the same shape. Can concurrent requests bypass the limit?
- The auth log has the same shape. Can concurrent failures corrupt it or exceed the cap?

State what happens in each case. Last-write-wins may be acceptable — say so if it is, and say what is lost.

## F. Failure modes on hostile or broken input

- A plugin whose main file is unreadable, or has no header, or has a header with a null byte
- A plugin directory that is a symlink pointing outside the plugins folder
- An option whose value is not valid UTF-8
- An external API returning valid JSON with wildly wrong types — a string where an array is expected, a number where a string is expected, deeply nested structures
- An external API returning a 200 with an HTML error page
- A cron array containing a malformed entry
- A site where `$wpdb->prefix` contains a regex or SQL metacharacter

None of these should produce a fatal, a warning, or a malformed response.

## G. WordPress best practices sweep

- No direct file access anywhere
- No output before headers on any path
- No `session_start`, no `date_default_timezone_set`, no `ini_set` that persists
- Nothing hooked to `init` or `plugins_loaded` that runs on ordinary front-end requests without need. Verify the CPT-registration listener gate is genuinely tight and measure its cost on a normal page load.
- No enqueued assets on pages that do not need them
- Text domain loaded correctly for WordPress 7.0
- No deprecated function calls for WordPress 7.0
- All hooks and filters documented

## H. Supply chain

- Confirm zero runtime dependencies, and that `vendor/` is excluded from the built zip
- Confirm nothing is fetched and executed at runtime, ever
- Confirm no analytics, telemetry, phone-home, or upsell code of any kind
- Confirm the three external services are the only outbound requests the plugin makes

## Report format

For each section, report: what you checked, what you found, what you fixed, and what you decided was acceptable with the reasoning. Include the measured numbers for section C.

If you find nothing in a section, say so explicitly rather than omitting it.

## Then

Re-run Plugin Check against the rebuilt zip and confirm zero errors. Confirm CI passes. Do not submit.
