# Known-vulnerability answer key: the live test site

Manual research performed 2026-07-27 against public advisory databases, while the
WPVulnerability `/plugin/` route was down (HTTP 500 for every slug). This is the
expected result set for `check_vulnerabilities` on the project's live test site
once the source recovers. If the tool's output disagrees with this document, one of the two is
wrong and the disagreement is a bug report with a ready-made test case either way.

Installed versions as of the same date. Advisory databases move; treat entries as
stale after a few months.

## Expected findings

### google-sitemap-generator 4.1.21 — MUST be flagged

- **CVE-2025-64632** — missing authorization in Auctollo's XML Sitemap Generator
  for Google, allowing exploitation of incorrectly configured access control.
  Affects all versions **through 4.1.21** (reported to persist in 4.1.22).
  CVSS **5.3 / medium**, published December 2025.
- Installed version 4.1.21 is inside the affected range. This is the live
  exposure on the site and the single most important row in this file.
- Sources: cvefeed.io/vuln/detail/CVE-2025-64632; wordpress.org support thread
  "XML Sitemap Generator for Google has a critical security vulnerability".

### all-in-one-seo-pack 4.7.9 — MUST be flagged (multiple ranges)

Per WPScan and vendor advisories, 4.7.9 sits inside at least three ranges:

- Stored XSS via post meta description and canonical URL — affects **< 4.8.2**.
- Sensitive information exposure via localized script data — affects **< 4.9.7.1**.
- Missing authorization on a REST endpoint allowing Contributor-level users to
  retrieve the site's global AI access token — affects **≤ 4.9.2**, fixed 4.9.3.

All require an authenticated Contributor account; real-world risk depends on
whether the site has untrusted low-privilege users.

## Expected clean at installed versions

| Plugin | Version | Why clean |
|---|---|---|
| better-wp-security (Solid Security) | 9.3.6 | Newest advisory is IP-spoofing DoS affecting 9.3.1, fixed 9.3.2. Being a major version behind (10.x exists) is maintenance debt, not a published vulnerability. |
| redirection | 5.5.2 | Zero present advisories; most recent patched entry is 3.6.2 (2018). |
| akismet | 5.3.7 (inactive) | Zero present advisories; last one at 3.1.4 (2015). |
| really-simple-captcha | 2.4 | No advisories; CleanTalk secure-code certification at exactly 2.4 (June 2026). |
| auditra | 0.1.0 | Not on wp.org; no advisory record exists. Expect no wp.org identity, not "clean". |

## Core

WordPress 6.7.5: the live `/core/6.7.5/` endpoint returned zero vulnerabilities
on 2026-07-27 (verified directly, that route was up). Note 6.7 is past its
endoflife.date EOL (2025-04-15) — an EOL fact, not a CVE.

## Naming trap

The critical authentication bypass widely covered in 2024 ("one of the most
severe flaws in Wordfence's twelve-year history") was in **Really Simple
Security** — a different plugin from Really Simple CAPTCHA, and not installed
on this site. Do not let a scanner or a client conflate the two.

## How to run the comparison

```
php tests/mcp-client.php https://{live-test-site}/wp-json/auditra/v1/mcp/{token} \
  --call check_vulnerabilities
```

Trust the result only when `state` is `complete` and `_meta.sources.wpvulnerability.status`
is `ok`. A `complete_stale` state is a real answer from cached data, labeled with its age;
`partial` and `not_performed` are not answers to this comparison.

## Result of the 2026-07-28 comparison

Run against the live site after the upstream `/plugin/` route recovered, with the
enrichment cache cleared first. Every row above held:

- `state: complete`, 7 of 7 plugins checked, `unchecked_slugs` empty, `unparsed: 0`.
- `google-sitemap-generator` 4.1.21: **CVE-2025-64632** reported, `lt 4.1.23`, fixed in
  4.1.23 — consistent with this file's note that 4.1.22 is still affected. One finding of
  the four advisories on record for that slug.
- `all-in-one-seo-pack` 4.7.9: nine findings covering all three ranges named above
  (`< 4.8.2`, `< 4.9.7.1`, and `≤ 4.9.2` expressed upstream as `lt 4.9.3`), plus six
  advisories published after this file was written.
- The four expected-clean plugins reported clean.
- Core 6.7.5 checked, zero findings.

Cross-checked against an independent reimplementation of the operator semantics run over
the raw API payloads: identical finding sets for all seven slugs.
