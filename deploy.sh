#!/usr/bin/env bash
#
# Deploys the plugin to the live test site.
#
# Never automatic: run by hand when a phase is complete and the connector
# needs proving. Uses the pluginlens-test SSH alias and nothing else.
# (That alias names the host, not the plugin — do not rename it along with
# the plugin. Doing exactly that once produced a misleading rsync
# "io_read_int" error, which is how openrsync reports an unresolvable host.)
#
# The working tree carries ~2,600 files, almost all of them in vendor/.
# This script stages the shipped file set first, using the same .distignore
# the release zip uses, and transfers only that — so the live site receives
# exactly what wordpress.org would.

set -euo pipefail

REMOTE="pluginlens-test"
REMOTE_PATH="domains/outsourcewebdesign.com/public_html/wp-content/plugins/auditra/"

cd "$(dirname "$0")"

if [ ! -f .distignore ]; then
	echo "error: .distignore not found; refusing to deploy an unfiltered tree." >&2
	exit 1
fi

# Fail loudly on an unreachable host rather than through a cryptic rsync error.
if ! ssh -o BatchMode=yes -o ConnectTimeout=15 "$REMOTE" true 2>/dev/null; then
	echo "error: cannot reach '$REMOTE' over SSH." >&2
	echo "       Check the Host entry in ~/.ssh/config and that the key is authorized." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

rsync -a \
	--exclude-from=.distignore \
	--exclude='.wordpress-org' \
	--exclude='vendor' \
	--exclude='.git' \
	./ "$STAGE/"

FILES=$(find "$STAGE" -type f | wc -l | tr -d ' ')
if [ "$FILES" -lt 20 ]; then
	echo "error: staged only $FILES files; that is not a complete plugin." >&2
	exit 1
fi
echo "Staged $FILES files."

rsync -az --delete "$STAGE/" "$REMOTE:$REMOTE_PATH"

# Verify what actually landed instead of trusting the exit code.
REMOTE_FILES=$(ssh -o BatchMode=yes "$REMOTE" "find \"\$HOME/$REMOTE_PATH\" -type f 2>/dev/null | wc -l" | tr -d ' ')
if [ "$REMOTE_FILES" != "$FILES" ]; then
	echo "error: staged $FILES files but $REMOTE_FILES arrived. Deploy incomplete." >&2
	exit 1
fi

echo "Deployed $REMOTE_FILES files to $REMOTE:$REMOTE_PATH"
echo "Reminder: activate the plugin, and reconnect any MCP client if the namespace changed."
