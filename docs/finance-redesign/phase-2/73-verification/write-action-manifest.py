from pathlib import Path
import runpy,contextlib,io
p=Path(__file__).parent
with contextlib.redirect_stdout(io.StringIO()): ns=runpy.run_path(str(p/'check-api-contract.py'))
routes=ns['routes'];mask=ns['mask'];current=ns['current'];front=ns['frontend']
rows=['# Report 73 — Finance API/action contract manifest','','Generated from literal API calls, known closed W1–W4 wrapper expansion and purchasing/search dispatches, compared to the actual Laravel route registry. Arbitrary dynamic code is not evaluated. See the report service/permission matrix and regression logs for state eligibility; this manifest does not claim every action was executed against live records.','','| Method / endpoint | Frontend owner | Backend handler | Middleware | Eligibility / outcome |','|---|---|---|---|---|']
files=[f for f in (front/'src/modules/finance').rglob('*') if f.suffix in ['.vue','.ts'] and not f.name.endswith('.spec.ts')]
owners={f:ns['calls'](f.read_text()) for f in files}
for method,path in sorted(current):
 route=next((r for r in routes if mask('/'+r['uri'])==path and method in r['method'].split('|')),None)
 source=[str(f.relative_to(front/'src/modules/finance')) for f,calls in owners.items() if (method,path) in calls]
 handler=route['action'] if route else 'UNMATCHED';middle=', '.join(route.get('middleware',[])) if route else ''
 rule='Read: authorized scope/filters; success authoritative projection; errors preserve unavailable state.' if method=='GET' else 'Write: controller/service permission + record-state + validation + posting/period controls; success refresh authoritative record; refusal retains record and surfaces error.'
 rows.append('| '+' | '.join([method+' `'+path+'`','<br>'.join(source) or 'Closed search/purchasing dispatch',handler,middle,rule])+' |')
(p/'action-contract-manifest.md').write_text('\n'.join(rows)+'\n')
