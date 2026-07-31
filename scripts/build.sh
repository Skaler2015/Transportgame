#!/usr/bin/env bash
# Build the production artifact: compile the Vue SPA and stage it inside the
# Laravel app's public/ directory so the whole game deploys as one app.
#
# Usage:  bash scripts/build.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "▸ Building frontend (Vue + Vite)…"
cd frontend
npm ci
npm run build
cd "$ROOT"

echo "▸ Staging SPA into backend/public…"
# Remove previously staged SPA output (keep Laravel's own public files).
rm -rf backend/public/assets
rm -f backend/public/index.html
cp -r frontend/dist/. backend/public/

echo "▸ Installing backend production dependencies…"
cd backend
composer install --no-dev --optimize-autoloader --no-interaction
cd "$ROOT"

echo "✓ Build complete. Deployable app is in backend/ (docroot = backend/public)."
