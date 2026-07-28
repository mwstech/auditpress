# CCD Kickoff Prompt: AuditPress Phase 0 (v4)

Supersedes all earlier versions. LocalWP clone for development, live site for the connector proof.

Prerequisites, all done by Benny before pasting this:

- `docs/SPEC.md` committed to `main`
- LocalWP site `auditpress` created, SSL trusted, permalinks set to Post name
- outsourcewebdesign.com cloned into that Local site and loading correctly
- Repo cloned into the Local site's plugins directory
- `ssh auditpress-test` connects and `wp --info` works
- Live site pre-flight checks in `ENVIRONMENT-SETUP.md` section 6 have passed

Paste everything below the line into CCD.

---

You are building AuditPress, a WordPress plugin that turns the site it is installed on into a read-only MCP server. The full specification is at `docs/SPEC.md`. Read it before writing anything.

## Project coordinates

- Repository: `https://github.com/mwstech/auditpress.git`
- Default branch: `main`, protected, pull requests only
- Development site: LocalWP site `auditpress` at `https://pluginlens.local`, a clone of outsourcewebdesign.com
- The repo is cloned directly into that site's plugins directory, so the repo and the active plugin are the same folder. No sync step for local work.
- WP-CLI is available through the LocalWP site shell
- Live site: `https://www.outsourcewebdesign.com`, dormant since 2015, used only to prove the connector works over public HTTPS
- SSH alias for the live site: `auditpress-test`

Always use the `www.` form of the live URL. The site canonicalizes to `www.` and a redirect on a POST will drop the request body.

## Standing rules for this project

1. Work on a named feature branch, `phase-0-scaffold` for this batch. Never commit directly to `main`. Open a pull request when the phase is complete.
2. One feature per commit. No chained tasks in a single commit. Commit messages in the form `phase-0: add settings page token generation`.
3. Read-only against WordPress. This codebase must never contain a call to `activate_plugin`, `deactivate_plugins`, `delete_plugins`, any `*_Upgrader` class, or any `INSERT`, `UPDATE`, `DELETE`, `DROP`, or `ALTER` SQL. The seed and teardown scripts in `tests/` are the sole exception and never ship.
4. No Composer runtime dependencies. WordPress ships everything needed, and a dependency-free plugin means deploying is one rsync with no build step.
5. Never ask me for SSH credentials, WP admin passwords, or token values, and never write any of them into a file, a commit, or a log line. The SSH host is configured as `auditpress-test`. Use that alias and nothing else.
6. **All development happens against the Local clone.** The live site is touched only at step I, only when I ask. Never run the seed script against the live site.
7. When you make a decision a future agent might reverse, append it to `docs/DECISIONS.md` with the rule and the reason.
8. If an external API is unreachable during development, mock it and continue. Do not stall waiting for network access.
9. I direct this build in plain English and do not read code. When you report progress, describe what changed and what it means, not which functions you edited.

## Phase 0 scope

This phase proves one thing: that Claude Chat can connect to a WordPress site running this plugin. Nothing else matters until that works. Build the minimum that tests it.

### A. Survey the clone first

Before writing anything, inventory the Local site and report back: total plugins, how many active, which look abandoned, whether any custom or orphan tables exist, whether any orphan cron events exist, and whether any active plugin registers shortcodes that appear nowhere in the content. This tells us which test conditions are already present and which need injecting. Do not modify anything during the survey.

### B. Repository scaffold

1. Create the directory structure defined in section 3 of the spec, minus `.wp-env.json`. Empty placeholder files are fine for anything not built this phase.
2. `auditpress.php` with a complete WordPress plugin header. The `Plugin Name` must be exactly `AuditPress` with no descriptive suffix, because the wp.org slug is derived from it. Version 0.1.0. Author `Macronimous Web Solutions`. Author URI `https://www.macronimous.com/`. Plugin URI `https://www.macronimous.com/free-tools/auditpress/`. License GPLv2 or later with License URI. Text Domain `auditpress`. Requires at least 6.0. Requires PHP 7.4.
3. `composer.json` with `squizlabs/php_codesniffer` and `wp-coding-standards/wpcs` as dev dependencies only.
4. `phpcs.xml.dist` configured against the `WordPress-Extra` ruleset.
5. `.gitignore` covering `vendor/`, `node_modules/`, and OS cruft.
6. `docs/DECISIONS.md` seeded with the seven entries listed in section 15 of the spec.

### C. Test conditions

1. Based on the survey in step A, write `tests/seed-conditions.sh` to inject only the conditions the clone lacks, from the list in `ENVIRONMENT-SETUP.md` section 7.
2. Write `tests/teardown-conditions.sh` to reverse every one of them cleanly.
3. Run seed, confirm each condition exists, run teardown, confirm the clone returns to its prior state.

### D. Settings page

1. Admin menu item under Tools, labeled AuditPress. Capability `manage_options`, or `manage_network_options` on multisite.
2. A master enable toggle for the MCP endpoint. **Default off.** A fresh install must expose nothing.
3. A "Generate token" action producing 32 bytes from `random_bytes`, hex encoded, stored in a non-autoloaded option.
4. When enabled and a token exists, display the full connection URL with a copy button.
5. A "Revoke and regenerate" action that invalidates the previous token immediately.
6. Nonce on every form action. Escape every output.

### E. Minimal MCP transport

1. Register `POST /wp-json/auditpress/v1/mcp/{token}` with `permission_callback` returning true. Authenticate inside the handler.
2. Token comparison with `hash_equals`. Invalid token returns HTTP 401 with a plain JSON body. Endpoint disabled returns HTTP 404.
3. Implement JSON-RPC 2.0 handling for `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, and `ping`.
4. `notifications/initialized` returns HTTP 202 with an empty body and no JSON-RPC response object.
5. Respond with `application/json`. Do not implement SSE.
6. Run stateless. Do not issue a session ID.
7. Buffer and discard any stray output from other plugins before writing the response. The clone runs an old custom theme and AIOSEO, so assume something will emit a stray notice.
8. Put token verification behind an interface in `class-security.php` so the auth mechanism can be swapped without touching the transport layer.
9. Before implementing, check the current MCP specification for the correct `protocolVersion` string and confirm that a plain JSON response to a POST is still permitted. The spec document names `2025-06-18` from memory. Verify it.

### F. One hardcoded tool

`get_capabilities`, taking no arguments, returning a JSON string describing what the server will eventually be able to answer and explicitly listing what it does not measure: per-plugin runtime cost, front-end asset weight, and any write operation. Content accuracy does not matter yet. The round trip does.

### G. Test harness

`tests/mcp-client.php`, a CLI script that sends raw JSON-RPC to a given endpoint URL and prints the response and its byte size. It must run `initialize`, `tools/list`, and `tools/call get_capabilities` against either the Local or the live site. Every later phase is verified through this, so build it properly.

### H. CI

`.github/workflows/ci.yml` running on pull requests: PHP lint across 7.4, 8.0, 8.2, and 8.4; PHPCS against the project ruleset; and a grep check that fails the build if any forbidden write function or SQL keyword from rule 3 appears in `includes/`.

### I. Deploy to the live site

Only when everything above passes locally, and only when I say so.

1. Confirm the WordPress root and plugin directory path on the live site over SSH.
2. Write `deploy.sh` as a single rsync excluding `.git`, `vendor`, `tests`, and `docs`.
3. Deploy, activate with WP-CLI, enable the endpoint, generate a token.
4. Run `tests/mcp-client.php` against the live URL and confirm the same three calls succeed over public HTTPS.
5. If any request returns 403, 406, or an HTML error page rather than JSON, stop and tell me. That is the host firewall, not our code, and it needs a support ticket rather than a workaround.

## Out of scope for Phase 0

Do not build any collector. Do not make any outbound HTTP call. Do not write `readme.txt`. Do not add translations. Do not style the admin page beyond default WordPress admin markup. Do not attempt OAuth.

## Verification checklist

Report back when I can do all of these:

1. I read your survey of the clone and know what test conditions already exist.
2. I activate the plugin on the Local site and see AuditPress under Tools.
3. The endpoint is off, and hitting the URL returns 404.
4. I toggle it on, generate a token, and copy a connection URL.
5. I run `tests/mcp-client.php` against the Local URL and see a valid `initialize` response, a tool list containing `get_capabilities`, and a successful tool call.
6. I change one character in the token and get a 401.
7. I run the seed script, see every injected condition, run teardown, and they are gone.
8. CI passes on the pull request.
9. The same three calls succeed against `https://www.outsourcewebdesign.com` over public HTTPS.

Once the checklist passes, I will add the live connection URL to Claude Chat as a custom connector and confirm it works from there. Do not proceed to Phase 1 until I confirm that connection.

Standing conventions apply throughout: secrets stay server-side, schema changes are additive, and any UI beyond default WordPress admin markup gets a design brief from me first.
