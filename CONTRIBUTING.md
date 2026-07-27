# Contributing to PluginLens

Thanks for considering it. A few things make contributions land smoothly.

## The easiest useful contribution: prefix overrides

[`includes/data/prefix-overrides.json`](includes/data/prefix-overrides.json) maps plugin slugs to the option and table prefixes they actually use, for the many cases where no algorithm can derive them (Contact Form 7 uses `wpcf7_`; Google's sitemap plugin uses `sm_`). Every entry moves real bytes out of the "unattributed" bucket for every PluginLens user.

To add one:

1. Find the plugin's real prefixes: look at its option names (`wp option list --search="..."`) or table names on a site where it's installed.
2. Add the slug with an array of prefixes, alphabetically ordered, trailing `_` or `-` included:

```json
"some-plugin": ["someplugin_", "sp_"]
```

3. In the PR description, say how you verified the prefixes (which plugin version, what you observed).

Prefer precision over coverage: a wrong prefix silently misattributes data, which is worse than no entry. Generic prefixes (`wp_`, single words like `simple_`) will be declined.

## Ground rules for code changes

These are architectural invariants recorded in [docs/DECISIONS.md](docs/DECISIONS.md) — PRs that cross them will be declined regardless of quality:

- **Read-only, always.** No write operation against WordPress state, ever. CI greps for this and fails the build.
- **Facts, not verdicts.** No scores, grades, or recommendation strings in server responses.
- **Honest degradation.** Missing data is reported as missing (`sources_unavailable`, coverage objects, `null` with a reason) — never silently zero, never guessed.
- **Attribution confidence is always visible.** Never present a heuristic match as a fact.
- **No Composer runtime dependencies.** Dev tooling only.

## Practicalities

- Branch from `main`, one feature per commit, commit messages in the form `area: what changed`.
- `composer install && composer lint` must pass (PHPCS, WordPress-Extra ruleset, pinned versions).
- PHP 7.4 through 8.4 compatibility; CI lints all four.
- Verify against a real site with `php tests/mcp-client.php <endpoint-url>`; `tests/seed-conditions.sh` builds a messy test site if you need one.

## Reporting security issues

Please do not open public issues for security problems in the endpoint or token handling. Email the address on the plugin page instead.
