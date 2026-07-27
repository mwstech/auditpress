# CCD Prompt: PluginLens Phase 1

Covers what `docs/SPEC.md` calls Phase 1 and Phase 2, merged. The transport work planned for Phase 1 was already completed during Phase 0 to get the connector working, so what remains is the tool registry pattern plus the first real collector.

Paste everything below the line into CCD.

---

Phase 0 is complete and verified. The connector is live in Claude Chat against `https://www.outsourcewebdesign.com` and `get_capabilities` returns correctly over public HTTPS. Token-in-path authentication is confirmed working, so OAuth is permanently off the table. Record that in `docs/DECISIONS.md`.

Branch: `phase-1-inventory`. All development against the Local site. Standing rules from Phase 0 still apply in full.

## Scope

Build the inventory collector and the first real tool. No outbound HTTP calls in this phase. Everything comes from WordPress itself.

### A. Tool registry

Formalize whatever pattern currently registers `get_capabilities` into a proper registry in `class-tool-registry.php`. Adding a tool should mean dropping one file into `includes/mcp/tools/` and nothing else. Each tool declares its own name, description, and JSON Schema for arguments.

The tool description matters more than it looks. It is the only thing the AI client reads when deciding whether to call a tool, so write each one as a plain statement of what the tool returns, not what it is called.

### B. Inventory collector

`includes/collectors/class-inventory.php`. Reads WordPress directly, no network.

Collect per plugin: plugin file path, derived slug, name, version, author, description, plugin URI, text domain, requires WP, requires PHP, active state, network-active state on multisite, auto-update setting, update availability and target version, disk size, and file count.

Implementation notes that will otherwise cost a day:

- `get_plugins()` needs `wp-admin/includes/plugin.php` loaded manually in a REST context.
- Use `get_mu_plugins()` and `get_dropins()` for the two categories that never appear on the plugins screen.
- Update data comes from `get_site_transient('update_plugins')`. Do not trigger a fresh update check on every call.
- Multisite needs `get_site_option('active_sitewide_plugins')` in addition to the per-site `active_plugins`.
- Slug derivation: the directory name, or the basename minus `.php` for single-file plugins.
- Disk size and file count go in a 24 hour transient. Cap the recursive scan at a file count ceiling and return null past it rather than timing out.

### C. `list_plugins`

Arguments: `status` (`all`|`active`|`inactive`|`mu`|`dropin`, default `all`), `has_update` (bool), `limit` (default 25, max 100), `offset` (default 0), `detail` (bool, default false).

Do not build the `health` filter this phase. It depends on wordpress.org data that arrives in Phase 3.

Compact row, which is the default: `slug`, `name`, `version`, `status`, `update_available`, `latest_version`, `flags[]`.

`flags` this phase covers only what is knowable without network access: `network_active`, `single_file`, `mu_plugin`, `dropin`, `requires_newer_php`, `requires_newer_wp`.

With `detail: true`: adds author, description truncated to 200 characters, plugin URI, text domain, requires WP, requires PHP, auto-update setting, disk size, and file count.

### D. Response size discipline

Build this now. Retrofitting it later means rewriting every tool.

- Every tool response carries a `_meta` object with `total`, `returned`, `truncated`, `sources_unavailable`, and `generated_at`.
- Default `limit` 25, hard maximum 100, enforced server-side regardless of what the client asks for.
- Every free-text field truncated. Descriptions at 200 characters, no exceptions.
- Byte sizes rounded to two significant figures with the unit labeled.
- Never return raw post content, full changelogs, or raw API payloads.

`tests/mcp-client.php` must print the response byte size on every call. Any response over 20 KB gets trimmed before this phase closes. The test site has 42 plugins when seeded, which is the case to measure against.

### E. Update `get_capabilities`

Move `list_plugins` from `planned_tools` to `available_now` and describe what it actually returns.

Keep the `available_now` versus `planned_tools` split permanently. It stops a client inventing capabilities that do not exist yet, and it is more useful than a flat list.

## Out of scope

No outbound HTTP of any kind. No wordpress.org lookups, no vulnerability data, no end-of-life checks. No autoload, cron, database, or usage analysis. No `get_plugin_details`. No health flags requiring network data.

## Verification checklist

Report back when I can do all of these:

1. Run the seed script, then call `list_plugins` through the harness and see all 42 plugins with correct active and inactive counts.
2. See the mu-plugin and the `object-cache.php` drop-in in the results, correctly categorized.
3. See the single-file plugin correctly flagged.
4. Filter by `status: active` and get only active plugins.
5. Call with `detail: true` and see the expanded fields, with every description cut at 200 characters.
6. Read the byte size of both the compact and detailed responses for all 42 plugins, and confirm both are under 20 KB.
7. Request `limit: 500` and get 100, with `truncated: true` in `_meta`.
8. CI passes on the pull request.

Do not deploy to the live site this phase. Local only.
