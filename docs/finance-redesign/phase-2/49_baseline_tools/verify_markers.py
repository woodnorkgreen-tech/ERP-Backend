#!/usr/bin/env python3
"""Evaluate every migration's schema markers against a schema export.
usage (run in the directory holding migration_markers.tsv):
  verify_markers.py <tables.txt> <columns.tsv>   (SHOW TABLES; information_schema TABLE_NAME\tCOLUMN_NAME)
Verdict per migration: PRESENT (all markers hold), ABSENT (none hold), PARTIAL, NO_MARKER.
Later migrations can legitimately undo an earlier one's marker (a column added then dropped/renamed,
a table created then dropped), so a marker superseded later in the sequence is SUPERSEDED, not failed."""
import sys, collections
tables = {l.strip() for l in open(sys.argv[1]) if l.strip()}
cols = collections.defaultdict(set)
for l in open(sys.argv[2]):
    p = l.rstrip('\n').split('\t')
    if len(p) == 2: cols[p[0]].add(p[1])
rows = [l.rstrip('\n').split('\t') for l in open('migration_markers.tsv')]
# supersession: markers negated by a later migration
later_drop_tbl, later_drop_col, later_ren = set(), set(), set()
seq = []
for name, m in rows:
    seq.append((name, m.split() if m else []))
def holds(mk):
    kind, rest = mk.split(':', 1)
    if kind == 'table': return rest in tables
    if kind == 'table-absent': return rest not in tables
    if kind in ('column', 'changed'):
        t, c = rest.split('.', 1); return c in cols.get(t, set())
    if kind == 'dropped':
        t, c = rest.split('.', 1); return t in tables and c not in cols.get(t, set())
    if kind == 'table-renamed':
        a, b = rest.split('->'); return b in tables and a not in tables
    if kind == 'renamed':
        t, cc = rest.split('.', 1); o, n = cc.split('->'); return n in cols.get(t, set())
    return False
# compute which markers are superseded by any later migration
superseded = collections.defaultdict(set)
for i, (name, mks) in enumerate(seq):
    for mk in mks:
        kind, rest = mk.split(':', 1)
        for name2, mks2 in seq[i+1:]:
            for m2 in mks2:
                k2, r2 = m2.split(':', 1)
                if kind == 'table' and k2 == 'table-absent' and r2 == rest: superseded[name].add(mk)
                if kind in ('column','changed') and k2 == 'dropped' and r2 == rest: superseded[name].add(mk)
                if kind in ('column','changed') and k2 == 'renamed' and r2.split('->')[0] == rest: superseded[name].add(mk)
                if kind in ('column','changed') and k2 == 'table-absent' and r2 == rest.split('.')[0]: superseded[name].add(mk)
                if kind == 'dropped' and k2 in ('column',) and r2 == rest: superseded[name].add(mk)
                if k2 == 'table-renamed':
                    a = r2.split('->')[0]
                    if kind == 'table' and rest == a: superseded[name].add(mk)
                    if kind in ('column','changed','dropped','renamed') and rest.split('.')[0] == a: superseded[name].add(mk)
                if kind == 'table-absent' and k2 == 'table' and r2 == rest: superseded[name].add(mk)
out = collections.Counter(); detail = []; verdict_lines = []
for name, mks in seq:
    live = [m for m in mks if m not in superseded[name]]
    if not mks: v = 'NO_MARKER'
    elif not live: v = 'SUPERSEDED'
    else:
        h = [holds(m) for m in live]
        v = 'PRESENT' if all(h) else ('ABSENT' if not any(h) else 'PARTIAL')
        if v != 'PRESENT': detail.append((name, v, [m for m, x in zip(live, h) if not x]))
    out[v] += 1
    verdict_lines.append(f'{name}\t{v}')
open('verdicts.tsv', 'w').write('\n'.join(verdict_lines) + '\n')
print(dict(out))
for d in detail: print(*d)
