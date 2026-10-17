#!/usr/bin/env bash
# Sync read-only reference from opmonihub/sistema (pinned commit).
# Delegates to the expanded core bundle (OpenSpec changes 1–9).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec "$ROOT/scripts/sync-sistema-full-core.sh" "$@"
