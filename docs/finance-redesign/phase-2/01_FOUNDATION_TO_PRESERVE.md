# 01 — Foundation to Preserve

Phase 1.5, Part A. Source of truth: `docs/finance-redesign/current-state/*.md` (the Phase 1
Current-State Audit, 2026-09-22). Purpose: name explicitly which architectural patterns the Phase 1
audit found sound, so that no later step in this remediation/redesign effort touches them without a
documented reason. Nothing in this document proposes new work beyond what is already flagged in the
Phase 1 audit; it is a preservation register, not a design document.

Legend:
- **KEEP AS-IS** — the pattern is correct today; no change is warranted by anything in Phase 1.
- **KEEP & STRENGTHEN** — the pattern is fundamentally correct, but Phase 1 found specific, narrow
  gaps in its own implementation (not its design) that should be closed without changing the
  pattern itself.
- **EXTEND** — the pattern is correct and should be applied to more cases than it covers today,
  without changing how it works where it already applies.

---

## 1. Cost Collector / `cost_lines` as the project cost source

**Classification: KEEP & STRENGTHEN, and separately EXTEND once WNG decides labour/logistics
policy.**

Phase 1 found this the single strongest piece of project-cost architecture in the module: budget
and spend share one table via a `nature` column (planned/committed/accrued/actual), so variance is
a `GROUP BY`, never a reconciliation between two independently-maintained systems
(`04_CURRENT_DATA_MODEL.md` §B; `05_CURRENT_WORKFLOWS.md` Part E.2). **KEEP & STRENGTHEN**: close
the `$guarded=['id']` mass-assignment breadth on `CostLine` and give "append-only after
verification" a model-level guard instead of relying solely on the absence of an `update()` route
(`04_CURRENT_DATA_MODEL.md` ISSUE-3/ISSUE-4). **EXTEND** (gated on Workflow 7's business decision,
not a technical redesign): once WNG chooses a labour-attribution method and a logistics-tagging
approach, the same `CostLine`/`nature` mechanism should carry those actuals too — do not build a
second cost table for labour or logistics.

## 2. Ledger-based project margin

**Classification: KEEP AS-IS at the single-project level; EXTEND to the portfolio view.**

`CostAccountService::marginAgainstJournals()` computes margin live from posted `project_invoices`
and released `journal_lines` — never a cached total (`05_CURRENT_WORKFLOWS.md` Part E.2). This is
explicitly the pattern Phase 1 recommended as the model for closing other gaps, not a candidate for
replacement. **EXTEND**: lift the same computation into the portfolio-wide Cost Accounts list view,
which today shows no billing/margin column at all (`05_CURRENT_WORKFLOWS.md` Part E.5, risk M15) —
this is additive (more rows use the same formula), not a redesign of the formula.

## 3. `JournalPostingService::postBalancedEntry()` (the single funnel)

**Classification: KEEP & STRENGTHEN.**

The funnel itself — sum legs, assert `bccomp(debit,credit)===0`, check every account is postable,
write inside one transaction — is correct and well-documented as the intended single write path
(`06_ACCOUNTING_POSTING_ANALYSIS.md` Part A.2). **KEEP & STRENGTHEN**: two producers currently
bypass it (`postSupplierInvoice()`, `reverseEntry()`) and must be routed through it or through an
extracted `assertBalanced()` helper — this is closing a gap in the funnel's adoption, not changing
the funnel's design. This is risk C4 in the stabilization plan.

## 4. Accounting-period controls

**Classification: KEEP AS-IS.**

Phase 1 rated this one of the strongest controls in the entire module: periods are DB-backed and
enforced at the service layer immediately before every ledger write, with a row-locked re-check
before the final spend-voucher post specifically to close a create-then-post race
(`07_APPROVAL_AND_PERMISSION_MATRIX.md` §15). No redesign is warranted. The only open item is a
verification task, not a change: confirm no code path writes to `journal_entries`/`journal_lines`
via raw SQL outside the Eloquent path this audit traced (Technical question #65 in the Phase 1
questions list).

## 5. Supplier three-way match

**Classification: KEEP AS-IS for the match/verification mechanism itself.**

The sha256-fingerprint design — verification silently invalidates itself the moment any checked
fact changes after sign-off — is tamper-evident, not merely tamper-logged, and is confirmed correct
(`05_CURRENT_WORKFLOWS.md` Part B.2). **This classification applies to the matching algorithm
only.** The surrounding document control — that a Purchase Order can be edited or deleted with no
status check at all after approval — is a defect in `PurchaseOrderController`, not in the match
itself, and is addressed as CRITICAL risks C2/C3 in `02_CRITICAL_STABILIZATION_PLAN.md`. Fixing
those risks should not touch the fingerprint/matching logic.

## 6. SpendVoucher maker/checker/poster control

**Classification: KEEP AS-IS as the reference pattern; EXTEND to Payroll and Bill verification.**

Three distinct permissions (create/approve/post) plus identity-level self-succession blocks at
every stage is the single strongest approval control found in the audit
(`05_CURRENT_WORKFLOWS.md` Part C.3; `07_APPROVAL_AND_PERMISSION_MATRIX.md` §13.1). **EXTEND**:
Payroll's `HR_MANAGE_PAYROLL` (one permission for prepare/lock/pay, identity-only separation) and
Bill verification's hard-coded 3-role array should both be brought up to this same standard —
this is replicating an existing, proven pattern, not designing a new one. Whether and how to extend
it is itself subject to a business decision on role/permission ownership (Part D of this phase).

## 7. Reversal through compensating entries

**Classification: KEEP AS-IS.**

Every reversal mechanism traced — `JournalPostingService::reverseEntry()`,
`PaymentReversalService::reverse()`, top-up deletion's compensating credit — creates a new
offsetting record; none mutates or deletes the original (`07_APPROVAL_AND_PERMISSION_MATRIX.md`
§16). This must remain the pattern for anything that replaces the petty-cash `clear-all` wipe
endpoint (risk C6) — that endpoint is the one place this principle is violated, and its fix must
conform to this pattern, not introduce an exception to it.

## 8. `DocumentNumber::next()`

**Classification: KEEP AS-IS where adopted; EXTEND to PO/Bill/GRN numbering.**

The atomic, row-locked counter table already correctly replaced three independently race-prone
"read max, add 1" implementations for payment/requisition/receipt/journal numbering
(`06_ACCOUNTING_POSTING_ANALYSIS.md` Part D.3). **EXTEND**: apply the identical mechanism to
`PurchaseOrder::generatePONumber()`, `Bill::generateBillNumber()`, and
`GoodsReceiptNote::generateGrnNumber()`, which still use the unlocked pattern this design was built
to eliminate. This is a mechanical extension of a proven pattern, not new design work.

## 9. `ChartAccountMap`

**Classification: KEEP & STRENGTHEN.**

The translation layer between a reference chart and WNG's real chart is correctly designed and
already used successfully at one call site (`resolveVerifiedLiabilityAccount()`)
(`04_CURRENT_DATA_MODEL.md` §4.1, §4.3). **KEEP & STRENGTHEN**: the twelve hard-coded account
lookups in `JournalPostingService`/`PayrollFinancePostingService` should route through this
existing mechanism instead of querying `chart_of_accounts` directly. This is the technical half of
CRITICAL risk C1 — see `02_CRITICAL_STABILIZATION_PLAN.md`. The map's *contents* (which numeric
code corresponds to which of WNG's mnemonic accounts) is a data/policy question, not a design
question, and is blocked on WNG confirmation of the live chart plus a WIP-accounting policy
decision (Part C, Workflow 6 and the Decision Register).

## 10. `posting_rules`

**Classification: KEEP & STRENGTHEN — with one explicit decision needed.**

The table is well-designed (data-driven debit/credit pairs by expense code/voucher type,
effective-dated) but is consulted at only one point in the posting flow before hard-coded fallbacks
take over (`04_CURRENT_DATA_MODEL.md` ISSUE-10). Phase 1 flagged that the codebase should either
commit to this table as the real resolution mechanism or formally retire it so it stops misleading
future readers of the schema — **this specific either/or is itself a decision for the Decision
Register**, not something to resolve unilaterally here. Preserve the table and its schema as-is
until that decision is made.

## 11. Data-driven VAT/WHT

**Classification: KEEP AS-IS.**

`vat_treatments`/`wht_categories` as effective-dated data, resolved by transaction date rather than
today's date, with WHT correctly calculated on the net (not gross) amount, is verified correct
throughout (`06_ACCOUNTING_POSTING_ANALYSIS.md` Part C). No part of this mechanism should be
touched. The gaps found (missing-evidence checks, WHT net-paid display, non-resident WHT, VAT
withholding) are additive/UI gaps or unimplemented-scope questions, not defects in the existing tax
calculation engine.

## 12. Receivables posting model (deposit → invoice → allocation, 3 ledger events)

**Classification: KEEP AS-IS; EXTEND to cover credit-note WIP reversal.**

The three-event model (cash arrival → Client Deposits liability; invoice issued → AR/Revenue/Output
VAT; allocation → deposit discharged against the receivable) correctly separates operational
capture from accounting events at every step and is a material, verified improvement over the
pre-September-2026 state (`05_CURRENT_WORKFLOWS.md` Part A.2, Part E.8). **EXTEND**: build the
negative-release path in `WorkInProgressReleaseService` so a credit note also reverses its
proportional share of Cost of Sales, using the same event-based mechanism already in place —
this is completing the pattern, not replacing it (risk M10).

## 13. Bank reconciliation

**Classification: KEEP & STRENGTHEN.**

The CSV-import, auto-match (reference+amount+account+date-tolerance), manual match/unmatch/ignore
workflow is sound (`06_ACCOUNTING_POSTING_ANALYSIS.md` Part B). **KEEP & STRENGTHEN**: add
`SoftDeletes` (or an equivalent supersede marker) to `StatementMatch` so re-matching/unmatching/
ignoring a line no longer permanently erases what it used to be matched to (risk H8) — this is
closing a data-retention gap in an otherwise-correct workflow, not changing the matching algorithm.

## 14. Project → Finance integration (Projects module)

**Classification: KEEP AS-IS.**

Invoice issuance, client-receipt verification, and WIP release all post inside the same database
transaction as the triggering status change, so a Finance posting failure blocks the operational
status change rather than silently diverging from it (`08_CROSS_MODULE_INTEGRATION_MAP.md`). This
atomic, fail-closed design is exactly right and should be the template for closing the payroll
money-movement gap (risk C7), not itself revisited.

## 15. Procurement/Stores → Finance integration

**Classification: KEEP AS-IS for the cost-posting mechanism; the surrounding document controls are
addressed separately in the stabilization plan.**

The outbox pattern feeding `StoresCostProducer` (explicit terminal states, guarded retry) and the
supplier-bill posting path (`postSupplierInvoice()`, once routed through the funnel per item 3
above) are sound (`08_CROSS_MODULE_INTEGRATION_MAP.md`). The Purchase Order mutability defects
(risks C2/C3) sit in `PurchaseOrderController`, upstream of this integration, and must be fixed
there — fixing them should not require any change to how Stores or Bills post into Finance.

---

## Summary table

| # | Component | Classification |
|---|---|---|
| 1 | Cost Collector / `cost_lines` | KEEP & STRENGTHEN (+ EXTEND, gated on WNG decision) |
| 2 | Ledger-based project margin | KEEP AS-IS (single project) / EXTEND (portfolio) |
| 3 | `postBalancedEntry()` funnel | KEEP & STRENGTHEN |
| 4 | Accounting-period controls | KEEP AS-IS |
| 5 | Supplier three-way match | KEEP AS-IS |
| 6 | SpendVoucher maker/checker/poster | KEEP AS-IS / EXTEND (to Payroll, Bill verification) |
| 7 | Reversal via compensating entries | KEEP AS-IS |
| 8 | `DocumentNumber::next()` | KEEP AS-IS / EXTEND (to PO/Bill/GRN) |
| 9 | `ChartAccountMap` | KEEP & STRENGTHEN |
| 10 | `posting_rules` | KEEP & STRENGTHEN (pending a commit-or-retire decision) |
| 11 | Data-driven VAT/WHT | KEEP AS-IS |
| 12 | Receivables posting model | KEEP AS-IS / EXTEND (credit-note WIP reversal) |
| 13 | Bank reconciliation | KEEP & STRENGTHEN |
| 14 | Project → Finance integration | KEEP AS-IS |
| 15 | Procurement/Stores → Finance integration | KEEP AS-IS |

**No component reviewed above is classified REDESIGN.** Every REDESIGN classification from the
Phase 1 audit (`11_KEEP_REDESIGN_MERGE_REMOVE_MATRIX.md`) — Purchase Order mutability, client
billing's backend module placement, the two funnel-bypassing posting paths, payroll's money
movement, Finance attachment architecture, the Logistics→Finance link — is addressed as a targeted
fix in `02_CRITICAL_STABILIZATION_PLAN.md` (where it is a financial-integrity risk) or carried
forward as a scoped question in the workflow/decision documents (where it depends on a WNG choice).
None of them require touching the fifteen foundational patterns listed above.
