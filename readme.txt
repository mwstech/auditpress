=== PluginLens ===
Contributors: macronimous
Tags: ai, mcp, plugins, audit, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask your AI assistant real questions about your site's plugins — which are vulnerable, abandoned, bloated, or unused — and get answers grounded in facts.

== Description ==

You have questions about your WordPress site that are surprisingly hard to answer. Which of my plugins have known security vulnerabilities *in the version I'm actually running*? Which ones haven't been updated in years? What's slowing down my options table? What did that plugin I deleted in 2019 leave behind in my database? Is anything on this site actually using that shortcode plugin?

PluginLens lets you ask those questions in plain language, in an AI assistant like Claude, and get answers based on the real state of your site — not guesses.

It works by turning your site into a small, read-only [MCP](https://modelcontextprotocol.io/) server. MCP (Model Context Protocol) is the open standard AI assistants use to connect to outside data sources. You enable the endpoint, copy one URL into your AI client as a connector, and from then on the assistant can look up facts about your plugin estate whenever you ask: the full plugin inventory, update status, published vulnerabilities matched against your installed versions, wordpress.org health signals, end-of-life status for your PHP and WordPress versions, autoloaded option weight per plugin, cron schedules and orphaned jobs, leftover database tables, and whether registered shortcodes and blocks actually appear in your content.

PluginLens reports facts and never verdicts. It contains no scoring, no grades, and no advice engine — the analysis is your AI assistant's job, which means the quality of the answers improves as AI models improve, without the plugin changing at all.

**What PluginLens deliberately does not do:** it cannot measure per-plugin runtime cost or front-end asset weight (and says so rather than inventing numbers), and it performs no write operation of any kind.

= The endpoint, stated plainly =

PluginLens exposes information about your site over an authenticated HTTP endpoint. You should understand exactly what that means before enabling it:

* **On install, the endpoint is disabled and inert.** It answers 404 to everything until an administrator explicitly enables it and generates an access token. A fresh install exposes nothing.
* **When enabled, anyone holding the token URL can read:** your plugin list with names, versions, and health flags; WordPress, PHP, and database versions; vulnerability findings matched to your installed versions; autoloaded option names and sizes; cron hook names and schedules; database table names, sizes, and approximate row counts; and shortcode/block usage counts. Treat the connection URL like a password.
* **It never exposes:** post content, user accounts or emails, comments, credentials, salts, or option *values* — only option names and byte sizes.
* **It is structurally read-only.** The codebase contains no plugin-management, database-write, or file-write calls, and our continuous integration fails the build if any is ever introduced. The endpoint cannot change anything on your site, and neither can an AI connected through it.
* **Revocation is one click.** Regenerating the token on the settings page invalidates every existing connection immediately. Disabling the toggle returns the endpoint to 404.
* The endpoint is rate-limited (60 requests per minute per IP by default) and failed authentication attempts are logged for your review on the settings page.

== External services ==

To enrich its answers, PluginLens contacts three public services. In every case the only data transmitted is plugin slugs and version strings. No site content, no URLs (beyond the API hosts), no user data, and no personal data ever leave your site. All three degrade silently: if a service is unreachable, the affected fields are absent and the response says which source was unavailable.

**1. WordPress.org Plugin API** (https://api.wordpress.org/)
Sent: plugin slugs, when you (or your AI client) request plugin health information; cached 24 hours.
Answers: last-updated dates, tested-up-to versions, active install counts, ratings, support activity.
Terms and privacy: https://wordpress.org/about/privacy/

**2. WPVulnerability** (https://www.wpvulnerability.net/)
Sent: plugin slugs and your WordPress core version, when vulnerability data is requested; cached 12 hours.
Answers: published vulnerability records with CVE identifiers, CVSS scores, and affected version ranges.
Privacy: https://www.wpvulnerability.com/privacy/

**3. endoflife.date** (https://endoflife.date/)
Sent: product names only — literally the strings "php", "wordpress", "mysql", or "mariadb"; cached 7 days.
Answers: support and end-of-life dates for the versions you run.
It is an open-source community project: https://github.com/endoflife-date/endoflife.date

= Supporting the data sources =

WPVulnerability is a free, volunteer-run service that this plugin (and the whole WordPress security ecosystem) depends on. If PluginLens is useful to you, consider supporting them: https://www.wpvulnerability.com/sponsorship/

== Installation ==

1. Install and activate PluginLens.
2. Go to **Tools → PluginLens**.
3. Enable the MCP endpoint and generate an access token.
4. Copy the connection URL and add it to your AI client as a custom connector (in Claude: Settings → Connectors → Add custom connector).
5. Ask your assistant something real: "Which of my plugins have known vulnerabilities?" or "What did old plugins leave behind in my database?"

Your site must be reachable over HTTPS from the internet for a cloud AI client to connect to it. Pretty permalinks (Settings → Permalinks, anything other than Plain) are required.

== Frequently Asked Questions ==

= Do I need to know what MCP is? =

No. You enable a toggle, copy a URL into your AI client, and ask questions. MCP is just the standard that makes the connection work.

= Which AI clients work with it? =

Any MCP client that supports remote connectors over HTTP — Claude (custom connectors), and other MCP-capable clients. The endpoint speaks the standard protocol, not anything vendor-specific.

= Is this safe to run on a production site? =

The endpoint is disabled by default, token-authenticated, rate-limited, and structurally incapable of writing to your site. What it exposes when enabled is described honestly in the section above — read it and decide. Failed authentication attempts are logged on the settings page.

= Does it slow my site down? =

No. It does nothing on normal page loads. Work happens only when your AI client asks a question, and expensive lookups are cached (external data for 12–24 hours, disk scans for a day).

= Why doesn't it give my site a score? =

Because scores would be invented. PluginLens reports measurable facts — versions, dates, sizes, counts, published CVEs — and leaves judgment to the AI reading them, which can weigh your actual context instead of applying a formula.

= Does it work on multisite? =

Not properly in v1. It operates on the individual site it runs on; managing it on a network requires a network administrator. Full network support may come later.

= What happens if one of the external services is down? =

The affected answers degrade gracefully: the response says exactly which source was unavailable and which plugins went unchecked, rather than pretending a partial answer is complete. Failing services are retried with increasing backoff (up to 24 hours) to avoid hammering free community APIs.

== Screenshots ==

1. The settings page: enable toggle, connection URL with copy button, and the disclosure of exactly what is exposed.
2. An AI assistant answering "which of my plugins are vulnerable?" through PluginLens, with version-matched CVE findings.
3. An AI assistant reconstructing a site's deletion history from orphaned tables, orphaned cron jobs, and leftover autoloaded options.

== Changelog ==

= 0.1.0 =
* Initial release: nine read-only MCP tools — get_capabilities, list_plugins, get_site_overview, check_vulnerabilities, analyze_autoload, analyze_cron, analyze_database, analyze_usage, get_plugin_details.
* Enrichment from wordpress.org, WPVulnerability, and endoflife.date with caching, coverage reporting, and progressive backoff.
* Token authentication, rate limiting, failed-authentication log.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
