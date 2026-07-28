# CCD Prompt: AuditPress Phase 8.5

Pre-submission audit. No new features, no new behavior.

Paste everything below the line into CCD.

---

Phase 8 is merged. The plugin is functionally complete, hardened, and packaged. This phase is the last gate before submission.

Three things changed in the wordpress.org process that this phase must account for:

- **Plugin Check is now a blocking gate.** New submissions run through it automatically, and an error-level finding in the Plugin Repo category prevents the submission from reaching a human reviewer at all.
- **WordPress.org publishes an official `wp-plugin-directory-guidelines` skill** for AI agents. Load it before starting so you are working from the current guidelines rather than any recollection.
- **Submission volume is 400 to 560 plugins per week**, and April 2026 saw a supply-chain incident involving 22 backdoored plugins across 182,000 sites. Reviewers are stretched and appropriately suspicious. A plugin that exposes site data over an authenticated HTTP endpoint will be read very closely.

Branch: `phase-8-5-audit`. Standing rules from Phase 0 still apply in full.

## A. Load the official guidelines

Load the `wp-plugin-directory-guidelines` skill from the official WordPress repository. If a WPORG compliance skill is also available, load that too. Work from those documents rather than from any prior understanding of the rules.

Report anything in the current guidelines that this plugin does not satisfy, before fixing anything.

## B. Plugin Check

Install Plugin Check on the Local site. Run it against AuditPress via WP-CLI:

```
wp plugin check auditpress
```

Run every category, not only Plugin Repo.

Fix every error. Fix every warning, or record in `docs/DECISIONS.md` precisely why a specific warning is a false positive and must stand. Do not silence anything without a written reason.

Then build the distributable zip exactly as it will be submitted, and run Plugin Check against the extracted zip rather than the working directory. The zip excludes `tests`, `docs`, `.git`, and anything in `.distignore`, so it is a different artifact from what you have been checking all along, and it is the artifact that gets reviewed.

Report the full before and after output.

## C. Reserved slug verification

Verify `auditpress` against the official reserved-slugs list. It is one of the reference documents published by the wordpress.org plugin directory MCP server. If the slug is reserved or conflicts with a trademark, stop and tell me immediately, because it changes the text domain, every prefix, and the repository name.

## D. Dead code removal

Read every file. Remove:

- Functions, methods, and classes never called
- Constants and properties never read
- Commented-out code
- Debug output, `error_log` calls, and `var_dump` remnants
- Imports and dependencies no longer used
- Anything built for a phase whose approach later changed

Report what you removed. If something looks unused but you are not certain, list it for me rather than deleting it.

## E. Security audit, adversarial

Phase 7 checked the plugin against a checklist. This is a different exercise: read the code as someone trying to break it.

Work through at minimum:

- Can any tool argument reach a query, a file path, or a function name unsanitized?
- Can the `limit`, `offset`, `max_posts`, or `slugs` arguments be used to exhaust memory or time out the request?
- Can a crafted slug in `get_plugin_details` or `analyze_usage` escape its intended scope?
- Is the token comparison timing-safe on every path, including the failure paths?
- Can the rate limiter be bypassed through header spoofing, and is the IP source trustworthy behind a proxy or CDN?
- Does any error message, in any failure mode, leak a file path, a database name, a table prefix, or a token fragment?
- What happens on a site with 500 plugins? With a 2 GB options table? With a plugin whose main file is unreadable?
- Does any external API response get trusted without validation? A compromised or spoofed upstream returning hostile JSON must not be able to do anything.

Fix what you find. Report everything, including anything you decided was acceptable and why.

## F. Distribution artifact

Confirm the zip is correct:

- `.distignore` excludes `tests`, `docs`, `.git`, `.github`, `composer.json`, `phpcs.xml.dist`, and `deploy.sh`
- `.wordpress-org` is excluded from the plugin zip, since directory assets live in SVN's assets folder, not in the plugin
- The extracted zip installs and activates cleanly on a fresh WordPress with no other plugins present
- All nine tools work from that fresh install
- Report the zip's size and file count

## G. Fresh-install walkthrough

Install the zip on a clean WordPress with no seeded data and no other plugins. Follow only the readme, as a stranger would.

Report every point where the readme is unclear, a step is missing, or something behaves differently than the readme describes. This is not a code check. It is the first-run experience, and it is what a reviewer will do.

## Out of scope

No new features. No new tools. No changes to tool responses except where the security audit requires them. Do not submit the plugin.

## Verification checklist

Report back when I can read all of these:

1. Your report of anything in the current official guidelines this plugin does not satisfy.
2. Plugin Check output, before and after, run against the extracted zip.
3. Confirmation that `auditpress` is not a reserved slug.
4. The list of everything you removed as dead code, plus anything you flagged but did not remove.
5. Your adversarial security findings, including issues you judged acceptable and why.
6. The zip's size and file count, and confirmation it installs and runs clean on a bare WordPress.
7. Your fresh-install walkthrough notes.
8. CI passes on the pull request.
