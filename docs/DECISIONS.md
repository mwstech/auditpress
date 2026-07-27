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
