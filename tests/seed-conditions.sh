#!/usr/bin/env bash
#
# Injects the test conditions from ENVIRONMENT-SETUP.md section 7 that the
# local site lacks. LOCAL SITE ONLY - the script refuses to run anywhere else.
# tests/teardown-conditions.sh reverses everything this script does.
#
# This script and its teardown are the sole place in this repository allowed
# to perform write operations against WordPress. They never ship.
#
# Usage: WP_CMD="wp" bash tests/seed-conditions.sh
#   WP_CMD defaults to "wp"; point it at a wrapper if wp is not on PATH.

set -euo pipefail

WP_CMD="${WP_CMD:-wp}"
STATE_FILE="$(cd "$(dirname "$0")" && pwd)/.seed-state"

# ---- Safety: local site only. Never run against the live site. ----
SITEURL="$($WP_CMD option get siteurl)"
case "$SITEURL" in
  *pluginlens.local*) ;;
  *)
    echo "REFUSING TO RUN: siteurl is $SITEURL, not the pluginlens.local development site." >&2
    exit 1
    ;;
esac

if [ -f "$STATE_FILE" ]; then
  echo "REFUSING TO RUN: $STATE_FILE exists, so seeded conditions are already present." >&2
  echo "Run tests/teardown-conditions.sh first." >&2
  exit 1
fi

PREFIX="$($WP_CMD db prefix)"
PLUGINS_DIR="$($WP_CMD eval 'echo WP_PLUGIN_DIR;')"
echo "Seeding test conditions into $SITEURL (table prefix $PREFIX)"
: > "$STATE_FILE"

# ---- Condition 1: orphan table left behind by a deleted plugin ----
ORPHAN_TABLE="${PREFIX}defunct_tracker_log"
$WP_CMD db query "CREATE TABLE \`$ORPHAN_TABLE\` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, visited_at DATETIME NOT NULL, url VARCHAR(255) NOT NULL, PRIMARY KEY (id));"
$WP_CMD db query "INSERT INTO \`$ORPHAN_TABLE\` (visited_at, url) VALUES (NOW(), '/sample-page/'), (NOW(), '/hello-world/');"
echo "orphan_table=$ORPHAN_TABLE" >> "$STATE_FILE"
echo "  [1] orphan table: $ORPHAN_TABLE"

# ---- Condition 2: orphan cron event whose plugin no longer exists ----
ORPHAN_HOOK="defunct_analytics_hourly_sync"
$WP_CMD cron event schedule "$ORPHAN_HOOK" now hourly >/dev/null
echo "orphan_cron=$ORPHAN_HOOK" >> "$STATE_FILE"
echo "  [2] orphan cron hook: $ORPHAN_HOOK"

# ---- Condition 3: two competing caching plugins active at once ----
for slug in cache-enabler wp-fastest-cache; do
  if $WP_CMD plugin is-installed "$slug" 2>/dev/null; then
    echo "cache_preexisting=$slug" >> "$STATE_FILE"
  else
    $WP_CMD plugin install "$slug" >/dev/null
    echo "installed=$slug" >> "$STATE_FILE"
  fi
  if ! $WP_CMD plugin is-active "$slug" 2>/dev/null; then
    $WP_CMD plugin activate "$slug" >/dev/null
    echo "activated=$slug" >> "$STATE_FILE"
  fi
done
echo "  [3] competing caching plugins active: cache-enabler, wp-fastest-cache"

# ---- Condition 4: a plugin whose wp.org listing is closed ----
# Display Widgets was removed from wp.org in 2017; it cannot be downloaded,
# so recreate its header by hand. Inactive on purpose.
CLOSED_DIR="$PLUGINS_DIR/display-widgets"
if [ ! -d "$CLOSED_DIR" ]; then
  mkdir -p "$CLOSED_DIR"
  cat > "$CLOSED_DIR/display-widgets.php" <<'PHP'
<?php
/**
 * Plugin Name: Display Widgets
 * Plugin URI: https://wordpress.org/plugins/display-widgets/
 * Description: Simply hide widgets on specified pages. (Test fixture: this slug is closed on wordpress.org.)
 * Version: 2.7
 * Author: displaywidget
 */
PHP
  echo "closed_plugin=display-widgets" >> "$STATE_FILE"
  echo "  [4] closed-on-wp.org plugin present: display-widgets (inactive)"
else
  echo "  [4] display-widgets already present, leaving untouched"
fi

# ---- Condition 5: enough plugins to stress response size (target 40+) ----
# Mix of currently popular plugins and long-abandoned-but-downloadable ones.
# All left inactive. Failures are tolerated and reported; wp.org availability
# shifts over time.
FILLER_SLUGS=(
  akismet classic-editor classic-widgets contact-form-7 wordpress-seo
  all-in-one-seo-pack wpforms-lite elementor woocommerce jetpack
  duplicate-post wp-mail-smtp redirection updraftplus wp-optimize
  really-simple-ssl broken-link-checker regenerate-thumbnails safe-svg
  disable-comments simple-custom-css code-snippets widget-logic
  members advanced-custom-fields autoptimize loco-translate
  health-check query-monitor debug-bar user-switching duplicator
  wps-hide-login limit-login-attempts-reloaded cookie-notice
  hello-dolly wp-crontrol transients-manager
)
CURRENT_TOTAL="$($WP_CMD plugin list --field=name | wc -l | tr -d ' ')"
echo "  [5] plugins before filler: $CURRENT_TOTAL, target 40+"
for slug in "${FILLER_SLUGS[@]}"; do
  TOTAL="$($WP_CMD plugin list --field=name | wc -l | tr -d ' ')"
  if [ "$TOTAL" -ge 42 ]; then
    break
  fi
  if $WP_CMD plugin is-installed "$slug" 2>/dev/null; then
    continue
  fi
  if $WP_CMD plugin install "$slug" >/dev/null 2>&1; then
    echo "installed=$slug" >> "$STATE_FILE"
  else
    echo "      warn: could not install $slug (skipped)"
  fi
done

# ---- Condition 6: an mu-plugin (never appears on the plugins screen) ----
WP_CONTENT="$($WP_CMD eval 'echo WP_CONTENT_DIR;')"
MU_DIR="$WP_CONTENT/mu-plugins"
if [ ! -d "$MU_DIR" ]; then
  mkdir -p "$MU_DIR"
  echo "created_mu_dir=1" >> "$STATE_FILE"
fi
if [ ! -f "$MU_DIR/pluginlens-mu-fixture.php" ]; then
  cat > "$MU_DIR/pluginlens-mu-fixture.php" <<'PHP'
<?php
/**
 * Plugin Name: PluginLens MU Fixture
 * Description: Test fixture: a must-use plugin, to verify mu-plugin inventory. Does nothing.
 * Version: 1.0
 */
PHP
  echo "mu_plugin=pluginlens-mu-fixture.php" >> "$STATE_FILE"
fi
echo "  [6] mu-plugin fixture: pluginlens-mu-fixture.php"

# ---- Condition 7: an object-cache.php drop-in ----
# Pass-through to WordPress's default cache implementation, so the site
# behaves identically while the drop-in slot is genuinely occupied.
if [ ! -f "$WP_CONTENT/object-cache.php" ]; then
  cat > "$WP_CONTENT/object-cache.php" <<'PHP'
<?php
/**
 * Plugin Name: Object Cache Drop-in Fixture
 * Description: Test fixture: occupies the object-cache.php drop-in slot while delegating to WordPress's default in-memory cache.
 * Version: 1.0
 */
require_once ABSPATH . WPINC . '/cache.php';
PHP
  echo "dropin=object-cache.php" >> "$STATE_FILE"
  echo "  [7] object-cache.php drop-in fixture created (passthrough)"
else
  echo "  [7] object-cache.php already present, leaving untouched"
fi

# ---- Condition 8: a single-file plugin, not in a folder ----
if [ ! -f "$PLUGINS_DIR/legacy-maintenance-notice.php" ]; then
  cat > "$PLUGINS_DIR/legacy-maintenance-notice.php" <<'PHP'
<?php
/**
 * Plugin Name: Legacy Maintenance Notice
 * Description: Test fixture: a single-file plugin living directly in the plugins directory. Does nothing.
 * Version: 0.3
 * Author: A Bygone Freelancer
 */
PHP
  echo "single_file=legacy-maintenance-notice.php" >> "$STATE_FILE"
fi
echo "  [8] single-file plugin fixture: legacy-maintenance-notice.php (inactive)"

# ---- Verification summary ----
echo
echo "Verification:"
$WP_CMD db query "SELECT COUNT(*) AS orphan_table_rows FROM \`$ORPHAN_TABLE\`;"
$WP_CMD cron event list --fields=hook | grep -c "$ORPHAN_HOOK" | sed 's/^/  orphan cron events: /'
ACTIVE_CACHING="$($WP_CMD plugin list --status=active --field=name | grep -cE '^(cache-enabler|wp-fastest-cache)$' || true)"
echo "  active caching plugins (want 2): $ACTIVE_CACHING"
[ -f "$CLOSED_DIR/display-widgets.php" ] && echo "  closed plugin fixture: present"
[ -f "$MU_DIR/pluginlens-mu-fixture.php" ] && echo "  mu-plugin fixture: present"
[ -f "$WP_CONTENT/object-cache.php" ] && echo "  object-cache.php drop-in: present"
[ -f "$PLUGINS_DIR/legacy-maintenance-notice.php" ] && echo "  single-file plugin fixture: present"
echo "  total plugins now: $($WP_CMD plugin list --field=name | wc -l | tr -d ' ')"
echo
echo "State recorded in $STATE_FILE - teardown-conditions.sh reverses exactly this."
