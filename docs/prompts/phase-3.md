# CCD Prompt: AuditPress Phase 3

Vulnerability matching, and the persistent cache that Phase 4 will depend on.

Paste everything below the line into CCD.

---

Phase 2 is merged and deployed. The enrichment interface is proven end to end, including graceful degradation and cache reuse under blocked network conditions.

Branch: `phase-3-vulnerabilities`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## A design change to make first

The object-cache finding in Phase 2 is correct for endoflife.date, where three or four calls with bounded timeouts degrade acceptably. It stops being acceptable at scale. Vulnerability lookups run one request per installed plugin, which on the seeded site is 45, and the wordpress.org client in Phase 4 will be the same. If transients fail to persist, every MCP request fires 45 outbound calls to a free community-run API. That is slow for the user and abusive to the service.

So bulk enrichment does not use transients.

Add a persistent cache backend to the enrichment manager: a single non-autoloaded option storing a keyed map of slug to `{data, fetched_at}`, with expiry checked in PHP rather than delegated to a transient TTL. Options always hit the database, so persistence is guaranteed regardless of object cache state.

Keep transients for the endoflife client. Three or four keys with a seven-day TTL is exactly what transients are for. Use the persistent backend only where the cost of a cache miss scales with plugin count.

Record this in `docs/DECISIONS.md` with the reasoning, because the split will look arbitrary to anyone reading the code later.

## Scope

### A. WPVulnerability client

`includes/enrichment/class-wpvulnerability-client.php`, implementing the existing enrichment interface, using the new persistent cache backend.

Endpoints:

```
https://www.wpvulnerability.net/plugin/{slug}/
https://www.wpvulnerability.net/core/{version}/
```

Keyless. **Verify the actual response shape against the live API before writing the parser.** The spec describes it from memory and it may have changed. Fetch a slug with known vulnerabilities, read the real structure, then build against what you find. If it differs materially from `docs/SPEC.md` section 8.2, stop and tell me rather than adapting silently.

Cache 12 hours.

Politeness requirements, because this is a free service run by volunteers and the plugin will eventually be installed by strangers:

- Send a `User-Agent` identifying AuditPress and its version.
- Parallel fetches capped at a sensible concurrency, not 45 simultaneous connections.
- Same short timeouts as the endoflife client, with the same silent degradation.
- Never re-fetch a slug already in cache and unexpired.

### B. Version matching

This is the part that matters most and the part easiest to get quietly wrong.

A slug appearing in the vulnerability database is **not** a finding. Only an installed version falling inside an affected range is a finding. Match with `version_compare` against the operator fields the API returns, handling inclusive and exclusive bounds correctly, and handling ranges with no upper bound.

Where a fixed-in version is available, report it. Where the API gives a CVSS score and severity, report them as returned. **Do not invent severity labels, do not compute your own risk score, and do not map scores to words the API did not supply.**

Where a vulnerability record is ambiguous or unparseable, exclude it from findings and count it in a separate `unparsed` figure rather than guessing.

### C. `check_vulnerabilities`

Arguments: `slugs` (array, optional; all installed plugins if omitted), `include_core` (bool, default true).

Per finding: affected slug, installed version, source, CVE identifier where present, CVSS score and severity as returned, affected version range, fixed-in version where known.

Truncate any free-text description hard. Vulnerability records can carry long advisory text and this tool must not become the response that blows the context window. A short identifier and a version range is what the client reasons over.

Include in `_meta`: how many plugins were checked, how many findings, and how many records were unparsed.

### D. `has_vulnerability` flag on `list_plugins`

Add it to the existing compact-row `flags` array. A boolean flag only. No counts, no scores, no severity in the compact row. Anyone who wants detail calls `check_vulnerabilities`.

This is the flags-not-numbers rule from Phase 1 applied to its first real case.

Because this makes `list_plugins` dependent on network data for the first time, it must still return correctly with the flag simply absent when the source is unavailable, with `sources_unavailable` populated.

### E. Update `get_capabilities`

Move `check_vulnerabilities` into `available_now` with an accurate description.

## Out of scope

No wordpress.org lookups. No autoload, cron, database, or usage analysis. No `get_plugin_details`. No theme vulnerability checking, even though the API supports it.

## Verification checklist

Report back when I can do all of these:

1. Call `check_vulnerabilities` on the seeded Local site and see findings that are genuinely version-matched, not slug-matched.
2. See a plugin whose slug is in the vulnerability database but whose installed version is outside every affected range, and confirm it is correctly reported as clean.
3. Block outbound HTTP, call `list_plugins`, and confirm it returns normally with no `has_vulnerability` flags and `sources_unavailable` populated.
4. Call twice and confirm the second call reads from the persistent option cache, with the object-cache drop-in fixture in place, proving the transient problem is genuinely solved rather than avoided.
5. Read the response size for a full-site vulnerability check on 45 plugins and confirm it is under 20 KB.
6. See the `unparsed` count in `_meta` and know it is honest rather than silently zero.
7. CI passes on the pull request.

Then deploy to the live site. Solid Security 9.3.6 is a full major version behind, AIOSEO is at 4.7.9 against 4.9.10, and WordPress core is 6.7.5. If any of those carry known published vulnerabilities, this is the phase where the tool becomes genuinely useful for client work rather than merely informative.
