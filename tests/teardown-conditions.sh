#!/usr/bin/env bash
#
# Reverses everything tests/seed-conditions.sh did, using the state file it
# wrote. LOCAL SITE ONLY.
#
# Usage: WP_CMD="wp" bash tests/teardown-conditions.sh

set -euo pipefail

WP_CMD="${WP_CMD:-wp}"
STATE_FILE="$(cd "$(dirname "$0")" && pwd)/.seed-state"

SITEURL="$($WP_CMD option get siteurl)"
case "$SITEURL" in
  *pluginlens.local*) ;;
  *)
    echo "REFUSING TO RUN: siteurl is $SITEURL, not the pluginlens.local development site." >&2
    exit 1
    ;;
esac

if [ ! -f "$STATE_FILE" ]; then
  echo "Nothing to tear down: $STATE_FILE does not exist." >&2
  exit 0
fi

PLUGINS_DIR="$($WP_CMD eval 'echo WP_PLUGIN_DIR;')"

# Deactivate anything we activated (before deleting anything).
while IFS='=' read -r key value; do
  [ "$key" = "activated" ] || continue
  if $WP_CMD plugin is-active "$value" 2>/dev/null; then
    $WP_CMD plugin deactivate "$value" >/dev/null
    echo "  deactivated: $value"
  fi
done < "$STATE_FILE"

# Delete every plugin we installed.
while IFS='=' read -r key value; do
  [ "$key" = "installed" ] || continue
  if $WP_CMD plugin is-installed "$value" 2>/dev/null; then
    $WP_CMD plugin delete "$value" >/dev/null
    echo "  deleted: $value"
  fi
done < "$STATE_FILE"

# Remove the closed-plugin fixture.
while IFS='=' read -r key value; do
  [ "$key" = "closed_plugin" ] || continue
  rm -rf "${PLUGINS_DIR:?}/$value"
  echo "  removed fixture: $value"
done < "$STATE_FILE"

# Unschedule the orphan cron hook.
while IFS='=' read -r key value; do
  [ "$key" = "orphan_cron" ] || continue
  $WP_CMD cron event delete "$value" >/dev/null 2>&1 || true
  echo "  unscheduled: $value"
done < "$STATE_FILE"

# Drop the orphan table.
while IFS='=' read -r key value; do
  [ "$key" = "orphan_table" ] || continue
  $WP_CMD db query "DROP TABLE IF EXISTS \`$value\`;"
  echo "  dropped table: $value"
done < "$STATE_FILE"

# Caching plugins occasionally leave dropins and cache directories behind.
WP_CONTENT="$($WP_CMD eval 'echo WP_CONTENT_DIR;')"
for leftover in advanced-cache.php cache/cache-enabler cache/wpfc; do
  if [ -e "$WP_CONTENT/$leftover" ]; then
    rm -rf "${WP_CONTENT:?}/$leftover"
    echo "  removed leftover: wp-content/$leftover"
  fi
done

rm -f "$STATE_FILE"

echo
echo "Verification:"
echo "  total plugins now: $($WP_CMD plugin list --field=name | wc -l | tr -d ' ')"
echo "  orphan tables remaining: $($WP_CMD db query "SHOW TABLES LIKE '%defunct_tracker_log';" --skip-column-names | wc -l | tr -d ' ')"
echo "  orphan cron remaining: $($WP_CMD cron event list --fields=hook | grep -c defunct_analytics_hourly_sync || true)"
echo "Teardown complete."
