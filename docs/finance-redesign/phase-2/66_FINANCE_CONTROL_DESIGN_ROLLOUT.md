# 66 — Finance Control Design Rollout (All Displays)

**Date:** 2026-09-29
**Branch:** `finance/frontend-stream-e-spend-vouchers` in both repos (not merged to `master`, not deployed)
**Instruction:** "Let all displays take this design and structure, but not fonts: maintain our current slim fonts."
**Scope:** frontend only. No backend change, no migration.

## 1. Summary

The Report 65 control-centre design now covers every Finance display, in two layers.

- **Layer 1, the design (all 37 displays).** `FinanceShell` wraps every Finance route in the `.fin-ctl` scope. The existing `--fin-*` tokens now point to the control palette, and the shared Finance vocabulary is restyled:
  - `fin-surface`, `fin-label`, `fin-input`, tables (including plain Tailwind tables), `fin-tab`, primary and secondary actions, `StatusChip` and `FinanceState`.

  So a screen not rewritten still shows the canvas, square cards, caps labels, dense tables, tinted chips and dark primary actions.
- **Layer 2, the structure (workflow registers and document pages).**
  - Registers get a KPI ribbon, a dense table and the shared inspector (control chain, ledger impact, audit trail, backend-allowed actions).
  - Document pages get an 8/4 layout: the document, plus a sticky control rail.

## 2. Fonts

The reference fonts are **not** used. The Finance workspace keeps the app's Poppins at its slim scale:

- body 300, UI 400, emphasis 500, strong 600;
- tabular lining figures, as `.fin-num` does.

Hanken Grotesk was removed, and `index.html` is unchanged. A scoped rule caps `font-bold` and `font-semibold` inside Finance at the app's strong and emphasis weights, so every converted screen stays slim.

## 3. Structural Conversions

| Workspace | Display | Structure |
|---|---|---|
| Overview | Finance overview | Control header, KPI ribbon, queue and inspector, project snapshot (Report 65) |
| My actions | Work queue | Queue with the shared inspector (every work type) |
| Expenses & cash | Payment voucher register | KPI ribbon from the register's counts, state tabs, dense table, inspector |
| Expenses & cash | Payment voucher page | Document and sticky rail (next step, workflow, accounting, history) |
| Expenses & cash | Advances & surrenders | Dense table and inspector (request to reconciliation) |
| Purchasing & payables | Supplier bill register | KPI ribbon, dense table, inspector (requisition, PO, GRN, bill, verification, payment) |
| Purchasing & payables | Supplier bill page | Document and sticky 8/4 rail |
| Sales & receivables | Client invoice register | Dense table and inspector (basis, check, issue, receipt, allocation) |
| Sales & receivables | Receipt register | Dense table and inspector (built from the row itself; no extra request) |
| Sales & receivables | Client invoice page | Document and sticky 8/4 rail |

## 4. Kept Structurally, Skinned Only (and why)

| Display | Reason |
|---|---|
| Petty cash register, Cash requisitions, Requisition page | Legacy screens with tabbed payment, accounting and allocation flows and their own insight rail; restructuring risks working flows |
| Cost verification, Cost collector, Cost accounts | Already a control structure: expandable rows with the journal drawer, and a sticky side rail |
| General ledger, Tax schedules, Periods, Reconciliation, Reports | Analytical screens: tabs plus summary tiles and tables, which the skin brings into line |
| Inventory position, issues, adjustments | Read-only ledger views with no workflow document to inspect |
| Supplier payments, WHT, What we owe, Project billing, Payroll disbursement, Setup screens | Already tile-and-table pages; the skin matches them to the design |

These are candidates for later structural passes, not defects.

## 5. Shared Pieces Added

- **`FinanceInspectionPanel`:** the standard inspector body, used by the Overview, My Actions and every register.
- **Exported mappers** in `overview/inspector.ts`: `voucherInspection`, `billInspection`, `invoiceInspection`, `receiptInspection` and `requisitionInspection`. Each re-labels its own workspace projection and computes nothing.
- **`FinanceKpiCard`:** now accepts any route location.
- **Global skin:**
  - compact inputs, caps section titles, 12 px cards;
  - neutral empty states (an empty list is information, not success: no green tick);
  - a selected-row style (soft fill with a dark left rule).

## 6. Verification

| Check | Result |
|---|---|
| Frontend tests | 335 passed; the 8 known Design/Printing failures are unchanged; **0 new** |
| Finance specs after each workspace | 206 passed |
| ENG-1 | 267, **0 new**. One interim regression, a `to` prop typed as a string, was found and fixed before the Receivables commit. |
| API contract | **0 unmatched** (1,404 routes) |
| Build | PASS |

**Visual check.** A throwaway harness, excluded from git, rendered real Finance routes inside `FinanceShell` with fixture data and no network. Captured:

- the voucher register and voucher page;
- ledger, cost accounts, cash requisitions, tax, periods, paying accounts and inventory.

Fixed from the captures:

- tall inputs, sentence-case section titles and 16 px card padding;
- empty states rendered as success;
- a clipped register column and a collapsed search field.

One suspected `KshNaN` balance was traced to the fixture, not the view: the view checks `!== null`, and the real API returns `null`.

## 7. Commits (frontend)

| Commit | Content |
|---|---|
| `81a3ac4` | Design system, Overview, global skin |
| `564f20c` | Payment vouchers; inspection panel; denser skin |
| `3c8f884` | Purchasing & payables |
| `e4c95c5` | Sales & receivables, plus the `to` prop fix |
| `baf9b15` | Petty cash advances register |
| `a282e7c` | My actions |

## 8. Next

- **Structural passes** for the legacy Petty Cash screens, once their flows are covered by specs.
- **Stream F — W6 Payroll Finance** stays paused until asked.

Not merged to `master`: a push there deploys.
