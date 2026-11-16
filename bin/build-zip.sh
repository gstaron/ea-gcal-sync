#!/usr/bin/env bash
# Zbalí plugin z aktuálneho commitu do dist/ea-gcal-sync.zip (na ručné nahranie do WordPressu).
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist
git archive --format=zip -o dist/ea-gcal-sync.zip HEAD ea-gcal-sync
echo "dist/ea-gcal-sync.zip ($(git rev-parse --short HEAD))"
