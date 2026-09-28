#!/usr/bin/env bash
# WNG real-data rehearsal pipeline (Reports 52–55). Runs INSIDE the DDEV web container,
# from the rehearsal checkout (storage/app/wng_rehearsal) whose .env points at the
# disposable wng_*rehearsal databases. Started by run-wng-rehearsal.sh; never run it
# from a checkout pointed at a live database (the tools refuse live names anyway).
set -euo pipefail
cd /var/www/html/storage/app/wng_rehearsal
composer dump-autoload -q
A="php -d error_reporting=0 -d memory_limit=2G artisan"
R=rehearsal-reports
T=wng_target_rehearsal

echo "== configuration"
for k in database.connections.mysql.database database.connections.source_staging.database finance_accounts.profile finance_accounts.wip_policy queue.default; do
  printf '  %s = %s\n' "$k" "$($A config:show $k 2>/dev/null | tail -1 | sed 's/^ *//')"
done

echo "== evidence before Stage 1"
for r in projects employees d2 roles budget-authority w7 project-status; do
  $A migration:evidence $r --connection=source_staging --report-dir=$R/pre-stage1 >/dev/null 2>&1 && echo "  $r"
done

echo "== Stage 1 (source copy)"; t=$(date +%s)
$A migration:stage1 --execute --confirm=wng_source_rehearsal 2>/dev/null | grep -E "Stage 1|pending|FAIL|REFUSED" | tail -4
echo "  $(( $(date +%s)-t ))s"

echo "== clean target chain"; t=$(date +%s)
$A migrate --force >/dev/null; echo "  $(( $(date +%s)-t ))s"

echo "== dry run"
$A migration:import-source --accept-verified-narrowing --report-dir=$R/dry-run 2>/dev/null | grep -E "Schema gate|Orphan|refuse" | head -6
F=$(ls -td $R/dry-run/*/ | head -1)orphan_scan_staging.json
ALLOW=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo implode(" ", array_map(fn($x)=>"--allow-orphans=".$x["table"].".".$x["column"], $d["blocking"]));' "$F")
echo "  inherited orphan columns allow-listed: $(echo $ALLOW | wc -w)"

echo "== import"; t=$(date +%s)
$A migration:import-source --accept-verified-narrowing --execute --confirm=$T $ALLOW --report-dir=$R/import 2>/dev/null | grep -E "Schema gate|Reconciled|VALIDATION|REFUSED|  - " | head -10
echo "  $(( $(date +%s)-t ))s"

echo "== permissions"
$A migration:regenerate permissions --execute --confirm=$T 2>/dev/null | grep -E "Sync|Already|REFUSED"
$A migration:import-source --only=model_has_permissions --accept-verified-narrowing --execute --confirm=$T --report-dir=$R/mapped 2>/dev/null | grep -E "VALIDATION"

echo "== chart: complete + classify (dry run, execute, idempotent rerun)"
$A finance:complete-chart --classify-existing 2>/dev/null | grep -E "DRY|REFUSED|  - |Functions resolved|classified"
$A finance:complete-chart --execute --confirm=$T --classify-existing --output=$R/chart 2>/dev/null | grep -E "EXECUTE|REFUSED|  - |Functions resolved|classified|Catalogue"
echo "  rerun creates: $($A finance:complete-chart --execute --confirm=$T --classify-existing 2>/dev/null | grep -cE '\| create \|' || true)"

echo "== reference data (paying accounts, tax, expense codes)"
$A migration:regenerate reference --execute --confirm=$T 2>/dev/null | grep -E "Reference|REFUSED"

echo "== paying accounts without a ledger account (created by the step above)"
$A finance:complete-chart --execute --confirm=$T --disable-unlinked-sources 2>/dev/null | grep -E "Paying|REFUSED"

echo "== planned cost lines (twice: idempotent)"
$A migration:regenerate planned-cost-lines --execute --confirm=$T 2>/dev/null | grep -E "Planned|Projected"
$A migration:regenerate planned-cost-lines --execute --confirm=$T 2>/dev/null | grep -E "Projected"

echo "== reconciliation evidence"
for c in source_staging mysql; do for r in projects employees budget-authority w7; do
  $A migration:evidence $r --connection=$c --report-dir=$R/reconcile-$c >/dev/null 2>&1
done; done; echo "  written"
$A migration:verify-overtime-chain --report-dir=$R/overtime 2>/dev/null | tail -1
$A migration:verify-files --dry-run --report-dir=$R/files 2>/dev/null | grep -vE '^\s*$|DRY' | tail -4
$A migration:target-readiness 2>/dev/null | tail -4 || true   # informational: cron/storage-link are hosting items
echo PIPELINE-DONE
