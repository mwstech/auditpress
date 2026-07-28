# AuditPress

A WordPress plugin that turns the site it is installed on into a read-only [MCP](https://modelcontextprotocol.io/) server, so any MCP-capable AI client (Claude custom connectors, and others) can inspect and reason about that site's plugin estate in plain language.

Ask "which of my plugins are vulnerable?", "what's bloating my options table?", or "what did deleted plugins leave behind?" — and get answers grounded in the actual state of the site.

## How it works

Three layers inside one plugin, one REST route, no runtime dependencies:

- **Transport** — `POST /wp-json/auditpress/v1/mcp/{token}` speaking MCP over JSON-RPC 2.0 (protocol `2025-11-25`, plain JSON responses, stateless). Token-in-path auth behind a swappable interface, rate limiting, failed-auth logging.
- **Collectors** — read WordPress directly: inventory, site context, autoloaded options, cron, database tables, content-feature usage, and a three-tier attribution engine that maps options/tables/hooks back to owning plugins with explicit confidence levels (`high` = curated, `medium` = derived, or visibly unattributed).
- **Enrichment** — wordpress.org, WPVulnerability, and endoflife.date, all keyless, all cached, all degrading silently with per-source coverage reporting and progressive backoff (15 min → 24 h) on failures.

Nine tools; every response carries `_meta` (totals, truncation, sources unavailable, timestamps) and stays within a 20 KB budget. The server reports **facts, never verdicts** — scoring and advice are deliberately absent, because that's the AI client's job. The full design record lives in [docs/DECISIONS.md](docs/DECISIONS.md).

## Connect it to an AI client

1. **Tools → AuditPress** in wp-admin: enable the endpoint, generate a token, copy the connection URL.
2. In Claude: **Settings → Connectors → Add custom connector**, paste the URL (must be `https://`).
3. Ask questions. Start with "what can you tell me about this site's plugins?"

The site must be publicly reachable over HTTPS with pretty permalinks enabled. Note: most MCP clients cache the tool list — after updating the plugin, disconnect and reconnect the connector to pick up new tools.

## Contributing

The easiest genuinely useful contribution is a pull request against
[`includes/data/prefix-overrides.json`](includes/data/prefix-overrides.json) — the curated map from plugin slugs to the option/table prefixes they actually use (Contact Form 7 → `wpcf7_`). Every entry improves attribution accuracy for every user. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Development

```
composer install   # dev-only: PHPCS + WordPress Coding Standards (pinned)
composer lint
php tests/mcp-client.php https://example.com/wp-json/auditpress/v1/mcp/{token}
```

`tests/mcp-client.php` is the CLI harness that exercises the endpoint without an AI client in the loop; `tests/seed-conditions.sh` builds a deliberately messy test site (and `teardown-conditions.sh` reverses it exactly). CI runs a PHP lint matrix (7.4–8.4), PHPCS, and a grep gate that fails the build if any write operation ever appears in the plugin code.

## License

GPL-2.0-or-later.
