# Petty Cash / Finance Module — Refactor & Redesign Guide

> Status: **Design (pre-implementation)**
> Author: Architecture
> Scope: `ERP-Backend/app/Modules/Finance/PettyCash` + `ERP-Frontend/src/modules/finance`
> Companion: [REFACTOR_TASKS.md](./REFACTOR_TASKS.md)

---

## 1. Why this refactor

The module works but carries severe structural risk concentrated in **balance management**. The
prior audit (see `git` history / PR description) found correctness defects that all trace to a
single root cause:

> **There is no single source of truth for the petty-cash balance, and balance mutation is coupled
> into Eloquent lifecycle events.**

There are currently **four** independent definitions of "the balance":

1. Model boot events (`PettyCashDisbursement::boot`, `PettyCashTopUp::boot`) — incremental `+=/-=`.
2. The service layer (`PettyCashService`) — locks the row, then *relies on* those events.
3. The repository — bulk `whereIn()->update()` that **bypasses events** entirely.
4. `PettyCashBalance::recalculateBalance()` — a from-scratch re-derivation.

Because these disagree, the same business action produces different balances depending on the path
taken (single vs bulk archive, import vs manual entry, edit vs recalc).

### Defects this redesign eliminates by construction

| ID | Defect | Root cause removed |
|----|--------|--------------------|
| C1 | Dangling DB transaction + leaked row lock on early `return` | Manual `beginTransaction` replaced by closure `DB::transaction()` |
| C2 | Single archive moves balance; bulk archive does not | Single balance writer; no event-based mutation |
| C3 | Archiving an active disbursement silently inflates cash | Archive ≠ reversal; only an explicit reversal posts to the ledger |
| C4/H3 | Disbursement over-allocated to one top-up → negative remaining | `TopUpAllocator` splits FIFO across top-ups |
| C5 | `clearAllData` orphans the ledger | Reset posts a reversing ledger entry; ledger is source of truth |
| H1 | Float `!==` drift on balance math | `bc*` decimal math throughout |
| H2 | Negative balance via unguarded `updateBalance()` | All writes funnel through `LedgerService::post()` with guards |
| H6 | Ledger/balance divergence after top-up edit | Every balance move is paired atomically with a ledger row |

---

## 2. Goals & non-goals

### Goals
- One **atomic, locked, decimal-safe** writer for the balance, paired 1:1 with ledger entries.
- The **ledger (`petty_cash_ledger_entries`) becomes the source of truth**; `current_balance` is a
  cached projection that can always be rebuilt from the ledger.
- Reduce cyclomatic complexity of the hot methods (`createDisbursement` ~12 → ~4) and decouple
  orchestration from persistence, allocation, normalization, and balance math.
- **Zero breaking changes** to the HTTP API, response envelopes, or the frontend.
- Test coverage that locks current behavior *before* refactoring (characterization tests).

### Non-goals (explicitly out of scope for this pass)
- Changing the REST endpoint paths, request bodies, or JSON response shapes.
- Multi-currency, double-entry general ledger, or chart-of-accounts posting (future).
- Re-skinning the frontend UI. FE changes are limited to consuming new optional response fields and
  removing client-side compensation for backend bugs.
- Changing the requisition approval workflow semantics.

---

## 3. Contracts that MUST be preserved (compatibility surface)

These are frozen. Any change here is a separate, versioned decision.

### 3.1 HTTP API (consumed by `pettyCashService.ts`, base `/api/finance/petty-cash`)
All current routes and verbs, including:
- `GET/POST/PUT/DELETE /disbursements`, `/disbursements/{id}`, `/disbursements/{id}/void`,
  `/disbursements/bulk-delete`
- `GET/POST/PUT/DELETE /top-ups`, `/top-ups/{id}`, `/top-ups/available`,
  `/top-ups/{id}/available-balance`
- `GET /balance`, `POST /balance/check`, `POST /balance/recalculate`
- `GET /transactions`, `/search`, `/recent`, `/activity-logs`
- `POST /transactions/{id}/archive`, `/transactions/bulk-archive`,
  `/transactions/{id}/archive-group`, `/transactions/bulk-archive-groups`
- `GET /summary`, `/voucher`, `/voucher/pdf`, `/budgets/summary`, `/projects`, `/accounts`
- `POST /upload-excel`, `GET /download-template`
- Public: `/api/public/petty-cash/*`
- `DELETE /clear-all`

### 3.2 Response envelope (frozen)
```jsonc
// success
{ "success": true, "data": <payload>, "message"?: "..." }
// validation / domain failure
{ "success": false, "message": "...", "errors": { "field": ["..."] } }
```
Status codes stay as-is (422 validation, 403 permission, 500 server). The FE error pipeline in
`pettyCashService.ts` (`transformError`, status→code map) depends on these.

### 3.3 Service public signatures (internal callers + tests depend on these)
- `createDisbursement(array $data): array` → `['success'=>true,'data'=>Model]` **or**
  `['success'=>false,'errors'=>[...]]` (asymmetric — keep exactly).
- `createTopUp(array): PettyCashTopUp`, `updateTopUp(...): PettyCashTopUp`,
  `voidDisbursement($d, string): bool`, `recalculateBalance(): array`,
  `getCurrentBalanceInfo(): array`, `validateDisbursementData(): array`, `validateTopUpData(): array`.
- `updateDisbursement` / `deleteDisbursement` / `bulkDeleteDisbursements` keep **throwing** (the
  ledger-immutability guarantee — the FE expects 500/blocked).

### 3.4 Persistence contract
- Table schemas unchanged (no destructive migrations).
- `petty_cash_ledger_entries` row shape unchanged: `reference_number` prefixes `TOP-`, `PCR-`,
  `VOID-`; columns `type` (`credit|debit`), `amount`, `balance_snapshot`, `metadata` (JSON),
  `posted_at`. `PettyCashRepository::getFlatTransactions()` reconstructs the FE transaction objects
  from these — keep every JSON key it reads.
- `PettyCashTopUp::remaining_balance` and `is_fully_disbursed` accessors stay (FE + reports read them).

### 3.5 Frontend types (frozen unless additive)
`types/api.ts`, `types/pettyCash.ts`, `types/forms.ts`. New response fields must be **optional
additive** only (e.g. an allocations breakdown), never renames/removals.

---

## 4. Target backend architecture

```
HTTP Controller (thin: validate request → call service → return resource)
        │  (unchanged signatures & routes)
        ▼
PettyCashService  (orchestration only — no SQL, no balance arithmetic)
        ├─ LedgerService      → THE ONLY writer of balance + ledger (atomic, locked, bc-math)
        ├─ TopUpAllocator     → allocation strategy (replaces inline service lines 155-183)
        ├─ RowNormalizer      → shared import normalization (dedupes the 3 copies)
        ├─ RequisitionSync    → requisition status + bill-payment side effects (extracted)
        └─ PettyCashRepository→ pure persistence & queries (public API unchanged)
```

### 4.1 `LedgerService` — single source of truth
Responsibilities:
- The **only** code permitted to mutate `petty_cash_balances`.
- Every mutation is atomic with a ledger insert, under `lockForUpdate` on the singleton row, using
  `bcadd/bcsub/bccomp` (string decimal) — never float.
- Exposes: `post(LedgerEntry): PettyCashBalance`, `rebuildFromLedger(): PettyCashBalance` (the new,
  authoritative implementation behind `recalculateBalance()`).

A small `LedgerEntry` value object (DTO) builds the row: `LedgerEntry::creditFor($topUp)`,
`LedgerEntry::debitFor($disbursement)`, `LedgerEntry::reversalFor($disbursement, $reason)`. Its
`toRow($snapshot)` produces the exact column/metadata shape currently inlined in the service.

**Models lose their `boot()` balance closures.** This is the key decoupling. To preserve the
behavioral contract ("creating a disbursement changes the balance"), the *service* calls
`LedgerService::post()` explicitly — which it already half-does for the ledger, so balance and ledger
can no longer diverge.

> Migration safety: in Phase 1 the boot events are kept but **delegate** to `LedgerService` (no
> behavior change), then removed in Phase 3 once all write paths go through the service.

### 4.2 `TopUpAllocator` — allocation as a strategy
Replaces the 3-branch fallback in `createDisbursement` and the duplicate logic in the import.
- `allocate(string $amount, string $cost): Allocation[]` — strict: FIFO-fills across top-ups; throws
  `InsufficientFundsException` if the **aggregate** can't cover it. Splits instead of overflowing one
  top-up (fixes C4/H3). Includes `transaction_cost` (fixes the import's amount-only bug).
- `allocateLenient(...)` — import path; allows negative remainder (preserves `skip_balance_check`)
  but still splits correctly and includes cost.
- Backed by a **single SQL query** computing remaining via `SUM(amount + COALESCE(transaction_cost,0))`
  grouped per top-up — kills the N+1 (M1).

> `top_up_id` on the disbursement row stays as the **primary/anchor** allocation for backward compat.
> The full split is recorded additively (see §6).

### 4.3 `RowNormalizer` — shared import normalization
Extract the classification map, tax map, amount cleaning, and date parsing (currently copy-pasted
across `normalizeMappedRow`, `isDuplicate`, `prepareDisbursementData`). One class, unit-tested,
including the date-locale fix (§7).

### 4.4 Thin `createDisbursement` (signature identical)
```php
public function createDisbursement(array $data): array
{
    try {
        $disbursement = DB::transaction(function () use ($data) {
            $lenient = (bool)($data['skip_balance_check'] ?? false);
            unset($data['skip_balance_check']);

            $allocations = $this->allocator->forDisbursement($data, $lenient); // throws on shortfall
            $data['top_up_id'] = $allocations[0]->topUpId;

            $disbursement = $this->repository->createDisbursement(
                $data + ['created_by' => Auth::id(), 'status' => 'active']
            );
            $this->ledger->post(LedgerEntry::debitFor($disbursement));   // atomic balance+ledger
            $this->requisitions->syncIfLinked($disbursement);
            return $disbursement;
        });

        $this->logActivity('created', 'disbursement', $disbursement->id, /* ... */);
        return ['success' => true, 'data' => $disbursement];
    } catch (InsufficientFundsException|NoFundsAvailableException $e) {
        return ['success' => false, 'errors' => ['amount' => [$e->getMessage()]]]; // same shape
    }
}
```
`DB::transaction()` closure structurally eliminates C1 (no path can return with an open tx/lock).

---

## 5. Frontend redesign

The FE is already well-layered (service → store → composables → components). It does **not** need
restructuring; it needs to (a) stop compensating for backend bugs and (b) optionally surface the new
allocation detail. Keep all public store actions and composable signatures stable.

### 5.1 What changes
- **Remove client-side bug compensation.** e.g. `getAvailableTopUps()` swallows backend 500s and
  returns `{ data: [] }` (`pettyCashService.ts:548-567`). Once the backend endpoint is fixed and
  covered by tests, restore normal error propagation so the store/UI shows a real error state.
- **Balance trust.** Components that locally re-derive or "fix" balances should rely on
  `GET /balance` and the transaction ledger as authoritative.
- **Optional allocations display.** If the backend adds an additive `allocations[]` field to the
  disbursement response, `TransactionDetailModal.vue` can show the split. Gated on the field being
  present (additive, non-breaking).
- **Insufficient-funds UX.** The error path already maps `errors.amount` → field error. Verify
  `DisbursementForm.vue` surfaces the new precise message ("Available: X, Required: Y").

### 5.2 What stays frozen
- `services/pettyCashService.ts` method names, endpoints, return types.
- `stores/pettyCashStore.ts` public actions/getters consumed by views.
- All `types/*` (additive changes only).
- Permission keys in `composables/usePermissions.ts` (must match backend policy abilities — see
  Tasks BE-0).

### 5.3 FE cleanup opportunities (low risk, optional)
- `pettyCashService.ts` is 954 lines, ~60% generic HTTP/error plumbing. Extract the
  retry/transform/error-classification block into a reusable `httpClient` wrapper so the petty-cash
  service is just endpoint definitions. Behavior-preserving.
- Consolidate duplicated formatting helpers (`formatAmount`, `getClassificationLabel`, etc.) — note
  `getPaymentMethodLabel` is missing bank options that exist on the backend enum; align them.

---

## 6. Data model evolution (additive, non-destructive)

To record allocation splits without breaking `top_up_id`:

- **New table `petty_cash_disbursement_allocations`** (additive): `id`, `disbursement_id`,
  `top_up_id`, `amount` (decimal), `created_at`. The disbursement keeps `top_up_id` = first
  allocation for backward compat. `remaining_balance` accessor can later read from this table; until
  then it keeps its current definition (no behavior change).
- **No schema change** to `petty_cash_ledger_entries`, `petty_cash_balances`, `_top_ups`,
  `_disbursements`.
- Migration is **add-only**; safe to deploy ahead of code.

> If the team prefers to defer the allocations table, `TopUpAllocator` can still split logically and
> only persist the anchor `top_up_id` — the correctness fixes (no negative remaining, cost included)
> hold either way. The table only adds auditability.

---

## 7. Edge-case handling (worked examples)

### 7.1 Multi-top-up disbursement (today's C4)
**Setup:** Top-up A remaining 600, Top-up B remaining 600. Disburse **1,000**.

- **Today:** global check passes, full 1,000 pinned to one 600 top-up → its `remaining_balance` = **−400**.
- **After:** `allocate('1000','0')` → `[A:600, B:400]`; one debit ledger entry of 1,000 posted
  atomically; balance 1,200 → 200. No negative remaining. Response shape unchanged; optional
  `allocations: [{top_up_id:A, amount:600},{top_up_id:B, amount:400}]` added.

### 7.2 Concurrent disbursement race (today's C1)
**Setup:** Balance 200. Two simultaneous requests each disburse 150.

- **After:** both hit `lockForUpdate` on the singleton row inside `DB::transaction()`. Request 1
  commits (balance 50). Request 2 re-reads 50, `allocate('150')` throws `InsufficientFundsException`
  → `{ success:false, errors:{ amount:['Insufficient balance. Available: 50.00, Required: 150.00'] } }`.
  Transaction auto-rolled back; **no leaked lock**. (Today: request 2's tx is left open holding the
  lock, blocking everything.)

### 7.3 Archive is not a refund (today's C2/C3)
**Setup:** Active disbursement of 500, balance reflects it as spent.

- **After:** archiving sets `is_archived=true` via repository and posts **no ledger entry** → balance
  unchanged whether done singly or in bulk. Reversing cash requires the explicit **void** path
  (`VOID-` ledger credit). Single vs bulk now behave identically.

### 7.4 Excel import — ambiguous date + transaction cost (today's H3/H4)
**Setup:** Row `03/04/2025`, amount 1,000, cost 50, against top-up with 800 remaining; Kenyan DD/MM.

- **After:** `RowNormalizer` parses as **3 April** (configured day-first locale, falls back to
  ISO/`Y-m-d` and explicit formats). `allocateLenient('1000','50')` includes the 50 cost and splits;
  if only 800 available it still imports (skip_balance_check) but records the true split and lets the
  global balance go negative *visibly* (flagged in import results), instead of silently corrupting one
  top-up's remaining by an amount that ignored the cost.

### 7.5 `recalculateBalance` (rebuild)
`rebuildFromLedger()` sums credits − debits from `petty_cash_ledger_entries` (the source of truth),
writes the projection, returns `['old_balance','new_balance','difference','recalculated_at']`
(unchanged shape). This becomes the reconciliation oracle for tests.

---

## 8. Testing strategy

1. **Characterization tests first (BE-1).** Lock *current* observable behavior of the happy paths
   (create top-up, create disbursement, void, balance) before touching code, so refactors are
   provably behavior-preserving on the contract surface.
2. **Unit tests** for `LedgerService` (decimal math, lock, atomicity), `TopUpAllocator` (split, FIFO,
   shortfall, lenient), `RowNormalizer` (date locales, classification/tax maps, amount cleaning).
3. **Concurrency test** simulating two disbursements against a tight balance (DB transaction +
   pessimistic lock) — asserts no oversell and no leaked transaction.
4. **Reconciliation invariant test:** after any sequence of operations,
   `current_balance == rebuildFromLedger()`. This is the master guardrail and runs in CI.
5. **Contract tests:** assert response envelopes/keys for every endpoint are byte-stable (golden
   fixtures) so the FE is provably unaffected.
6. **FE:** Vitest unit tests for store actions against mocked endpoints; verify error mapping and the
   restored (non-swallowed) error propagation.

Per the test-harness memory: Feature tests need MySQL `db_test` (migrations are MySQL-only); Unit
tests are DB-free; run `ddev exec php artisan test`.

---

## 9. Rollout strategy (strangler / incremental — each step shippable)

- **Phase 0 — Authorization & safety net** (BE-0, BE-1): align permission abilities with FE keys;
  add characterization + reconciliation tests. No behavior change.
- **Phase 1 — Introduce `LedgerService`; events delegate to it.** Behavior identical; balance now has
  one implementation even though events still trigger it. Neutralizes C1 (convert manual tx to
  closures) immediately.
- **Phase 2 — `TopUpAllocator` + `RowNormalizer`.** Fix C4/H3, N+1 (M1), date locale (H4). Import and
  manual create both route through the allocator.
- **Phase 3 — Remove balance mutation from model `boot()`; route all writes through the service.**
  Fix C2/C3 (archive semantics), H2 (negative guard). Bulk paths post explicit entries or none,
  consistently.
- **Phase 4 — Fix `clearAllData` ledger reset (C5), bill-payment cost (H5), and import transaction
  scoping (M2).**
- **Phase 5 — FE cleanup:** remove bug-compensation, optional allocations display, httpClient
  extraction.

Each phase ends green on the reconciliation invariant + contract tests before merge.

---

## 10. Risk register

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Removing boot events breaks an untracked caller that relied on side effects | Med | Phase 1 keeps events as delegators; grep for direct `->save()`/`->update()` on models; reconciliation test catches drift |
| Import behavior change rejects previously-accepted messy rows | Med | `allocateLenient` + `RowNormalizer` preserve permissiveness; add import golden-file tests with real historical sheets |
| Concurrency lock changes cause deadlocks under load | Low | Single, consistent lock order (always balance singleton first); load test in staging |
| FE breaks on additive fields | Low | Fields are optional; contract tests assert no removals/renames |
| Decimal/float mismatch in existing data | Low | `rebuildFromLedger` one-time reconciliation on deploy; report drift before/after |

---

## 11. Definition of done

- All four balance writers collapsed into `LedgerService`; model `boot()` no longer mutates balance.
- `createDisbursement` CC ≤ 5; no manual `beginTransaction` in the module (all closures).
- Reconciliation invariant green in CI; contract/golden tests green; FE Vitest green.
- Audit defects C1–C5, H1–H6, M1–M2 closed with a regression test each.
- No change to any frozen contract in §3; FE bug-compensation removed.
