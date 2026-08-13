# Financial truth — the aligned Finance plan

Date: 2026-08-12
Supersedes the phase list in `cost-capture-audit.md` (that document remains the
detailed reference for Cost Capture's own gaps; its phases 2–4 are folded in below).

## The one principle

Every approved economic event has **exactly one** source record, **one** controlled
accounting treatment, **one** reversible journal trail, and **one** reconciliation path.

Where a module can currently say "approved", "received", "paid" or "invoiced" without
producing the matching financial entry, that is the bug — regardless of how good the
operational screen is.

---

## Where the truth breaks today

Assessed end-to-end, source module → project cost account → general ledger.

| Business event | Cost account | General ledger | State |
|---|---|---|---|
| Approved project budget | yes (queued projection) | n/a | connected |
| **Approved budget addition** | **no** | n/a | **broken — fixed in slice 1** |
| Approved purchase order | commitment | no, correctly | connected |
| Goods received | accrued | yes | connected |
| Stock issued to project | actual | yes | connected, weak classification |
| Petty-cash disbursement | actual | yes | connected |
| Manual project cost | after verification | yes | connected |
| **Spend voucher payment** | optional link | yes | **unsafe — double expense** |
| **Client invoice issued** | no | **no** | **missing** |
| **Client receipt verified** | billing progress only | **no** | **missing** |
| **Payroll locked / paid** | no labour allocation | **no** | **missing** |
| Assets / logistics / production | mostly no | no | missing |
| Period close and statements | period table only | no statements | missing |

---

## Delivery order

Reordered from the assessment only where a dependency or a live-corruption risk forces it.
Everything in phase 1 protects figures that are **already wrong or already at risk today**.

### Phase 1 — protect financial truth

| # | Work | Why this rank |
|---|---|---|
| 1.1 | **Budget additions become budget revisions** — approved addition projects planned cost lines | ✅ **shipped**, see slice 1. Blocking: "budget vs actual" used a stale ceiling |
| 1.2 | **Voucher settlement** — Dr Payable / Cr Bank from linked cost lines | Highest live risk: double expense recognition, distorts project profitability now |
| 1.3 | **AR journals** — invoice issued, receipt verified, reversal, void | A project can read 70% funded with no cash in the ledger |
| 1.4 | **Budget revision ≠ client variation** — split the four concepts | Internal overruns currently auto-authorise charging the client |
| 1.5 | **Reconciliation tests** — every source → subledger → GL path | Without these, 1.1–1.4 regress silently |
| 1.6 | **Queue-failure visibility** | All five cost listeners are `ShouldQueue`; if the worker stops, spend silently never reaches the ledger |

Note on 1.6 — not in the original assessment, but it undermines every "connected" row in
the table above. The wiring is correct and invisible when it fails.

### Phase 2 — complete core accounting
Supplier invoices + three-way matching (closes `releaseAccrual`, the standing double-count
landmine) · payroll journals + statutory liabilities · bank import and reconciliation ·
period-close workflow · journal explorer, trial balance, P&L, balance sheet · tax registers
and returns.

### Phase 3 — connect the operating company
Inventory valuation and control account · production labour and consumption · logistics and
fleet · assets, depreciation, maintenance · forecast-to-complete and margin-at-completion ·
consolidated CEO dashboard.

---

## Already closed, so nobody re-does it

- **Self-approval of budget additions** — `determineInitialStatus()` now returns
  `pending_approval` unconditionally, with budget type explicitly demoted to a ledger
  treatment rather than an approval grant. Both the formal approval path and the
  material-derived path are covered. Assessment gap #5 is done.
- **Tax reaches the GL** — `postCostLine` builds up to four legs (expense, input VAT,
  WHT payable, settlement). Assessment's tax note about *cost* VAT/WHT is satisfied;
  *output* VAT from client invoices is still missing and sits in 1.3.
- **Transition locking** on `verify` / `query` / `reject` / `reverse` / `resubmit`.
- **Preview equals commit** — `CostTaxPricer` is the single pricer for both the
  verification screen's preview and the write, so the two cannot drift.

---

## Slice 1 — budget additions become budget revisions (shipped 2026-08-12)

**The break.** `BudgetAddition` stores its own `materials` / `labour` / `expenses` /
`logistics` JSON, separate from the `task_budget_data` columns `BudgetProjector` reads.
Approval changed `status`, wrote a governance audit row, and stopped. So an authorised
addition was visible in its own workflow while every "budget vs actual" figure still used
the pre-addition ceiling.

**The fix.** Approval now emits `BudgetAdditionApproved`; a queued listener drives
`BudgetRevisionProjector`, which projects the addition's own line arrays into `planned`
cost lines keyed `source_type = 'BudgetAddition'`, `source_id = <addition id>`.

Chosen deliberately over mutating `task_budget_data`:

- the revision stays **immutable and attributable** — you can see which authority
  admitted which money, which is the whole point of a revision
- `postPlanned` already supersedes by `(source_type, source_id, source_ref)`, so
  re-running converges instead of duplicating
- rejection creates nothing, because nothing is written until approval

**Cost cause is left unset, on purpose.** `postPlanned` tags `isAddition` lines
`CLIENT-CHANGE`, whose own seed says "should be billable". An approved addition is
*internal authority to spend* and may be an emergency, rework, wastage or a genuine client
variation — the record does not say which. Asserting billability at projection time is
exactly the confusion in 1.4, so revision lines are projected unclassified and 1.4 will
introduce the explicit concepts:

| Concept | Meaning | Changes contract value? |
|---|---|---|
| Budget revision | internal authority to spend | no |
| Client variation | authority to invoice more | **yes** |
| Contingency drawdown | consumes approved contingency | no |
| Internal overrun | reduces margin, escalates | no |

---

## Open decision recorded

**`my-projects` search scope.** The gate moved from `! $search` to `! $portfolioAccess`,
so a user without `finance.costs.read`/`create` can no longer find a job they are not
assigned to, even by searching. Kept as-is — it is the more controlled reading and matches
this plan's direction — and the two tests plus the modal subtitle were corrected to tell
the truth. Reversible in one line if reporting against unassigned jobs turns out to be a
real field need (a driver or store keeper was the original rationale).
