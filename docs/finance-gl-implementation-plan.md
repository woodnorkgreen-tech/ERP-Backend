# Finance & Cost Accounting — GL Implementation Plan

> Companion to [quote-to-cash-redesign.md](./quote-to-cash-redesign.md) (Phase 2 there = the AR/billing
> half of this plan) and
> [PettyCash/docs/REFACTOR_GUIDE.md](../app/Modules/Finance/PettyCash/docs/REFACTOR_GUIDE.md) /
> `REFACTOR_TASKS.md` (the ledger-integrity work this plan generalizes into a real GL). Source
> requirement: WNG's "ERP Finance Module Overview" brief (received 2026-07-31), analysed against
> current code the same day.
>
> Legend: **[BE]** backend · **[FE]** frontend · **[D]** data/seed · **[T]** test ·
> Effort: S(<½d) M(~1d) L(2–3d)

---

## 0. What this is, and what it isn't

The brief describes a general ledger and cost-accounting engine, not a petty-cash feature. Petty cash
is one payment source among several (bank, mobile money, card) that must all post through the same
journal. This plan treats it that way: it **generalizes the petty-cash ledger work already in
flight into the GL**, rather than building a second, parallel accounting system next to it.

Three things already exist and are reused, not rebuilt:
- `LedgerService` (petty-cash balance, single-writer, atomic, `bcmath`) — the pattern the new
  `JournalService` copies.
- `ChartOfAccount` model + seeder — exists today but has **zero postings anywhere in the app**
  (confirmed vestigial). This plan is what finally makes it real.
- `PayrollLedger` / `PayrollRun` (HR module) — a working payroll engine, currently disconnected from
  project costing. Phase 5 wires it in; it is not rewritten.

Two things are confirmed **not to exist** and are net-new: a double-entry `JournalEntry`/`JournalLine`
model, and a `Supplier` master with tax fields. Client-side AR/billing (Invoice, milestones) is
already scoped as Phase 2 of `quote-to-cash-redesign.md` — this plan links to it rather than
duplicating it.

---

## 1. Non-negotiable design principles

- Petty cash, bank, mobile money and card are **payment methods**, not expense categories — one
  `JournalService` posts all of them identically. (brief §0)
- Field users pick a **plain-language category**. Finance owns the GL mapping table centrally —
  nobody hand-picks a GL account on a voucher. (brief §4)
- A journal that doesn't balance is **rejected outright**. Corrections are reversals, never
  edit/delete. (brief §5)
- The five classification dimensions (GL account, cost object, cost centre, activity, cost cause) are
  captured as **discrete fields**, never folded into a free-text description. (brief §1)
- Recoverable VAT is stripped out of project cost **at entry**, not reconciled later in a report.
  (brief §5)
- One person cannot be requester, approver, and Finance-poster on the same voucher. (brief §6)

---

## 2. Grounding in WNG's actual org structure

Pulled from the current staff roster. **Not committed as fact** — the department column in the
source sheet has a few ambiguous/misaligned rows, so treat the seed list below as a draft for Finance
to confirm before Phase 1 seeding (see §7).

### Cost centres (deduplicated department list → `cost_centres` seed)

Administration (HR & Admin) · Creatives · Finance · IT · Logistics · Operations · Printing ·
Procurement · Production · Projects · Sanitation · Technical

### Activities (`activities` seed) — reuse the existing task-stage taxonomy, don't invent a new one

`ERP-Backend/config/enquiry_workflow.php` already defines the stages every project moves through:
`site-survey, design, quote, quote_approval, materials, budget, procurement, teams, production,
logistics, setup, handover, setdown, report`. The cash-bearing subset —
**production, logistics, setup, handover, setdown** — is the primary Activity a voucher tags;
the rest (design, quote, budget…) rarely carry direct disbursements. Reusing this list instead of a
new one means Activity and project task-state never drift apart, and the voucher form can pre-fill it
from the project's current task automatically (see §5).

### Role separation → roles that already exist on the org chart

The brief's "one person cannot prepare, approve, and post the same voucher" rule isn't a new hire —
WNG's roster already has the separated roles, they just aren't wired into permissions yet:

| Brief role | Existing WNG job title(s) | Example |
|---|---|---|
| Requester | Project Officer / Technician / field staff tied to a Job ID | Rita Veronica, Sam Muthoka, Christopher Ojuma |
| Approver | Department Lead, within WNG's existing authority levels | Jacob Orako (Production Lead), Mary Wangui (Finance Lead) |
| Custodian | One holder per department float | assign per cost centre, Finance-approved |
| Finance Poster | Costing Officer / Costing Assistant | Godfrey Githongo (Costing Officer), Collins Yegon / Dennis Ombeta (Costing Assistant) |
| Receiver (goods/service confirmation) | Store Keeper / Procurement Assistant | Damnian Aloo (Store Keeper), Beth Kimani (Procurement Assistant) |

This means Phase 0/1 is a **permissions wiring problem**, not an org-design problem — the separation
of duties the brief demands can be turned on immediately once `PettyCashPolicy` exists (already the
open item BE-0 in `REFACTOR_TASKS.md`).

---

## 3. Data model: reuse / extend / new

| Model | Status today | Plan |
|---|---|---|
| `ChartOfAccount` | Exists, unused (0 postings) | Extend with `account_type` enum (`balance_sheet`/`direct_cost`/`overhead`/`opex`/`capex`, brief §2 A–E). Becomes the FK every journal line posts against. |
| `LedgerService` (petty cash) | Exists, in-progress refactor, scope = cash balance only | Generalize into `JournalService`. Petty-cash ledger becomes **one subledger** that posts through it, not the source of truth for accounting. |
| `CostCentre` | Doesn't exist | New master, seed from §2. |
| `Activity` | Doesn't exist | New master, seed from existing `enquiry_workflow.php` task types (§2) — no new ontology. |
| `CostCause` | Doesn't exist | New enum column: `planned`/`emergency`/`client_change`/`rework`/`breakdown`/`wastage`/`warranty`. |
| Job ID / cost object | Exists (`project_enquiry_id` / `job_number`) | Reuse as-is — already the anchor on `PettyCashDisbursement`. |
| `Supplier` | Doesn't exist | New: legal name, KRA PIN, VAT status, eTIMS default, WHT category. |
| `JournalEntry` / `JournalLine` | Doesn't exist | New. Single writer = `JournalService`, append/reversal-only, `lockForUpdate` + `bcmath`, same shape as `LedgerService::post()`. |
| Invoice / AR / AP | Doesn't exist (confirmed in the July quote-to-cash audit) | **Not built here** — already scoped as Phase 2 of `quote-to-cash-redesign.md`. Phase 4 below links to it. |
| `PayrollLedger` | Exists (HR), isolated from Finance | Phase 5 connector only — payroll engine itself is untouched. |

---

## 4. Phased task plan

### Phase 0 — Finish what's already moving (no new scope)

- [x] **BE-0 (partly)** Land the still-open items from `PettyCash/docs/REFACTOR_TASKS.md`:
  - [x] `PettyCashPolicy` — replaces 11 `hasRole('Super Admin')` checks in `PettyCashController` and
    2 role-list checks in `PettyCashRequisitionController`. No role strings remain anywhere in the
    PettyCash module outside the policy itself.
    **This widened access deliberately.** AcHAcounts/Admin/Manager already held
    `void_disbursement`, `delete_disbursement` and `edit_disbursement`; the Super-Admin-only checks
    meant none of them could use the grant. Honouring it is the point — it is what makes §2's role
    separation expressible at all.
    **`clearAll` was deliberately NOT widened.** `finance.petty_cash.admin` is granted to Accounts, so
    gating a full-data-wipe on it would hand that wipe to a second role as a side effect of tidying
    authorization. It stays Super-Admin-only via the policy's `before()`.
  - [x] the unguarded `PettyCashTopUpController::update/destroy` — already fixed before this pass;
    both now check `edit_top_up`/`delete_top_up` and `destroy` posts a ledger reversal.
  - [ ] field-level 422 error passthrough
  - [ ] the unreachable Void button

  Note for whoever picks up the rest: the permissions table carries three orphans —
  `finance.petty_cash.create`, `.update` and `.void` are granted to Accounts and Super Admin but no
  constant points at them (the live ones are `.create_disbursement`, `.edit_disbursement`,
  `.void_disbursement`). They grant nothing and should be retired, but deleting permission rows is
  destructive and was left alone here.

### Phase 1 — Classification dimensions on the existing voucher

- [ ] **D-1** Seed `cost_centres` from §2, Finance-confirmed *(S)*
- [ ] **D-2** Seed `activities` from `enquiry_workflow.php` task types *(S)*
- [ ] **BE-1** Add nullable `cost_centre_id`, `activity_id`, `cost_cause` to
  `petty_cash_disbursements` — additive, no contract break *(S, dep: D-1/D-2)*
- [ ] **FE-1** Voucher form: selecting a Job ID auto-fills cost centre + suggested activity from the
  project's current task stage; `cost_cause` defaults to `planned`, shown only as an override chip
  *(M, dep: BE-1)*
- [ ] **T-1** A disbursement created with only a Job ID (no manual dimension entry) still resolves all
  three fields correctly *(S)*

### Phase 2 — Real GL: `JournalService` + `ChartOfAccount` postings

- [ ] **BE-2** Extend `ChartOfAccount` with `account_type` *(S)*
- [ ] **BE-3** `JournalEntry`/`JournalLine` + `JournalService::post()` — balance-must-net-zero,
  `lockForUpdate`, `bcmath`; lift the pattern directly from `LedgerService::post()` *(L)*
- [ ] **BE-4** `PettyCashService` disbursement/top-up creation calls `JournalService` in addition to
  the existing `LedgerService` (dual-write: petty cash keeps its own balance ledger, GL becomes the
  single source of accounting truth) *(M, dep: BE-3)*
- [ ] **T-2** Extend the reconciliation invariant: `sum(JournalLine.debit) == sum(JournalLine.credit)`
  always holds, on top of the existing `current_balance == rebuildFromLedger()` check *(S)*

### Phase 3 — Supplier and tax

- [x] **D-3** `suppliers` master: legal name, KRA PIN, VAT status, eTIMS default, WHT category *(M)*
  — **extended the existing ProcurementStores `suppliers` table rather than creating a second master.**
  All columns nullable: six suppliers already existed, and a required KRA PIN would have made the
  procurement form unusable until somebody backfilled them. Also added `residency`, because WHT rates
  differ for non-residents and `wht_categories` already carried a residency column to match on.
- [x] **BE-5** WHT/VAT computed at posting time from `Supplier.wht_category` + a Finance-editable rate
  table (never hardcoded — brief §8 explicitly requires this configurable pre-go-live) *(M)*
  — `TaxResolver` + `VatTreatment`/`WhtCategory` models (the seeded tables had no models at all).
  `wht_amount` on `cost_lines` had a column and a `0.00` default that **nothing ever wrote**, so every
  supplier payment recorded zero withholding regardless of category; `CostVerificationService` now
  prices it. VAT resolves supplier-first (an unregistered supplier charges none whatever was bought);
  WHT resolves expense-code-first (the rate follows the nature of the service, not the vendor).
  **Known gap:** `wht_categories.aggregate_monthly` says the threshold is meant to be tested against a
  supplier's month, not a single payment. That needs a supplier-month view which does not exist, so
  the threshold is applied per payment today — a series of small payments under-withholds.
- [x] **FE-2** Supplier lookup by name/PIN with inline "save as new supplier" on the voucher form — no
  separate admin screen required for a first-time vendor *(M)*
  — `SupplierPicker.vue`, reusing the existing `useSuppliers` composable rather than a second client.
  Supplier search now covers `legal_name` and `kra_pin`, which it did not before. The capture form
  sends `payee_id` alongside `payee_name`: the id is what makes withholding computable, and
  `CostSubmission` had no `payee_id` field at all, so the frontend could not have linked a supplier
  even though the API already accepted one. Inline creation deliberately does **not** ask for tax
  fields — nobody at the counter knows a vendor's WHT category, and a guess produces a wrong rate that
  looks settled. Payees who are not suppliers keep a plain name and no tax identity.

### Phase 4 — Beyond petty cash

- [ ] **BE-6** Payment-method-agnostic posting: bank/mobile-money/card transactions reuse
  `JournalService` the same way petty cash does *(L)*

> **Blocker found 2026-08-10 — Stores, HR and Payroll all need a valuation source before they can be
> wired.** `cost-collector-integration.md` calls Stores the cheapest win because `inventory_logs` has
> `project_id` and `receipt_unit_cost`. The columns exist; the cost does not. `receipt_unit_cost` is
> **null on every row of every type** (14 check_in, 52 check_out, 1 return, 3 adjustment), nothing
> populates it, and there is no fallback: `inventory_lots` tracks quantity with no cost column and
> there is no item master carrying a price. `ot_entries` is the same shape — `project_id`, `hours` and
> a full approval chain, but no rate and no amount. Wiring either today posts cost lines worth zero.
> Deciding where material cost comes from (standard cost on the material master / weighted average at
> GRN / the PO line) is a Finance decision and is the real next dependency, not a coding task.
- Links into `quote-to-cash-redesign.md` Phase 2 (AR/billing) for the invoice/AP side —
  **do not build a second Invoice/AP model here.**

### Phase 5 — Payroll into WIP

- [ ] **BE-7** Connector: `PayrollLedger` entries post into `JournalService` as direct labour /
  indirect production labour, keyed by employee cost centre *(M)*

### Phase 6 — Dashboard (brief §9–10)

- [ ] **FE-3** Project performance + management reports — read-only queries over
  `JournalEntry`/`JournalLine`, no new write paths *(L, dep: Phases 1–5)*

---

## 5. The low-click voucher screen

One screen, in this order:

1. **Job ID** (or an "Overhead" toggle for non-project spend) → cost centre and activity auto-fill
   from the project's live task stage.
2. **Category** — plain-language dropdown (e.g. "Project transport"). GL account is resolved silently
   behind it; it is never a field the user sees.
3. **Amount + payee** — payee is an autocomplete over staff and `suppliers`, with inline "new
   supplier" if not found.
4. **Cost cause** — a single chip, pre-set to *Planned*. Touched only to flag emergency / client
   change / rework.
5. **Submit.**

That's 2–3 taps for the ~90% common case; the brief's five mandatory dimensions are *inferred*, not
typed, for anything routine. The user only slows down for the genuine exceptions (emergency spend,
new supplier, overhead not tied to a job).

---

## 6. Explicitly not rebuilt

`ChartOfAccount`, the petty-cash ledger integrity pattern, `PayrollLedger`, and the AR/billing model
already planned in `quote-to-cash-redesign.md` Phase 2. Each gets extended or connected, not
duplicated — a second parallel accounting system next to the existing one is the single biggest risk
in this plan if phases are done out of order.

## 7. Open decisions needed before build starts

- Confirm the §2 cost-centre list against the current org chart — a few roster rows had
  ambiguous/misaligned department values that shouldn't be seeded as-is.
- Capitalisation threshold (brief §2E) and the WHT rate table (brief §8) need Finance/accountant
  sign-off values — configurable fields, not hardcoded constants.
- Confirm the KES 20,000 petty-cash-per-transaction cap (brief §6) before Phase 0 policy work.
