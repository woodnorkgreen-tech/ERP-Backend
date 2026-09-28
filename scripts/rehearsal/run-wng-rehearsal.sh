#!/usr/bin/env bash
# Rebuild the disposable WNG rehearsal from the verified snapshot, end to end (Report 55).
# Host side: verify the snapshot, recreate the three wng_*rehearsal databases, restore the
# snapshot twice (pristine = count authority, rehearsal = Stage 1 copy), then run the
# in-container pipeline. Touches ONLY wng_source_pristine, wng_source_rehearsal and
# wng_target_rehearsal on the local DDEV database server.
#   scripts/rehearsal/run-wng-rehearsal.sh [--smoke]
set -euo pipefail
cd "$(dirname "$0")/../.."
SNAPSHOT=database/erpsystem-20260928-0918.sql.gz
EXPECTED=28bced26bad9b49ddbc4731f288b4c0bd1095e47076d254bafa99604c9e945e2
DB=ddev-ERP-Backend-db
WEB=ddev-ERP-Backend-web

echo "== snapshot"
H=$(sha256sum "$SNAPSHOT" | cut -d' ' -f1)
[ "$H" = "$EXPECTED" ] || { echo "SHA-256 MISMATCH ($H) — refusing"; exit 1; }
echo "  SHA-256 MATCH $H"; gzip -t "$SNAPSHOT" && echo "  gzip OK"
git check-ignore -q "$SNAPSHOT" && echo "  excluded from git (personal data)"

echo "== rehearsal databases"
docker exec $DB mysql -uroot -proot -e "
  DROP DATABASE IF EXISTS wng_source_pristine; DROP DATABASE IF EXISTS wng_source_rehearsal; DROP DATABASE IF EXISTS wng_target_rehearsal;
  CREATE DATABASE wng_source_pristine; CREATE DATABASE wng_source_rehearsal; CREATE DATABASE wng_target_rehearsal;
  GRANT ALL ON wng_source_pristine.* TO 'db'@'%'; GRANT ALL ON wng_source_rehearsal.* TO 'db'@'%'; GRANT ALL ON wng_target_rehearsal.* TO 'db'@'%';
  FLUSH PRIVILEGES;" 2>/dev/null && echo "  recreated"
for d in wng_source_pristine wng_source_rehearsal; do
  t=$(date +%s); zcat "$SNAPSHOT" | docker exec -i $DB mariadb -uroot -proot $d && echo "  $d restored in $(( $(date +%s)-t ))s"
done

docker exec -i $WEB bash < scripts/rehearsal/wng-rehearsal-pipeline.sh

if [ "${1:-}" = "--smoke" ]; then
  echo "== W1–W7 real-data smoke (commits data to the rehearsal target only)"
  docker exec -w /var/www/html/storage/app/wng_rehearsal $WEB php -d error_reporting=0 -d memory_limit=2G vendor/bin/phpunit -c phpunit.rehearsal.xml
fi
