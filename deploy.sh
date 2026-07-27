#!/usr/bin/env bash
#
# Deploys the plugin to the live test site as one rsync over SSH.
# Never automatic: run by hand when a phase is complete and the connector
# needs proving. Uses the pluginlens-test SSH alias and nothing else.

set -euo pipefail

REMOTE="pluginlens-test"
REMOTE_PATH="domains/outsourcewebdesign.com/public_html/wp-content/plugins/pluginlens/"

cd "$(dirname "$0")"

rsync -avz --delete \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='vendor' \
  --exclude='tests' \
  --exclude='docs' \
  --exclude='.claude' \
  --exclude='.DS_Store' \
  --exclude='tests/.seed-state' \
  ./ "$REMOTE:$REMOTE_PATH"

echo
echo "Deployed to $REMOTE:$REMOTE_PATH"
