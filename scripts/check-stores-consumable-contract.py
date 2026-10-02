#!/usr/bin/env python3
"""Compare literal Stores and affected Finance API calls with current registered routes and Git HEAD call sites."""
import json, re, subprocess
from pathlib import Path
backend = Path(__file__).resolve().parents[1]
frontend = backend.parent / 'ERP-Frontend'
import sys
raw = Path(sys.argv[1]).read_text()
routes = json.loads(raw[raw.index('[{'):])
registered = []
for route in routes:
    pattern = re.sub(r'\{[^}]+\}', '[^/]+', '/' + route['uri'])
    for method in route['method'].split('|'):
        registered.append((method, re.compile('^'+pattern+'$')))
call = re.compile(r"\b(?:api|axios|http|apiClient)\.(get|post|put|patch|delete)(?:<[^;]*?>)?\(\s*([`'\"])(.*?)\2", re.S)
def scan(source):
    result = set()
    for match in call.finditer(source):
        method, path = match[1].upper(), match[3]
        if not path.startswith('/api/procurement-stores') and not path.startswith('/api/finance') and not path.startswith('/api/costs'): continue
        path = re.sub(r'\$\{.*?\}', 'X', path).split('?')[0]
        if not any(m == method and p.fullmatch(path) for m,p in registered): result.add((method,path))
    return result
current, baseline = set(), set()
for root in ['src/modules/procurement-stores', 'src/modules/finance']:
    for file in (frontend/root).rglob('*'):
        if file.suffix not in ['.ts','.vue'] or '.spec.' in file.name: continue
        current |= scan(file.read_text())
        old = subprocess.run(['git','show','HEAD:'+str(file.relative_to(frontend))],cwd=frontend,text=True,capture_output=True)
        if old.returncode == 0: baseline |= scan(old.stdout)
new = current-baseline
print(f'Current unmatched: {len(current)}; baseline unmatched: {len(baseline)}; newly unmatched: {len(new)}')
for method,path in sorted(current): print(('NEW' if (method,path) in new else 'BASELINE'),method,path)
# These six contracts must exist regardless of how the frontend constructs paths.
for method,path in [('GET','/api/procurement-stores/consumable-units'),('GET','/api/procurement-stores/consumable-units/materials/X'),('GET','/api/procurement-stores/consumable-units/X'),('POST','/api/procurement-stores/consumable-units/X/counts'),('POST','/api/procurement-stores/consumable-units/X/hold'),('POST','/api/procurement-stores/consumable-units/materials/X/convert'),('POST','/api/procurement-stores/movements')]:
    matched=any(m==method and p.fullmatch(path) for m,p in registered)
    print('PASS' if matched else 'FAIL', method,path)
    if not matched: new.add((method,path))
sys.exit(bool(new))
