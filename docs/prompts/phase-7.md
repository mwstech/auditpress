# CCD Prompt: AuditPress Phase 7

Hardening. No new features.

Paste everything below the line into CCD.

---

Phase 6 is merged and live. The v1 tool catalog is complete: nine tools, three external sources, all collectors built. Nothing after this adds capability.

This phase makes the plugin safe to hand to strangers. Until now the only install has been on sites you control, with a token you generated, called by a client you trust. From here it has to survive being installed by people you will never meet, on sites you cannot see, and reviewed by wordpress.org's plugin team who will read every line looking for exactly the things this phase addresses.

Branch: `phase-7-hardening`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## Scope

### A. Rate limiting

Transient-backed counter per IP address. Default 60 requests per minute, filterable so a site owner can adjust it. Exceeding the limit returns HTTP 429 with a plain JSON body.

Apply to the MCP endpoint only.

### B. Authentication log

Record the last 50 failed authentication attempts: timestamp, IP address, and user agent. Display on the settings page, newest first. Hard cap the stored count so it cannot grow unbounded, and clear it on token regeneration.

**Never log a token value, not even a partial one, not even a hash.** Record only that an attempt failed.

### C. Progressive backoff for failing external sources

Recorded earlier as a deferred decision, now due.

The current negative cache is 15 minutes. That is correct for one site. At wordpress.org scale it means thousands of installations retrying a broken free service every fifteen minutes indefinitely, which is exactly the behavior that gets a plugin blocked at the network level and damages a volunteer-run project.

Implement escalating backoff on consecutive failures per source: 15 minutes, then 1 hour, then 6 hours, then 24 hours as a ceiling. Reset to zero on any successful response. Store the failure count alongside the negative cache entry.

The WPVulnerability outage during Phase 3 is the exact scenario this prevents.

### D. Timeout review

Current timeouts were tuned against empty WPVulnerability responses returning in 0.1 seconds. Populated responses do more backend work and will legitimately be slower once that service recovers. A plugin with fifteen historical CVEs is a heavier query than one with none.

Review whether the current 3 second connect and 5 second total ceilings will start abandoning valid responses when the service is healthy. Make the total timeout filterable. Err toward generous, since silent degradation already protects the endpoint from hanging.

### E. Security review against the wordpress.org checklist

Go through the whole codebase and verify, fixing anything that fails:

- Every file begins with a direct-access guard.
- Every user-facing output escaped with the correct function for its context.
- Every input sanitized on the way in.
- Every form action nonce-verified.
- Every admin action capability-checked: `manage_options`, or `manage_network_options` on multisite.
- Every database query through `$wpdb->prepare`, with `esc_like` on any pattern.
- No `eval`, no `base64`, no obfuscation of any kind.
- Every function, class, constant, option, transient, and hook prefixed.
- No PHP errors, notices, or warnings with `WP_DEBUG` enabled, on any tool, on both the Local and live sites.

Report anything you fix rather than fixing it silently.

### F. Token storage decision

The token is currently stored in a plain option, which is how WordPress plugins conventionally store API credentials. That is defensible, but it needs to be a documented decision rather than an unexamined default, because a reviewer will ask.

Write it up in `docs/DECISIONS.md`: what is stored, why plaintext is acceptable for a credential the site owner generates and owns, what the revoke path is, and what an attacker with database access could do with it. If the conclusion is that hashing with single-display is worth the usability cost, say so and change it. Either answer is fine. An undocumented one is not.

### G. Uninstall cleanup

`uninstall.php` must remove every option, transient, and cached entry the plugin created, including the persistent enrichment cache and the auth log. Verify by installing, running every tool, uninstalling, and confirming nothing remains in the options table.

### H. Settings page polish

Default WordPress admin markup only, no custom design.

- The connection URL should be genuinely easy to copy, since that is the one thing every user must do.
- The enable toggle should state plainly what enabling exposes.
- The revoke action should warn that existing connections will break.
- Show which external services the plugin contacts and what is sent to each: plugin slugs and version strings only, no site content and no personal data.

### I. Multisite

Verify the settings page, capability checks, and endpoint behave correctly on a multisite network. If multisite is not going to be properly supported in v1, say so explicitly in the plugin header and readme rather than half-supporting it.

## Out of scope

No new tools. No new external sources. No changes to tool responses except where the security review requires them. No readme.txt or translations, which are Phase 8.

## Verification checklist

Report back when I can do all of these:

1. Exceed the rate limit and receive a 429, then confirm normal service resumes after the window.
2. Fail authentication several times and see the attempts logged with no token material anywhere in the record.
3. Simulate a persistently failing external source and confirm backoff escalates through all four intervals and resets on success.
4. Run every tool with `WP_DEBUG` and `WP_DEBUG_LOG` enabled and see a clean log on both Local and live.
5. Read your report of everything the security review changed.
6. Install, run every tool, uninstall, and confirm the options table is clean.
7. Read the token storage decision and agree or disagree with it.
8. See a clear statement of multisite support status.
9. CI passes on the pull request.

Then deploy to the live site and confirm nothing regressed.
