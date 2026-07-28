# CCD Prompt: AuditPress Phase 8.7

Vulnerability path verification and degradation architecture. The last phase before submission.

Paste everything below the line into CCD.

---

WPVulnerability's maintainer has fixed the plugin route. The cause was a reserved-keyword collision on a database field.

This matters more than a bug fix. **The vulnerability path has never run against real data.** Every test through Phases 3 to 8.6 used empty responses or mocks, because populated responses were returning 500s the entire time this plugin was built. Version matching, response size, memory, timing, and parser robustness on real payloads are all unverified.

That outage also exposed an architectural gap this phase closes.

Branch: `phase-8-7-vulnerability-verification`. Standing rules from Phase 0 still apply in full.

## Part 1: Ground truth verification

### A. Reset state

Clear the enrichment cache and reset backoff state for the wpvulnerability source. Consecutive failures may have escalated it to the 24-hour interval, which would produce a false negative that looks like a bug.

### B. Confirm upstream recovery

Curl the plugin endpoint directly for `google-sitemap-generator` and one previously-working slug. Report status, response size, and time for each. Confirm populated responses now return 200.

### C. Run the answer key

Run `check_vulnerabilities` on the live site and compare against `tests/known-vulnerabilities.md`.

Report specifically:

- Does coverage come back complete, 7 of 7 checked?
- Does CVE-2025-64632 surface for `google-sitemap-generator` 4.1.21?
- Do the AIOSEO 4.7.9 ranges match?
- Are the four expected-clean plugins reported clean?
- What is the `unparsed` count?

**Any mismatch is a bug with a known correct answer. Report it. Do not adjust the answer key to match the output.**

### D. Verify the matching engine properly

The answer key confirms behavior on seven plugins. The engine needs harder cases.

Find a plugin in the vulnerability database with several historical advisories across different version ranges. Install it at a version deliberately chosen to sit outside every affected range, and confirm it reports clean. Then install a version inside exactly one range and confirm only that finding surfaces.

Also verify range boundary handling: a version equal to a range's lower bound, equal to its upper bound, and equal to the fixed-in version. Inclusive versus exclusive bounds are where this kind of code is usually wrong, and being wrong in the permissive direction means flagging safe sites as vulnerable.

### E. Real-data measurements

All previously measured against empty responses. Re-measure and report:

- Peak memory for `check_vulnerabilities` on the 45-plugin seeded site with a warm cache of real data
- Response size for a full-estate check with real findings present
- Persistent cache size with real data across 45 plugins
- Wall-clock time for a cold-cache full-estate check
- Time for a single populated response, and whether it approaches the current 5-second ceiling

**The timeouts were tuned against 0.1-second empty responses.** If a healthy populated response takes 3 or 4 seconds, the current ceiling will start abandoning valid requests, and the failure will look exactly like the outage just resolved. Raise it if the numbers say so.

### F. Parser against the published schema

WPVulnerability publishes an OpenAPI 3.1 specification at docs.wpvulnerability.com. Validate the parser against it. Report any field in the schema not handled, any assumption about optionality the schema contradicts, and any type the parser would mishandle.

## Part 2: Degradation architecture

The outage proved the failure path works — nothing crashed, coverage was honest, a client correctly identified the gap. It also exposed three things.

### G. Four response states, and no findings array when nothing was checked

This is the most important change in the phase.

An empty `findings` array is **shaped like an answer**. Even with coverage metadata attached, the structure says "I looked and found nothing." Metadata can be overlooked; structure cannot. When no plugin could be checked, the tool must not produce a findings array at all.

Rework `check_vulnerabilities` into four explicit states:

1. **complete** — every plugin checked against fresh data. Findings returned normally.
2. **complete_stale** — every plugin checked, but from cached data past its TTL. Findings returned, labeled with the age of the data and when it was fetched.
3. **partial** — some plugins checked, some not. Findings returned, explicitly scoped to the checked subset, with every unchecked plugin named.
4. **not_performed** — no plugin could be checked. **No findings array in the response at all.** Instead: the state, the reason the check could not run, when data was last successfully fetched, and when a retry will next be attempted.

State 4 is the one that matters. There is no way to misread a response that contains no findings field.

Apply the same state model to any other tool that surfaces network-dependent data, including the `has_vulnerability` flag on `list_plugins`. If vulnerability data is unavailable, the flag must be absent rather than false.

### H. Serve stale data when the upstream is unavailable

Cached vulnerability data currently expires after 12 hours. During a multi-week outage the cache empties and the tool has nothing. But six-day-old vulnerability data is far more useful than none, and it is what makes state 2 above possible.

Implement stale-while-unavailable: when a source is unreachable and cached data exists past its TTL, serve the stale data rather than nothing.

Rules:

- Stale data is always explicitly labeled with its age and fetch timestamp. Never silently.
- A maximum staleness beyond which data is discarded and the tool falls to state 4 instead. Choose a defensible figure and record the reasoning — vulnerability data ages badly, since a CVE published yesterday will not appear in a two-week-old cache.
- Fresh data always wins. Stale is a fallback, never a cache-hit shortcut.
- Applies to the wordpress.org and endoflife sources too, where staleness matters far less.

### I. Reason codes on unavailable sources

`sources_unavailable: ["wpvulnerability"]` says something failed but not what. These are different situations with different user responses:

- The site has no outbound HTTP access — the site owner must fix their firewall
- The upstream returned an error — wait, nothing to do
- The upstream timed out — possibly transient
- Backoff is active after repeated failures — the retry time is known
- The response was unparseable — likely a schema change worth reporting

Replace the flat array with a structured object per source: current status, reason code, timestamp of last successful fetch, and next retry time where a backoff window is active. This is what states 3 and 4 report as their reason.

Facts only. No advice, no severity.

### J. Document the provider seam

Do not build a second vulnerability provider. One outage with a known cause and a responsive maintainer is not a reliability pattern.

Do make the seam explicit: confirm a second provider could be added as one file implementing the existing interface, expose provider selection through a filter, and document in `CONTRIBUTING.md` what adding one would involve. Cheap now, and it means a future outage is an afternoon rather than a redesign.

## Part 3: Re-verify

Everything in Phase 8.6 that touched this path was verified against empty responses. Redo, with real data flowing:

- Hostile input handling, particularly the `version_compare()` scalar guard that caused the remotely-triggerable fatal
- Memory ceilings
- Concurrency on the cache option, now carrying substantially more data
- Response size discipline

## Verification checklist

Report back when I can read all of these:

1. The answer key comparison, item by item, with any mismatch called out.
2. Boundary-case results for the matching engine, including inclusive versus exclusive bounds.
3. All real-data measurements from section E, with a statement on whether timeouts need raising.
4. Parser findings against the published OpenAPI schema.
5. All four response states demonstrated, with the actual payload for each. State 4 must visibly contain no findings array.
6. A demonstration of stale-while-unavailable: block the upstream with a warm cache and show the tool serving labeled stale data.
7. The source status object with reason codes, shown for at least three distinct failure reasons.
8. Confirmation that `has_vulnerability` is absent rather than false on `list_plugins` when the source is unavailable.
9. Confirmation that the Phase 8.6 findings still hold with real data.
10. Plugin Check zero errors on the rebuilt zip, CI green.

Then rebuild the zip and give me the path and SHA-256. Do not submit.
