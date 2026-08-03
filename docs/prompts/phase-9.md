# CCD Prompt: Auditra Phase 9

MCP 2026-07-28 support. Dual-spec, backward compatible.

Paste everything below the line into CCD.

---

MCP spec `2026-07-28` was released on 28 July 2026. Read the specification at `https://modelcontextprotocol.io/specification/2026-07-28` and the changelog at `https://modelcontextprotocol.io/specification/2026-07-28/changelog` before writing anything. Do not work from this brief alone — it summarizes from an announcement post, not the spec.

**The good news first: Auditra is already stateless.** Phase 0 chose single JSON responses over SSE, issued no session ID, and implemented no server-initiated requests. The spec's headline change moved toward where this plugin already was. The migration cost described in the announcement, which centers on session identifiers, does not apply here.

What follows is additive. Nothing about the architecture changes.

Branch: `phase-9-mcp-2026-07-28`. Standing rules from Phase 0 still apply in full.

## Non-negotiable: both versions work

Support `2026-07-28` alongside `2025-06-18` and `2025-03-26`. Do not drop the older versions.

Claude's support for the new spec is described as rolling out, and the spec now carries a twelve-month minimum deprecation window. A plugin installed on someone's site must work with whichever client version reaches it. Version negotiation decides which shape to answer in, per request, with no server-side state.

## Scope

### A. Version negotiation

Accept `2026-07-28` and respond in its shape. Continue accepting the two older versions and responding in theirs.

Determine from the spec exactly how a client signals its version now that `initialize` is retired — the announcement shows an `MCP-Protocol-Version` header and a `_meta` block on every request. Confirm which is authoritative and what a server must do when the header and `_meta` disagree, or when neither is present.

State plainly in your report how the server decides which version it is speaking, per request.

### B. `server/discover`

The `initialize`/`initialized` exchange is retired and replaced by an optional `server/discover` RPC.

Implement it. Keep `initialize` and `notifications/initialized` working for older clients — they are not removed, they are simply not used by newer ones.

Read the spec for the exact response shape. Do not assume it mirrors the old `initialize` result.

### C. Header handling

Requests must now carry `Mcp-Method` and `Mcp-Name` headers so gateways can route without parsing the body.

Determine from the spec what a server is required to do with them. At minimum the server must not break when they arrive. Establish whether it must validate them against the JSON-RPC body, and what the correct error is on mismatch — a body and header that disagree is a request-smuggling shape and should not be silently resolved in favor of either.

### D. Cacheable list results

`tools/list` responses now carry `ttlMs` and `cacheScope`.

Implement them. Choose a TTL and record the reasoning: Auditra's tool catalog is fixed at build time and only changes on plugin update, so a long TTL is correct.

This also solves a real problem from this build. Every phase that added a tool required disconnecting and reconnecting the client, because tool lists were cached with no invalidation signal. Verify whether the new cache fields make that unnecessary and update the FAQ entry accordingly.

### E. Client identity in `_meta`

Requests now carry client name and version in `_meta`. Read them where useful. Do not log them by default, do not store them, and do not vary behavior based on which client is calling.

If the spec requires echoing anything back, do that.

### F. Deprecated features

Confirm Auditra uses none of the deprecated features: Roots, Sampling, Logging, the legacy HTTP+SSE transport, `Mcp-Session-Id`. State explicitly in your report that each is absent.

### G. Documentation

Update the readme FAQ, which currently names accepted protocol versions, and `get_capabilities` if it reports them. State that both spec generations are supported and that the server is stateless by design.

## Out of scope

Do not implement MRTR — Auditra makes no server-initiated requests. Do not implement Tasks, MCP Apps, or any other extension. Do not change authentication; token-in-path sits outside the OAuth flow the spec's auth hardening addresses, and OAuth is not being added in 1.0.

## Verification checklist

Report back when I can read all of these:

1. How the server determines protocol version per request, and what it does when signals conflict or are absent.
2. A full request/response cycle at `2026-07-28` through the harness: `server/discover`, `tools/list`, `tools/call`, with the actual payloads.
3. A full cycle at `2025-06-18` proving the old path still works unchanged, including `initialize` and `notifications/initialized`.
4. What the server does with `Mcp-Method` and `Mcp-Name`, including the mismatch case.
5. The `ttlMs` and `cacheScope` values chosen, with reasoning.
6. Confirmation that no deprecated feature is present, named individually.
7. Response sizes and timings at both versions, confirming nothing regressed.
8. Plugin Check zero errors on the rebuilt zip, CI green.

If anything in the spec contradicts this brief, follow the spec and tell me where it differs.

Then rebuild the zip, redeploy to the live site, and give me the path and SHA-256. Do not submit.
