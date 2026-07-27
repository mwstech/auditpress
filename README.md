# PluginLens

A WordPress plugin that turns the site it is installed on into a read-only [MCP](https://modelcontextprotocol.io/) server, so any MCP client (Claude, ChatGPT, Cursor) can inspect and reason about that site's plugin estate.

**Status: Phase 0.** The plugin currently proves the transport: a token-authenticated MCP endpoint answering `initialize`, `tools/list`, `tools/call`, and `ping`, with one orientation tool, `get_capabilities`. Collectors and analysis tools arrive in later phases. See [docs/SPEC.md](docs/SPEC.md) for the full build specification.

## Principles

- **Read-only.** Zero write operations against WordPress state, enforced by CI.
- **Disabled by default.** A fresh install exposes nothing until an admin enables the endpoint and generates a token.
- **Facts, not verdicts.** The server reports raw facts; the AI client does the judgment.
- **Self-contained.** No runtime dependencies, no central service, no telemetry.

## Usage

1. Install and activate the plugin.
2. Go to **Tools → PluginLens**, enable the MCP endpoint, and generate a token.
3. Copy the connection URL and add it to your MCP client as a custom connector.

## Development

Dev dependencies (PHPCS with WordPress Coding Standards) install with `composer install`. Run `composer lint` before pushing. The CLI harness `tests/mcp-client.php` exercises the endpoint without an AI client in the loop:

```
php tests/mcp-client.php https://example.com/wp-json/pluginlens/v1/mcp/{token}
```

## License

GPL-2.0-or-later.
