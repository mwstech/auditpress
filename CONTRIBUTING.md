# Contributing to AuditPress

Thanks for considering it. A few things make contributions land smoothly.

## The easiest useful contribution: prefix overrides

[`includes/data/prefix-overrides.json`](includes/data/prefix-overrides.json) maps plugin slugs to the option and table prefixes they actually use, for the many cases where no algorithm can derive them (Contact Form 7 uses `wpcf7_`; Google's sitemap plugin uses `sm_`). Every entry moves real bytes out of the "unattributed" bucket for every AuditPress user.

To add one:

1. Find the plugin's real prefixes: look at its option names (`wp option list --search="..."`) or table names on a site where it's installed.
2. Add the slug with an array of prefixes, alphabetically ordered, trailing `_` or `-` included:

```json
"some-plugin": ["someplugin_", "sp_"]
```

3. In the PR description, say how you verified the prefixes (which plugin version, what you observed).

Prefer precision over coverage: a wrong prefix silently misattributes data, which is worse than no entry. Generic prefixes (`wp_`, single words like `simple_`) will be declined.

## Adding a vulnerability provider

AuditPress reads vulnerability data from one source, [WPVulnerability](https://www.wpvulnerability.net/). That is a deliberate choice rather than a limitation of the design: a second provider is one file behind an existing seam, and the seam is documented here so a future outage is an afternoon's work rather than a redesign.

A provider is a class implementing [`AuditPress_Vulnerability_Provider_Interface`](includes/enrichment/interface-vulnerability-provider.php) — four methods:

| Method | Returns |
|---|---|
| `name()` | Short source name used in `_meta.sources`. |
| `plugin_findings( $slug_versions )` | Version-matched findings, `supply_chain` entries kept separate, plus `checked`, `unchecked`, `stale`, `unparsed`, `oldest_fetched_at`. |
| `has_vulnerability_map( $slug_versions )` | `slug => true|false|null`. `null` means "could not check" and is rendered as `vulnerability_unknown`, never as "clean". |
| `supply_chain_map( $slug_versions )` | `slug => verdict|'undetermined'|false|null`. Separate from the vulnerability map on purpose. |
| `core_findings( $wp_version )` | Findings for WordPress core, or `null` when unavailable. |

Register it with the `auditpress_vulnerability_provider` filter:

```php
add_filter(
	'auditpress_vulnerability_provider',
	function ( $provider, $manager ) {
		require_once __DIR__ . '/class-my-provider.php';
		return new My_Vulnerability_Provider( $manager );
	},
	10,
	2
);
```

Every tool that reports vulnerabilities — `check_vulnerabilities`, `list_plugins`, `get_plugin_details` — resolves its provider through that filter, so nothing else needs touching. A returned object that does not implement the interface is ignored and the default is used.

Four obligations come with the seam, and a provider that breaks them will produce dishonest responses:

1. **Version matches only.** A slug present in a database is not a finding; an installed version inside an affected range is.
2. **Never guess.** A record you cannot parse counts toward `unparsed`. A bound that is present but unusable is ambiguous, and ambiguous is never "clean".
3. **Name what you could not check.** Slugs go in `unchecked`, and the caller turns that into `partial` or `not_performed`. Silence reads as "checked and clean".
4. **Report scores as published.** No invented severities, no normalization across scoring systems.
5. **Keep supply-chain findings separate, and signed.** A compromised update channel is not a CVE. If your source publishes them, return them in `supply_chain` with a verdict and a `source` field carrying your provider's `name()`, so an entry quoted out of a response keeps its publisher. Mark an entry whose range you cannot resolve as `undetermined` rather than dropping it.

Use `AuditPress_Enrichment_Manager` for HTTP and caching: it provides parallel fetching, the persistent store, escalating backoff, stale-while-unavailable, and the per-source status accounting that populates `_meta.sources`.

## Ground rules for code changes

These are architectural invariants recorded in [docs/DECISIONS.md](docs/DECISIONS.md) — PRs that cross them will be declined regardless of quality:

- **Read-only, always.** No write operation against WordPress state, ever. CI greps for this and fails the build.
- **Facts, not verdicts.** No scores, grades, or recommendation strings in server responses.
- **Honest degradation.** Missing data is reported as missing (`_meta.sources` status objects with reason codes, coverage objects, `null` with a reason) — never silently zero, never guessed. A response that could not be produced does not ship an empty container in its place: when no plugin could be checked, `check_vulnerabilities` returns no `findings` array at all.
- **Attribution confidence is always visible.** Never present a heuristic match as a fact.
- **No Composer runtime dependencies.** Dev tooling only.

## Practicalities

- Branch from `main`, one feature per commit, commit messages in the form `area: what changed`.
- `composer install && composer lint` must pass (PHPCS, WordPress-Extra ruleset, pinned versions).
- PHP 7.4 through 8.4 compatibility; CI lints all four.
- Verify against a real site with `php tests/mcp-client.php <endpoint-url>`; `tests/seed-conditions.sh` builds a messy test site if you need one.

## Reporting security issues

Please do not open public issues for security problems in the endpoint or token handling. Email the address on the plugin page instead.
