# Petty Cash / Finance Refactor — Task Plan

> Companion to [REFACTOR_GUIDE.md](./REFACTOR_GUIDE.md). Execute phases in order; each phase is
> independently shippable and must end green on the **reconciliation invariant**
> (`current_balance == rebuildFromLedger()`) and the **contract/golden tests**.
>
> Legend: **[BE]** backend · **[FE]** frontend · **[T]** test · Effort: S(<½d) M(~1d) L(2-3d)
> Audit refs (C#/H#/M#) map to findings in the audit / REFACTOR_GUIDE §1.

---

## Phase 0 — Safety net & authorization (no behavior change)

- [ ] **BE-0 · Align authorization** *(M)* — Map every FE permission key in
  `composables/usePermissions.ts` (`finance.petty_cash.*`) to a backend ability. Introduce/confirm a
  `PettyCashPolicy` (follow the `OvertimePolicy` reference pattern from HR) guarding void, clear-all,
  recalculate, delete, upload, edit. Keep keys byte-identical to the FE.
  - *Accept:* unauthorized calls return 403 in the frozen envelope; FE permission checks still pass.
  - *Dep:* none.
- [ ] **T-1 · Characterization tests** *(M)* — Golden-fixture tests capturing CURRENT response
  envelopes/keys for: create/list disbursement, create/list top-up, void, `/balance`, `/transactions`,
  `/summary`, `/top-ups/available`, import result shape. These freeze §3 contracts.
  - *Accept:* suite green against current code; fixtures committed.
- [ ] **T-2 · Reconciliation invariant test** *(S)* — Helper asserting
  `current_balance == sum(credits) - sum(debits)` from `petty_cash_ledger_entries` after an operation
  sequence. Will be reused as the master guardrail in every later phase.
  - *Accept:* passes today for top-up→disburse→void sequence (document any pre-existing drift).

---

## Phase 1 — Introduce single balance writer (`LedgerService`)

- [ ] **BE-1 · `LedgerEntry` value object** *(S)* — DTO with `creditFor($topUp)`,
  `debitFor($disbursement)`, `reversalFor($disbursement,$reason)`, `toRow($snapshot)` producing the
  EXACT existing ledger column/metadata shape (keep `TOP-/PCR-/VOID-` prefixes & all JSON keys).
  - *Dep:* none.
- [ ] **BE-2 · `LedgerService::post()`** *(M)* — The only balance writer: `lockForUpdate` on singleton,
  `bcadd/bcsub` decimal math, atomic balance update + ledger insert in one `DB::transaction`.
  - *Accept:* unit tests for credit/debit/reversal; concurrency test (two posts) shows no lost update.
  - *Dep:* BE-1.
- [ ] **BE-3 · `LedgerService::rebuildFromLedger()`** *(S)* — Authoritative recompute from ledger;
  back `PettyCashService::recalculateBalance()` with it. Return shape unchanged
  (`old/new/difference/recalculated_at`).
  - *Accept:* T-2 invariant holds; existing recalculate endpoint response identical.
  - *Dep:* BE-2.
- [ ] **BE-4 · Events delegate to `LedgerService`** *(M)* — `PettyCashTopUp::boot` /
  `PettyCashDisbursement::boot` call `LedgerService::post()` instead of private `updateBalance()`.
  **No behavior change yet** (events still fire). Remove dead guarded methods later.
  - *Accept:* T-1 + T-2 green; balance values identical to pre-change on the happy paths.
  - *Dep:* BE-2.
- [x] **BE-5 · Kill manual transactions (fix C1)** *(M)* — Replace every
  `DB::beginTransaction/commit/rollBack` in `PettyCashService` with closure `DB::transaction()`.
  Specifically remove the early-`return` paths in `createDisbursement` that leak an open tx + lock
  (insufficient balance, no-top-up).
  - *Accept:* new test — a disbursement exceeding balance leaves NO open transaction and releases the
    lock (assert `DB::transactionLevel() === 0` after the call); concurrent request not blocked.
  - *Dep:* BE-2.

---

## Phase 2 — Allocation & import normalization

- [x] **BE-6 · `TopUpAllocator`** *(L)* — `allocate($amount,$cost)` (strict, FIFO split across
  top-ups, throws `InsufficientFundsException` on aggregate shortfall, includes `transaction_cost`)
  and `allocateLenient(...)` (import; allows negative, still splits + includes cost). Remaining
  computed in ONE SQL query (`SUM(amount + COALESCE(transaction_cost,0))` grouped) — fixes M1.
  - *Accept:* unit tests for: single-topup cover, multi-topup split (C4 case 600+600 vs 1000),
    shortfall throws, lenient negative, cost inclusion.
  - *Dep:* none (pure).
- [x] **BE-7 · Wire allocator into `createDisbursement`** *(M)* — Replace inline lines ~155-183 fallback
  chain; set `top_up_id` = first allocation (back-compat). Catch domain exceptions → `{success:false,
  errors:{amount:[...]}}` (frozen shape).
  - *Accept:* T-1 contract green; new negative-remaining regression test passes; CC of method ≤ 5.
  - *Dep:* BE-6, BE-5.
- [ ] **BE-8 · `RowNormalizer`** *(M)* — Extract classification map, tax map, amount cleaning, and
  **day-first date parsing** (fix H4: try `d/m/Y` before `m/d/Y`, then ISO) from the 3 duplicated
  import methods. Unit-tested.
  - *Accept:* date `03/04/2025` → 3 April; classification/tax maps match current outputs for known
    inputs; amount `"1,200.50 KES"` → `1200.50`.
  - *Dep:* none.
- [ ] **BE-9 · Refactor import to use allocator + normalizer** *(M)* — `getSuitableTopUp` /
  `prepareDisbursementData` delegate to BE-6/BE-8; fix transaction_cost omission (H3).
  - *Accept:* import golden-file test on a representative historical sheet still produces the same
    successful/failed/duplicate partition (allowing the corrected date/cost behavior, documented).
  - *Dep:* BE-6, BE-8.
- [ ] **BE-10 · (Optional) allocations table** *(M)* — Add-only migration
  `petty_cash_disbursement_allocations`; persist splits; expose additive `allocations[]` in the
  disbursement resource (optional field).
  - *Accept:* migration is add-only; resource change is additive (contract test: no removals).
  - *Dep:* BE-7.

---

## Phase 3 — Decouple balance from ORM events

- [ ] **BE-11 · Move writes into service; strip `boot()` balance mutation** *(L)* — Remove balance
  closures from both models. `createTopUp`/`createDisbursement`/`voidDisbursement` call
  `LedgerService::post()` explicitly. Delete dead guarded methods on `PettyCashBalance`
  (`addTopUp`/`subtractDisbursement`) or repurpose them as the guarded internals of `LedgerService`.
  - *Accept:* T-2 invariant green; all create/void balances identical to Phase 0 fixtures.
  - *Dep:* BE-4 (delegation already in place), BE-7.
- [ ] **BE-12 · Fix archive semantics (C2/C3)** *(M)* — Archiving posts NO ledger entry (balance
  unchanged); single and bulk archive behave identically (both pure flag updates). Document that cash
  reversal is **void-only**.
  - *Accept:* regression test — archiving 5 active disbursements singly vs in bulk yields the SAME
    (unchanged) balance.
  - *Dep:* BE-11.
- [ ] **BE-13 · Negative-balance guard (H2)** *(S)* — Centralize the guard in `LedgerService`
  (configurable: hard-block for manual, allow for `allocateLenient` imports). Remove silent-negative
  `save()` override or document the lenient exception explicitly.
  - *Accept:* manual disbursement cannot drive balance < 0; import path can, and flags it in results.
  - *Dep:* BE-11.

---

## Phase 4 — Remaining correctness fixes

- [ ] **BE-14 · `clearAllData` posts reversing entry (C5)** *(M)* — Either clear
  `petty_cash_ledger_entries` within the same transaction OR post a single reversing entry to zero,
  so the flat-transaction view (`getFlatTransactions`) matches the empty state.
  - *Accept:* after clear-all, `/transactions` returns empty and `/balance` is 0; invariant holds.
  - *Dep:* BE-2.
- [ ] **BE-15 · Bill-payment includes cost + no silent swallow (H5)** *(S)* —
  `createBillPaymentFromDisbursement` records `amount + transaction_cost`; surface failures (don't
  swallow) or move outside the cash transaction with a clear compensating log/alert.
  - *Accept:* bill payment amount == total cash out; a failing bill-payment is observable.
  - *Dep:* BE-11.
- [ ] **BE-16 · Import transaction scoping & lock duration (M2)** *(M)* — Avoid holding the singleton
  lock for the entire import; commit per-chunk or restructure so other petty-cash writes aren't
  blocked for the whole upload. Keep `chunkSize`.
  - *Accept:* import of N rows does not block a concurrent `/balance` read for the full duration
    (staging load test); partial-failure semantics documented.
  - *Dep:* BE-9.
- [x] **BE-17 · ~~Fix `getProjectBudgetsSummary` double full-table scan (M3)~~ — CLOSED BY DELETION**
  (2026-08). The method is gone, along with its route, its controller action, the unread
  `budget_snapshot` in `workspace()`, and the frontend service call. Nothing consumed it: the
  standalone endpoint had no caller, and the snapshot was computed on every dashboard refresh and
  discarded.
  - It was also *wrong*, not merely slow: it split spend four ways by `budget_category`, a column
    null on every disbursement, so three of its four figures were always zero and the fourth
    absorbed everything.
  - Replaced by `GET /api/costs/accounts` (`CostAccountService::index`), which aggregates in SQL
    and paginates on the aggregate, reading real figures from the cost ledger.

---

## Phase 5 — Frontend

- [ ] **FE-1 · Remove backend-bug compensation** *(S)* — In `pettyCashService.ts`, restore normal
  error propagation for `getAvailableTopUps()` (stop swallowing 500 → `{data:[]}`) now that BE is
  fixed/tested. Audit store/composables for similar masking.
  - *Accept:* store surfaces a real error state on failure; existing success path unchanged.
  - *Dep:* BE-6/BE-7 deployed.
- [ ] **FE-2 · Insufficient-funds UX** *(S)* — Verify `DisbursementForm.vue` renders the precise
  `errors.amount` message ("Available X, Required Y") from the new allocator response.
  - *Accept:* manual test + Vitest on store action mapping `errors.amount`.
  - *Dep:* BE-7.
- [ ] **FE-3 · (Optional) allocations display** *(M)* — If BE-10 ships, render the split in
  `TransactionDetailModal.vue`, gated on the optional `allocations[]` field.
  - *Accept:* renders when present; no change when absent.
  - *Dep:* BE-10.
- [ ] **FE-4 · Align label maps** *(S)* — `getPaymentMethodLabel` is missing bank options present in
  the backend enum (`equity/stanbic/ncba/kcb/family`). Sync FE labels with BE enums.
  - *Accept:* every backend payment method has a FE label.
  - *Dep:* none.
- [ ] **FE-5 · (Optional) extract `httpClient`** *(M)* — Move retry/transform/error-classification out
  of `pettyCashService.ts` into a reusable client; service becomes endpoint definitions only.
  Behavior-preserving.
  - *Accept:* FE Vitest green; no change to method signatures.
  - *Dep:* none.
- [ ] **FE-T · Store/service Vitest** *(M)* — Tests for error mapping, permission-error transform, and
  the de-compensated endpoints.

---

## Cross-cutting / DoD checklist

- [ ] Reconciliation invariant (T-2) green in CI after every phase.
- [ ] Contract/golden tests (T-1) show zero removals/renames on any frozen response.
- [ ] One regression test per closed audit defect (C1–C5, H1–H6, M1–M3).
- [ ] `graphify update .` run after backend edits (keeps the knowledge graph current).
- [ ] No manual `DB::beginTransaction` left in the module.
- [ ] FE: no remaining client-side compensation for backend bugs.

---

## Suggested execution order (critical path)

```
BE-0, T-1, T-2            (Phase 0 — do first, in parallel)
   → BE-1 → BE-2 → {BE-3, BE-4, BE-5}        (Phase 1)
   → BE-6 → BE-7 ; BE-8 → BE-9               (Phase 2, two parallel tracks)
   → BE-11 → {BE-12, BE-13}                  (Phase 3)
   → {BE-14, BE-15, BE-16, BE-17}            (Phase 4, parallelizable)
   → {FE-1, FE-2, FE-4} → FE-3/FE-5          (Phase 5)
```

Highest leverage / lowest risk to start: **BE-5 (C1 fix)** and **BE-2 + BE-4** — they neutralize the
most severe defects with minimal surface change.
