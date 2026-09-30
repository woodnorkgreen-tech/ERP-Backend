#!/usr/bin/env python3
"""Report 49 tooling: inventory + schema markers for every migration Laravel loads.

usage: python3 classify_migrations.py <out_dir>
Writes migration_inventory.tsv/.json and migration_markers.tsv into <out_dir>. Read-only on the repo.

Reads files from the working tree (branch HEAD). Cross-references:
  - origin/master tree (present on master => deployed code)
  - local dev ledger (ran on the dev DB) and its batch
Outputs a TSV + a summary.
"""
import os, re, subprocess, sys, json, collections

ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '..'))
# Output directory (default: cwd). Optional input: dev_ledger_batch.txt (migration<TAB>batch) in that directory.
SCR = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.getcwd()
DIRS = [
    'database/migrations',
    'app/Modules/ArchivalTask/database/migrations',
    'app/Modules/Finance/Database/Migrations',
    'app/Modules/HR/Database/Migrations',
    'app/Modules/MaterialsLibrary/Database/Migrations',
    'app/Modules/ProcurementStores/Database/Migrations',
    'app/Modules/Design/Database/Migrations',
    'app/Modules/UniversalTask/Database/Migrations',
    'app/Modules/Production/Database/Migrations',
    'app/Modules/Notifications/Database/Migrations',
    'app/Modules/Assets/Database/Migrations',
    'app/Modules/Printing/Database/Migrations',
]

def git(*a):
    return subprocess.run(['git', '-C', ROOT, *a], capture_output=True, text=True).stdout

master_files = set(git('ls-tree', '-r', '--name-only', 'origin/master').split('\n'))
ledger = {}
for line in (open(os.path.join(SCR, 'dev_ledger_batch.txt')) if os.path.exists(os.path.join(SCR, 'dev_ledger_batch.txt')) else []):
    p = line.rstrip('\n').split('\t')
    if len(p) == 2:
        ledger[p[0]] = int(p[1])

def up_body(src):
    m = re.search(r'function\s+up\s*\([^)]*\)[^{]*\{', src)
    if not m:
        return src
    i, depth = m.end(), 1
    while i < len(src) and depth:
        if src[i] == '{': depth += 1
        elif src[i] == '}': depth -= 1
        i += 1
    return src[m.end():i - 1]

rows = []
for d in DIRS:
    full = os.path.join(ROOT, d)
    if not os.path.isdir(full):
        continue
    for f in sorted(os.listdir(full)):
        if not f.endswith('.php'):
            continue
        path = f'{d}/{f}'
        src = open(os.path.join(full, f), encoding='utf-8', errors='replace').read()
        up = up_body(src)
        name = f[:-4]
        creates = re.findall(r"Schema::(?:connection\([^)]*\)->)?create\(\s*['\"]([\w]+)['\"]", up)
        alters = re.findall(r"Schema::(?:connection\([^)]*\)->)?table\(\s*['\"]([\w]+)['\"]", up)
        drops_tbl = re.findall(r"Schema::drop(?:IfExists)?\(\s*['\"]([\w]+)['\"]", up)
        renames_tbl = re.findall(r"Schema::rename\(\s*['\"]([\w]+)['\"]", up)
        drop_col = len(re.findall(r"->dropColumn\(|->dropColumns\(|->dropSoftDeletes|->dropTimestamps|->dropMorphs", up))
        rename_col = len(re.findall(r"->renameColumn\(", up))
        change = len(re.findall(r"->change\(\)", up))
        raw = re.findall(r"DB::(statement|unprepared)\(", up)
        raw_text = ' '.join(re.findall(r"DB::(?:statement|unprepared)\(\s*(['\"])(.{0,120})", up, flags=re.S) and [x[1] for x in re.findall(r"DB::(?:statement|unprepared)\(\s*(['\"])(.{0,120})", up, flags=re.S)] or [])
        raw_destr = bool(re.search(r"\b(DROP\s+(TABLE|COLUMN)|TRUNCATE|DELETE\s+FROM|ALTER\s+TABLE\s+\S+\s+DROP)\b", up, re.I))
        data_ops = len(re.findall(r"DB::table\([^)]*\)\s*(?:->\s*\w+\([^;]*?\))*?\s*->\s*(insert|update|delete|upsert|insertOrIgnore|updateOrInsert|truncate)\b", up, re.S))
        data_ops += len(re.findall(r"->(truncate)\(\)", up))
        model_ops = len(re.findall(r"\b(?!Schema\b)[A-Z]\w*::(?:create|updateOrCreate|firstOrCreate|insert|query|where|whereIn|whereNull)\(", up)) + len(re.findall(r"->(?:update|delete|insert)\(\s*\[", up))
        artisan = len(re.findall(r"Artisan::call|->call\(|\(new \w+Seeder|Seeder::class", up))
        deletes = bool(re.search(r"->delete\(\)|->truncate\(\)|DELETE\s+FROM|TRUNCATE", up, re.I))
        guard = bool(re.search(r"Schema::has(Table|Column|Index)|hasColumn|hasTable|hasIndex|IF\s+NOT\s+EXISTS|IF\s+EXISTS|insertOrIgnore|updateOrInsert|firstOrCreate|updateOrCreate|upsert", up, re.I))
        creates_guarded = bool(re.search(r"(if\s*\(\s*!\s*Schema::hasTable|Schema::hasTable\([^)]*\)\s*\)\s*\{?\s*return|unless.*hasTable)", up))
        drop_then_create = bool(set(drops_tbl) & set(creates))
        on_master = path in master_files
        rows.append(dict(
            name=name, path=path, module=d.split('/')[2] if d.startswith('app/') else 'root',
            on_master=on_master, dev_batch=ledger.get(name),
            creates=creates, alters=alters, drops_tbl=drops_tbl, renames_tbl=renames_tbl,
            drop_col=drop_col, rename_col=rename_col, change=change, raw=len(raw), raw_destr=raw_destr,
            data_ops=data_ops, model_ops=model_ops, artisan=artisan, deletes=deletes,
            guard=guard, creates_guarded=creates_guarded, drop_then_create=drop_then_create,
        ))

# Laravel runs all loaded migrations sorted by basename (Migrator::getMigrationFiles sorts by name).
rows.sort(key=lambda r: r['name'])

# duplicates of basename across dirs
cnt = collections.Counter(r['name'] for r in rows)
create_by_table = collections.defaultdict(list)
for r in rows:
    for t in r['creates']:
        create_by_table[t].append(r['name'])

def classes(r):
    c = []
    if not r['on_master']:
        c.append('PHASE_2B_NEW')
    if r['data_ops'] or r['model_ops'] or r['artisan']:
        c.append('DATA')
    destructive = r['drops_tbl'] or r['drop_col'] or r['rename_col'] or r['renames_tbl'] or r['change'] or r['raw_destr'] or r['deletes']
    if destructive:
        c.append('POTENTIALLY_DESTRUCTIVE')
    only_guarded = r['guard'] and not destructive
    if only_guarded:
        c.append('SAFE_IDEMPOTENT')
    if r['on_master']:
        # present on master: its schema is *likely* present in production only if production
        # tracks master. Structural additive migrations need comparison; flag everything
        # on master as requiring comparison unless guarded.
        if r['guard'] and not destructive:
            c.append('LIKELY_PRESENT_GUARDED')
        else:
            c.append('LEGACY_REQUIRES_SCHEMA_COMPARISON')
    return c

out = open(os.path.join(SCR, 'migration_inventory.tsv'), 'w')
out.write('seq\tmigration\tmodule\ton_master\tdev_batch\tclasses\tcreates\talters\tdrops\tnotes\n')
for i, r in enumerate(rows, 1):
    notes = []
    if r['drop_then_create']: notes.append('DROP-THEN-CREATE')
    if r['drop_col']: notes.append(f"dropColumn x{r['drop_col']}")
    if r['rename_col']: notes.append(f"renameColumn x{r['rename_col']}")
    if r['change']: notes.append(f"->change() x{r['change']}")
    if r['raw']: notes.append(f"raw SQL x{r['raw']}")
    if r['raw_destr']: notes.append('raw DROP/TRUNCATE/DELETE')
    if r['deletes']: notes.append('deletes rows')
    if cnt[r['name']] > 1: notes.append('DUPLICATE BASENAME')
    for t in r['creates']:
        if len(create_by_table[t]) > 1: notes.append(f'{t} created by {len(create_by_table[t])} migrations')
    if r['creates'] and not r['creates_guarded'] and not r['guard']: notes.append('unguarded create')
    r['classes'] = classes(r); r['notes'] = notes
    out.write('\t'.join(map(str, [i, r['name'], r['module'], 'Y' if r['on_master'] else 'N', r['dev_batch'] or '',
        ','.join(r['classes']), ','.join(r['creates']), ','.join(sorted(set(r['alters']))), ','.join(r['drops_tbl']), '; '.join(notes)])) + '\n')
out.close()
json.dump(rows, open(os.path.join(SCR, 'migration_inventory.json'), 'w'), default=str)

# ---- summary
S = collections.Counter()
for r in rows:
    for c in r['classes']: S[c] += 1
print('TOTAL loaded migrations:', len(rows))
print('on master:', sum(r['on_master'] for r in rows), ' branch-only (Phase 2B new):', sum(not r['on_master'] for r in rows))
print('not in dev ledger:', [r['name'] for r in rows if r['dev_batch'] is None])
print('dev ledger entries with no file:', sorted(set(ledger) - {r['name'] for r in rows})[:50])
for k, v in sorted(S.items()): print(f'  {k}: {v}')
print('duplicate basenames:', [n for n, c in cnt.items() if c > 1])
print('tables created by >1 migration:', {t: v for t, v in create_by_table.items() if len(v) > 1})
print('drop-then-create:', [r['name'] for r in rows if r['drop_then_create']])
print('first 12 in execution order:')
for r in rows[:12]:
    print('  ', r['name'], r['creates'], 'guarded' if (r['guard'] or r['creates_guarded']) else 'UNGUARDED')

# ---- markers: what to look for in the production-copy schema to call a migration PRESENT
NONCOL = {'index','unique','primary','foreign','dropColumn','dropColumns','dropIndex','dropUnique','dropForeign','dropPrimary',
          'renameColumn','renameIndex','dropSoftDeletes','dropTimestamps','dropMorphs','dropConstrainedForeignId','dropForeignIdFor',
          'fullText','spatialIndex','comment','engine','charset','collation','temporary','after','first','change','dropIfExists'}
def table_blocks(up):
    for m in re.finditer(r"Schema::(?:connection\([^)]*\)->)?table\(\s*['\"](\w+)['\"]\s*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?\{", up):
        i, depth = m.end(), 1
        while i < len(up) and depth:
            if up[i] == '{': depth += 1
            elif up[i] == '}': depth -= 1
            i += 1
        yield m.group(1), up[m.end():i-1]
mk = open(os.path.join(SCR, 'migration_markers.tsv'), 'w')
for r in rows:
    src = open(os.path.join(ROOT, r['path']), encoding='utf-8', errors='replace').read()
    up = up_body(src)
    markers = [f"table:{t}" for t in r['creates']]
    for t, body in table_blocks(up):
        for meth, col in re.findall(r"\$table->(\w+)\(\s*['\"](\w+)['\"]", body):
            if meth in NONCOL or meth.startswith('drop') or meth.startswith('rename'):
                continue
            if 'change()' in body.split(col,1)[1].split(';',1)[0]:
                markers.append(f"changed:{t}.{col}")
            else:
                markers.append(f"column:{t}.{col}")
        for old, new in re.findall(r"renameColumn\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]", body):
            markers.append(f"renamed:{t}.{old}->{new}")
        for col in re.findall(r"dropColumn\(\s*['\"](\w+)['\"]", body):
            markers.append(f"dropped:{t}.{col}")
    for t in r['drops_tbl']:
        if t not in r['creates']:
            markers.append(f"table-absent:{t}")
    for a, b in re.findall(r"Schema::rename\(\s*['\"](\w+)['\"]\s*,\s*['\"](\w+)['\"]", up):
        markers.append(f"table-renamed:{a}->{b}")
    for a, b in re.findall(r"RENAME\s+TABLE\s+`?(\w+)`?\s+TO\s+`?(\w+)`?", up, re.I):
        markers.append(f"table-renamed:{a}->{b}")
    for t, body in table_blocks(up):
        for arr in re.findall(r"dropColumn\(\s*\[([^\]]*)\]", body):
            for col in re.findall(r"['\"](\w+)['\"]", arr):
                markers.append(f"dropped:{t}.{col}")
    added = {m.split(':',1)[1] for m in markers if m.startswith('column:')}
    markers = [m for m in markers if not (m.startswith('dropped:') and m.split(':',1)[1] in added)]
    r['markers'] = markers
    mk.write(r['name'] + '\t' + ' '.join(markers) + '\n')
mk.close()
json.dump(rows, open(os.path.join(SCR, 'migration_inventory.json'), 'w'), default=str)
nomark = [r['name'] for r in rows if not r['markers']]
print('migrations with no automatic schema marker (data/raw/index-only):', len(nomark))
