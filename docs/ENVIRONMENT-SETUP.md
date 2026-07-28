# AuditPress: Environment Setup

Two environments, and the second one only matters once.

| Environment | Job | Frequency |
|---|---|---|
| LocalWP site `auditpress`, a clone of outsourcewebdesign.com | CCD's entire build loop | Constant |
| `https://www.outsourcewebdesign.com` | Proving the Claude Chat connector works over public HTTPS | Once per phase, at most |

The local clone is the important idea here. It carries a decade of genuine accumulated cruft, so it is far better test data than any synthetic seed site, and CCD can break it freely.

---

## 1. LocalWP site

LocalWP runs unlimited sites side by side. The Macronimous 2026 site stays exactly as it is.

1. Create a new site named `auditpress`. PHP 8.2, WordPress default, whichever web server.
2. Enable the trusted SSL certificate so `https://pluginlens.local` resolves cleanly. The plugin should be exercised over HTTPS from the start, matching how it will actually run.
3. Set permalinks to Post name. `/wp-json/auditpress/v1/mcp/{token}` will not resolve on plain permalinks and the token-in-path design breaks entirely.

**Note:** LocalWP's Connect feature only syncs with WP Engine and Flywheel, so there is no live sync to outsourcewebdesign.com. The clone below is a one-time copy, and the two will drift. That is fine. Re-clone if the live site ever changes, which it will not.

---

## 2. Clone the live site into Local

Either route works. Pick whichever is less friction.

**Migration plugin.** Install All-in-One WP Migration or Duplicator on the live site, export, import into the Local site. Simplest if the site is small, which it is.

**Manual.** Export the database over SSH with WP-CLI, rsync `wp-content` down, import into Local, then run a search-replace for the URL:

```
wp search-replace 'https://www.outsourcewebdesign.com' 'https://pluginlens.local' --all-tables
```

After cloning, verify the local site loads and the plugins list matches the live one. That list is the test data.

---

## 3. Where the code lives

Clone the repo directly into the Local site's plugins directory:

```
cd ~/Local\ Sites/auditpress/app/public/wp-content/plugins
git clone https://github.com/mwstech/auditpress.git
```

The repo and the active plugin are then the same directory. No symlink, no sync step, no deploy for local work. CCD edits a file and reloads. Avoid symlinking, which occasionally confuses WordPress path resolution and produces bugs that waste an afternoon.

LocalWP provides WP-CLI through the site shell, so CCD has everything it needs without SSH.

---

## 4. Shipping to the live site

`deploy.sh` in the repo root, one rsync over SSH:

```
rsync -avz --delete \
  --exclude='.git' --exclude='vendor' --exclude='tests' --exclude='docs' \
  ./ pluginlens-test:<plugin-path>/auditpress/
```

Excluding `.git`, `vendor`, `tests`, and `docs` keeps the live site clean and mirrors what eventually ships to wp.org.

Deploy is never automatic. It runs when a phase is complete and the connector needs proving.

---

## 5. Live site access

1. Enable SSH in the host panel. Note host, port, and username.
2. Add your public key rather than using password auth.
3. Add to `~/.ssh/config`:

```
Host pluginlens-test
    HostName <host>
    Port <port>
    User <username>
    IdentityFile ~/.ssh/id_ed25519
```

4. Verify `ssh pluginlens-test` connects and `wp --info` works.

**Never paste SSH credentials, WP admin passwords, or generated tokens into a CCD chat, a commit, or a log line.** CCD references the alias `pluginlens-test` and nothing else.

---

## 6. Pre-flight checks on the live site

Only needed before the first deploy, not before CCD starts. Each one is a day saved if it fails.

**PHP version.** Must be 7.4 or higher. AIOSEO 4.7.9 confirms at least 7.0, no more than that.

**REST API reachable:**

```
curl -i -X POST https://www.outsourcewebdesign.com/wp-json/ \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"ping"}'
```

A 404 or a WordPress REST error object is a pass. The request reached WordPress. A 403, a 406, or an HTML error page means the host firewall blocked it, and that needs a support ticket before the connector will ever work. You have hit exactly this on macronimous.com, where plain curl returns 406 and Bingbot gets 403 on robots.txt, so treat it as likely rather than unlikely.

**Security plugins.** Wordfence, iThemes Security, Sucuri, and All In One WP Security all block REST endpoints by default. Deactivate for the duration or add an exception for the `auditpress/v1` namespace.

**www canonical.** The site canonicalizes to `www.`. Use the `www.` form in `deploy.sh`, in the test harness, and in the Claude Chat connector URL. A redirect on a POST drops the request body, and the failure looks like a protocol bug rather than a URL problem.

---

## 7. Conditions to inject locally

The clone covers most cases naturally. `tests/seed-conditions.sh` adds only what is missing and must be verifiable, with `tests/teardown-conditions.sh` reversing it. Run against the Local clone only, never the live site.

Check what the clone already provides before injecting anything. Likely missing:

- A custom table left behind by a deleted plugin, producing an orphan table
- A scheduled cron event whose plugin has been removed, producing an orphan cron
- Two competing caching plugins active at once
- A plugin whose wp.org listing is closed
- Enough total plugins to exercise response size discipline. If the clone has fewer than 40, install the difference and leave them inactive. Inactive plugins still appear in `get_plugins()` and still need wp.org enrichment, so they stress the context window without affecting runtime.

---

## 8. Optional: testing the connector without deploying

LocalWP's Live Link exposes a local site on a temporary public HTTPS URL. That can prove the Claude Chat connector against the Local site before touching the live one.

Treat it as a convenience, not the real test. The URL changes each session, and the tunnel sits in the request path, so a failure there does not cleanly distinguish a protocol problem from a tunnel problem. The live site remains the authoritative proof.
