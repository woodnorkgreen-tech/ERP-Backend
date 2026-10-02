#!/usr/bin/env python3
"""Check Finance Reports GET calls against an artisan route:list JSON export.

Usage: python3 scripts/check-finance-reports-contract.py /tmp/finance-routes.json
Export with: ddev exec php -d error_reporting=22527 artisan route:list --json
"""
import json
from pathlib import Path
import re
import sys

routes = json.loads(Path(sys.argv[1]).read_text())
service = Path(__file__).resolve().parents[2] / "ERP-Frontend/src/modules/finance/reports/services/reportsService.ts"
paths = re.findall(r"['\"](/api/finance/reports/[^'\"]+)['\"]", service.read_text())
registered = {"/" + route["uri"] for route in routes if "GET" in route["method"].split("|")}
unmatched = sorted(set(paths) - registered)
for path in sorted(set(paths)):
    print(f"{'FAIL' if path in unmatched else 'PASS'} GET {path}")
print(f"{len(paths)} call sites; {len(set(paths))} endpoints; {len(unmatched)} unmatched")
if not paths:
    sys.exit("FAIL: no Finance Reports calls found")
sys.exit(1 if unmatched else 0)
