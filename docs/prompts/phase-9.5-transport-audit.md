# CCD Prompt: Auditra Phase 9.5

Targeted transport audit. Only the code added since Phase 8.7.

Paste everything below the line into CCD.

---

Phase 9 and the Origin work added new code that parses attacker-controlled input **before authentication runs**. That code has never been through an adversarial pass. The collectors, enrichment, attribution, and memory work are unchanged since Phase 8.7 and are out of scope here.

Branch: `phase-9-5-transport-audit`. Standing rules from Phase 0 still apply in full.

**Report everything, including what you checked and found safe. A finding you dismissed with reasoning is more useful than a clean report.**

## Scope: the pre-auth surface

Everything that runs before the token is verified is the most exposed code in this plugin. Enumerate it explicitly first — list every function that executes on an unauthenticated request, in order — then audit each one.

### A. Origin parsing

`parse_url` on hostile input is a classic vulnerability source. Test, do not reason:

- `https://evil.com@www.outsourcewebdesign.com` — embedded credentials, where naive parsing reads the wrong host
- `https://www.outsourcewebdesign.com.evil.com` — suffix attack
- `https://www.outsourcewebdesign.com.` — trailing dot, which DNS treats as equivalent
- `HTTPS://WWW.OUTSOURCEWEBDESIGN.COM` — case variance in scheme and host
- Punycode and unicode homographs of the site's own domain
- `https://www.outsourcewebdesign.com:8443` — non-default port
- `//www.outsourcewebdesign.com` — scheme-relative
- A 100,000-character Origin header
- Null bytes, CRLF, and raw newlines inside the header
- Malformed strings where `parse_url` returns `false` or omits components
- An Origin that is valid but has a path, query, or fragment appended

For each: what the code does, and whether it is right. State whether host comparison is case-insensitive and whether the trailing-dot form is handled.

Also confirm what happens when `home_url()` itself is unusual — a subdirectory install, a non-standard port, or a site where `home_url()` and the actual request host differ, as behind a reverse proxy.

### B. The base64 header sentinel

`Mcp-Name` may arrive base64-encoded in the `=?base64?…?=` form, and the plugin decodes it.

- Is there a length cap **before** the decode rather than after? Decoding a 10 MB header to compare against a short tool name is a memory problem regardless of what the comparison then does.
- What happens on malformed base64, on valid base64 that decodes to invalid UTF-8, on base64 containing null bytes, and on a nested or repeated sentinel?
- Is `strict` mode used on `base64_decode`?
- Can the decoded value influence anything other than an equality comparison?

Measure peak memory for a request carrying the largest header the web server will accept.

### C. Header and body comparison

- What if `Mcp-Method` or `Mcp-Name` arrives multiple times?
- What if the JSON-RPC body's `method` or `params.name` is an array, an object, a number, or null rather than a string? Type juggling in a comparison is where this goes wrong.
- Is the comparison type-safe, using strict equality rather than loose?
- Does the mismatch error response echo any attacker-supplied value back? It must not.

### D. Version negotiation from `_meta`

The protocol version now routes requests to different code paths, and it arrives inside attacker-controlled JSON.

- What if `io.modelcontextprotocol/protocolVersion` is an array, an object, a boolean, or a 10,000-character string?
- Is the value ever used to index an array, build a string, or select a callable? If so, that is the finding.
- What does the unsupported-version error return, and does it echo the submitted value?
- Can a crafted `_meta` block reach any code path that a well-formed request cannot?

### E. Order of operations and resource cost

- Confirm the exact order: Origin, then headers, then rate limit, then auth. State it.
- Is every pre-auth check cheap? Anything that allocates, parses deeply, or hits the database before the rate limiter is a denial-of-service vector against an unauthenticated request.
- Does `server/discover` require a valid token? If it is reachable unauthenticated, state exactly what it discloses and whether that is acceptable.
- Does any new code path bypass the rate limiter or reach a tool without authentication?

### F. Information disclosure in new responses

- Does `serverInfo` in `_meta` disclose anything beyond name and version?
- Do any of the new error shapes — `-32020`, `-32022`, the 403 — leak a file path, a site path, a table prefix, an internal hostname, or a token fragment?
- Does the unsupported-version error's list of supported versions matter? Probably not, but state the reasoning.

### G. Regression checks

- Run all nine tools with `WP_DEBUG` and `WP_DEBUG_LOG` on, in both protocol eras, and confirm the log is still clean. This was verified before Phase 9 and the new paths could have broken it.
- Confirm the Phase 8.6 hostile-input findings still hold — the `version_compare` scalar guard in particular.
- Confirm peak memory has not regressed from the Phase 8.7 numbers.
- Confirm the CI read-only grep gate still passes and no write operation entered the codebase.

## Report format

For each section: what you checked, what you found, what you fixed, what you accepted and why. Include the memory measurements from section B and E.

If a section is clean, say so explicitly rather than omitting it.

## Then

Re-run both full harness sequences on the live site. Plugin Check on the rebuilt zip. CI green. Rebuild, redeploy, and give me the path and SHA-256.

Do not submit.
