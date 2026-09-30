#!/usr/bin/env bash
# Read-only diagnostic for the redesigned target checkout (Report 51 §24).
#
# Why: deploy.yml runs `php artisan migrate --force` in ~/erp-backend-master on every
# master push, yet woodnork_erp has zero tables. This script shows, from the host,
# what that checkout actually resolves to. It changes nothing and prints no secrets
# (no passwords, usernames, keys or GRANT lines).
#
# Run on the host as the deploy user:
#   bash ~/erp-backend-master/deploy/diagnose-target-deploy.sh ~/erp-backend-master
set -u
CHECKOUT="${1:-$HOME/erp-backend-master}"

echo "== Checkout"
cd "$CHECKOUT" 2>/dev/null || { echo "cd $CHECKOUT FAILED — deploy.yml's 'cd' would fail and every later line would run elsewhere"; exit 1; }
pwd
git rev-parse --abbrev-ref HEAD 2>&1; git log -1 --format='%h %ad %s' --date=iso 2>&1
echo "Local modifications (a dirty tree can make 'git pull' fail silently in deploy.yml):"
git status --short 2>&1 | head -20

echo "== PHP"
command -v php; php -v 2>&1 | head -1

echo "== Environment (non-secret keys only)"
if [ -f .env ]; then
  grep -E '^(APP_ENV|APP_URL|DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|QUEUE_CONNECTION|FILESYSTEM_DISK)=' .env
else
  echo ".env MISSING — config falls back to defaults (DB_CONNECTION=pgsql): migrate cannot reach MySQL"
fi
[ -f bootstrap/cache/config.php ] && echo "config is CACHED (bootstrap/cache/config.php) — artisan uses the cached values, not .env"

echo "== Artisan readiness (read-only)"
php artisan migration:target-readiness 2>&1 | tail -30

echo "== Queue cron"
crontab -l 2>/dev/null | grep -E 'queue:work|schedule:run' || echo "no queue/scheduler cron in this user's crontab (check the hosting panel)"

echo "== Storage link"
ls -ld public/storage 2>&1

echo
echo "Also check, in GitHub Actions → 'Deploy Laravel' → the latest master run → 'Deploy to production':"
echo "  - the output of 'php artisan migrate --force' (errors such as Access denied / Unknown database / could not find driver)"
echo "  - appleboy/ssh-action v1.0.3 defaults to script_stop: false (no stop on error): the step's exit status is the LAST command's,"
echo "    so a failed migrate is reported as success if 'stores:process-finance-postings' succeeds afterwards."
