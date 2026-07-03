#!/bin/sh
set -e

# FrankenPHP is a single binary that bundles its own thread-safe PHP runtime.
# It's ~170MB and platform-specific, so it isn't committed — fetch the build for
# this container's OS on boot if it isn't already present. (On Railway the
# filesystem is fresh per deploy, so this runs once per release.)
if [ ! -x ./frankenphp ]; then
  echo "FrankenPHP binary not found — downloading…"
  php artisan octane:install --server=frankenphp --no-interaction
fi

# Apply pending migrations before booting (Railway runs this on every deploy).
php artisan migrate --force

# Serve with Laravel Octane on FrankenPHP: a persistent, multi-worker runtime.
# Unlike `php artisan serve` (PHP's single-threaded dev server, which processed
# one request at a time so a slow query froze the whole API), this handles
# requests concurrently across workers. --max-requests recycles workers to keep
# memory flat. `exec` hands over PID 1 so Railway's stop signals reach Octane.
exec php artisan octane:frankenphp \
  --host=0.0.0.0 \
  --port="$PORT" \
  --workers=auto \
  --max-requests=250
