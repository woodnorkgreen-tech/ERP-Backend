"""Compare literal Finance API calls and Report 73A federated sources to Laravel routes.
Dynamic existing wrapper contracts remain covered by their W1-W7 regressions.
"""
from pathlib import Path
import json,re,subprocess
backend=Path(__file__).resolve().parents[4]
frontend=backend.parent/'ERP-Frontend'
routes=json.loads((Path(__file__).parent/'registered-routes.json').read_text())
def mask(path):
 path=path.split('?')[0]
 path=re.sub(r'\$\{.*?\}', '{}', path)
 path=re.sub(r'\{[^}]*\}', '{}', path)
 return path.rstrip('/')
registered={(m,mask('/'+r['uri'])) for r in routes for m in r['method'].split('|')}
pattern=re.compile(r"\b(?:api|axios)\.(get|post|put|patch|delete)(?:<[^\n]*?>)?\(\s*(['\"`])(/api/[^'\"`]+)\2")
def calls(s):
 result={(m.upper(),mask(p)) for m,_,p in pattern.findall(s)}
 # Resolve only known, closed W1-W4 wrappers; no arbitrary JS evaluation.
 local=s
 if 'const invoiceUrl =' in local:
  local=re.sub(r"\$\{invoiceUrl\(enquiryId, invoiceId\)\}", '/api/projects/enquiries/{enquiryId}/invoices/{invoiceId}', local)
  local=local.replace('api.post(invoiceUrl(enquiryId),', "api.post('/api/projects/enquiries/{enquiryId}/invoices',")
  local=local.replace('api.put(invoiceUrl(enquiryId, invoiceId),', "api.put('/api/projects/enquiries/{enquiryId}/invoices/{invoiceId}',")
 if 'const billUrl =' in local:
  local=re.sub(r"\$\{billUrl\(id\)\}", '/api/procurement-stores/bills/{id}', local)
  local=local.replace('api.put(billUrl(id),', "api.put('/api/procurement-stores/bills/{id}',")
 base=re.search(r"const base = ['\"](/api/[^'\"]+)['\"]",local)
 if base:
  local=local.replace('${base}',base[1])
  local=local.replace('api.post(base,', "api.post('"+base[1]+"',")
  local=local.replace('${requisitionUrl(id)}',base[1]+'/requisitions/{id}')
 result|={(m.upper(),mask(p)) for m,_,p in pattern.findall(local)}
 return result
current=set();baseline=set()
for file in (frontend/'src/modules/finance').rglob('*'):
 if file.suffix not in ['.vue','.ts'] or file.name.endswith('.spec.ts'):continue
 current |= calls(file.read_text())
 old=subprocess.run(['git','show','HEAD:'+str(file.relative_to(frontend))],cwd=frontend,capture_output=True,text=True)
 if old.returncode==0:baseline |= calls(old.stdout)
# Explicit finite dispatch of FinanceSearch; each permission and method is visible in the component.
search=[('GET','/api/finance/invoices'),('GET','/api/finance/receipts'),('GET','/api/finance/payables/bills'),('GET','/api/finance/payables/payments'),('GET','/api/finance/spend-vouchers'),('POST','/api/procurement-stores/search/purchase-orders')]
current |= set(search) | {('GET','/api/projects/enquiries'),('GET','/api/clientservice/clients'),('POST','/api/procurement-stores/search/suppliers'),('GET','/api/finance/journals'),('GET','/api/finance/payroll')}
# PurchasingDocumentsView dispatches over a closed three-member Kind union.
current.discard(('POST', '/api/procurement-stores/search/{}'))
for kind in ['requisitions','purchase-orders','goods-receipt-notes']:
 current |= {('GET','/api/procurement-stores/'+kind),('GET','/api/procurement-stores/'+kind+'/{}')}
current |= {('POST','/api/procurement-stores/search/requisitions'),('GET','/api/procurement-stores/goods-receipt-notes/search')}

new=current-baseline
unmatched=sorted(current-registered);new_unmatched=sorted(new-registered)
for m,p in sorted(current):print(('PASS' if (m,p) in registered else 'UNRESOLVED EXISTING STATIC PATH'),m,p)
print(f'{len(current)} static/finite-dispatch endpoints; {len(new)} new; {len(new_unmatched)} newly unmatched; {len(unmatched)} unresolved static paths (including preexisting template-prefix inference limits).')
(Path(__file__).parent/'api-contract.json').write_text(json.dumps({'new':sorted(new),'newly_unmatched':new_unmatched,'unresolved_static':unmatched,'methodology':'Literal Finance API calls plus explicit FinanceSearch finite dispatch, normalized Laravel parameters. W1-W7 tests cover existing variable wrappers.'},indent=2))
raise SystemExit(bool(new_unmatched or unmatched))
