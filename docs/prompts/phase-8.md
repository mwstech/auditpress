# CCD Prompt: Auditra Phase 8

Packaging for wordpress.org. No logic changes.

Paste everything below the line into CCD.

---

Phase 7 is merged and live. The plugin is functionally complete and hardened. This phase produces everything the wordpress.org plugin directory requires and makes the repository public.

The review queue runs several weeks. A rejection costs more calendar time than any bug in this project has, so the goal is a submission that needs no round trip.

Branch: `phase-8-packaging`. Standing rules from Phase 0 still apply in full.

## Scope

### A. readme.txt

Official wordpress.org format. This file is the plugin's entire storefront and the primary thing a reviewer reads.

Header block: Contributors, Tags, Requires at least, Tested up to, Requires PHP, Stable tag, License, License URI.

Two header fields need real answers rather than guesses:

- **Tested up to** must be the current WordPress release, and the plugin must actually have been tested on it. Check what the current release is, and if the Local site is behind it, update the Local site and re-run all nine tools before writing the number.
- **Tags** are limited to five that matter. Wordpress.org ignores the rest. Choose for how someone would search for this, not for what it is called.

Sections: Description, External services, Installation, Frequently Asked Questions, Screenshots, Changelog, Upgrade Notice.

The description has an unusual job. Most plugins can assume the reader knows what the plugin does from its category. This one has to explain what an MCP server is, why a WordPress site would be one, and what the user actually gets, to an audience that may have never used an AI client. Lead with the outcome — ask questions about your plugins in plain language and get real answers — not with the protocol.

### B. External services disclosure

Mandatory, and the section a reviewer will read most carefully.

For each of the three services, state: the service name and URL, what data is sent, when it is sent, and links to that service's terms and privacy policy.

- api.wordpress.org
- wpvulnerability.net
- endoflife.date

What is sent is plugin slugs and version strings, nothing else. State explicitly that no site content, no user data, and no personal data leaves the site.

### C. Disclose what the endpoint exposes

Separate from the external services section, and just as important.

This plugin exposes site data over an authenticated HTTP endpoint. That is unusual, and a reviewer will scrutinize it. Do not bury it. State plainly:

- The endpoint is disabled on install and does nothing until an administrator enables it and generates a token.
- What data it exposes when enabled.
- That it is read-only and performs no write operation of any kind.
- That the token can be revoked at any time from the settings page.

Being conspicuously upfront about this is the difference between a reviewer reading it as a considered design and reading it as something you hoped they would miss.

### D. Credit and support the data sources

Add a line to the readme crediting WPVulnerability as a free, volunteer-run service, with a link to their donation page. The plugin depends on it and will send it real traffic.

### E. Internationalization

Every user-facing string wrapped in the correct translation function with text domain `auditra`. Text domain matches the slug exactly.

**One specific trap.** WordPress 6.7 introduced a `doing_it_wrong` notice when translation functions are called before `init`. Phase 7 achieved a clean `WP_DEBUG` log across all nine tools. Adding i18n can reintroduce notices if any string is translated too early, particularly in class constructors or during `plugins_loaded`. Re-run the full `WP_DEBUG` pass after i18n is complete and confirm the log is still clean.

Generate the `.pot` file into `languages/`.

### F. Directory assets

Into `.wordpress-org/`:

- `banner-772x250.png` and `banner-1544x500.png`
- `icon-128x128.png` and `icon-256x256.png`
- Screenshots, numbered, matching the readme's Screenshots section

Two screenshots carry the weight. The settings page showing the enable toggle and connection URL, because that is the only interface the plugin has. And an AI client actually answering a question about a site through the connector, because that is the product and no static screenshot of an admin page will convey it.

Tell me what dimensions and content you need and I will produce the artwork. Do not generate placeholder graphics.

### G. Pre-public repository audit

The repository has been private throughout. Before it goes public, verify the entire git history, not just the current tree:

- No tokens, keys, passwords, or SSH details in any commit
- No live site hostnames or paths that should not be public
- No internal notes in `docs/` that were written on the assumption nobody outside would read them

Report anything found rather than quietly rewriting history. Some of it may be fine to leave.

### H. GitHub-facing files

- `README.md` for developers: what it is, how it works, how to connect it to an AI client, how to contribute to `prefix-overrides.json`
- `CONTRIBUTING.md`, with the overrides file called out as the easiest useful contribution
- Confirm `LICENSE` is GPL-2.0-or-later and the plugin header matches

### I. Deployment workflow

`.github/workflows/deploy.yml` using the 10up WordPress plugin deploy action, pushing tagged releases from Git to SVN. It will not run until the plugin is approved and the SVN repository exists, but it should be written and reviewed now rather than under time pressure later.

Repository secrets `SVN_USERNAME` and `SVN_PASSWORD` will be added by me before the first release.

## Out of scope

No logic changes. No new tools. No changes to tool responses. Do not submit to wordpress.org; that is Phase 9 and it is mine to do.

## Verification checklist

Report back when I can do all of these:

1. Read `readme.txt` and understand what the plugin does without knowing what MCP is.
2. Read the external services section and see all three services with data, timing, and policy links.
3. Read the endpoint disclosure and find nothing understated.
4. Confirm the current WordPress version, that the Local site runs it, and that all nine tools were re-tested on it.
5. See a clean `WP_DEBUG` log after i18n, on all nine tools.
6. Read your list of exactly what artwork you need from me.
7. Read your report of the git history audit.
8. Confirm every string is translatable and the `.pot` file generates.
9. CI passes on the pull request.

Do not make the repository public yourself. Report that everything is ready and I will flip it.
