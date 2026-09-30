# 06 — Finance Roles: Proposed Responsibility Model

Phase 1.5, Part D. The Phase 1 audit found that today's system-level roles (`Admin`, `Accounts`,
`Super Admin`) are not a business responsibility model — they are broad, technical permission
groupings under which one role can hold create+approve+post+reverse authority for an entire
transaction type. This document proposes a **functional** responsibility model as a starting point
for discussion. **No named employee, department headcount, or monetary threshold is assumed
anywhere in this document.** Every functional-role-to-action mapping below is a **draft proposal**
for WNG to confirm, amend, or reject — not a finding of fact about how things must work.

## Proposed functional responsibilities

| Functional Role | Plain description |
|---|---|
| **Requester** | The person with an operational need to spend money or a claim to be reimbursed — could be any employee. |
| **Project Officer** | Manages a specific project day-to-day; raises project-linked costs and requests. |
| **Procurement** | Sources suppliers, raises Purchase Orders, manages the buying process. |
| **Accounts Preparer** | Enters and records financial documents (bills, receipts, disbursements) into the system. |
| **Accounts Reviewer** | A second set of eyes on a financial document before it is approved — distinct from whoever prepared it. |
| **Finance Approver** | Authorizes a financial document for posting/payment once reviewed. |
| **Payment Authorizer** | The final sign-off that releases actual money — may be the same person as the Finance Approver, or a distinct role, depending on WNG's chosen control strength. |
| **Management Approver** | Senior sign-off reserved for exceptions, overruns, or high-value transactions. |
| **Reconciler** | Matches recorded transactions against bank/cash statements and confirms they agree. |
| **System Administrator** | Configures the system itself (chart of accounts, permissions, reference data) — not a day-to-day transaction participant. |

This model deliberately separates *what needs to happen* from *who currently has the system
permission to do it* — the current `Admin`/`Accounts`/`Super Admin` groupings can be mapped onto
these functional roles once WNG confirms which real people/positions fill which function (see
`03_WNG_FINANCE_DECISION_REGISTER.md`).

---

## Proposed responsibility matrix, by transaction type

For each transaction type: **Create | Review | Approve | Post | Pay | Reconcile | Reverse**. Where
a cell says "—", that action does not apply to this transaction type as currently designed. Where a
cell proposes a functional role, it is a **draft** based on either (a) the strongest existing
pattern in the system (SpendVoucher's genuine three-way split), used as the template, or (b) a
plain reading of who logically touches that step — never an assumption about current permission
grants.

### Client Invoice

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Project Officer / Accounts Preparer | Accounts Reviewer | Finance Approver | *System, on issue* | — | — | Finance Approver (credit note) or Management Approver (void) |

**Open question (→ Workflow 1):** does WNG want a mandatory review step before an invoice is
issued, or does the preparer currently issue directly? Today's system technically allows either.

### Credit Note

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Accounts Preparer | — | Finance Approver | *System, on issue* | — | — | *Not reversible — a new credit note or contact Management Approver* |

**Open question (→ Workflow 1):** should credit-note creation and approval always be different
people, or can the same person do both for routine cases?

### Client Payment / Receipt Verification / Allocation

| Create (record) | Review | Approve (verify) | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Accounts Preparer | — | Accounts Reviewer / Finance Approver | *System, on verify* | — | Reconciler | Finance Approver |

**Open question (→ Workflow 1):** should verifying a payment and allocating it to an invoice always
be the same person, or should allocation require a second person's check?

### Purchase Order

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Procurement / Project Officer | — | Procurement lead or Finance Approver, depending on value | — | — | — | *No routine reversal — see change-order question below* |

**Open question (→ Workflow 2):** should PO approval authority scale with order value (a small
order approved within Procurement, a large one requiring Finance or Management sign-off)? If an
approved order must change, who approves the change?

### Supplier Bill

| Create | Review | Approve (verify) | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Accounts Preparer | — | Accounts Reviewer / Finance Approver | *System, on verify* | Payment Authorizer | Reconciler | Finance Approver |

**Open question (→ Workflow 2):** should bill verification require a permission distinct from
general Accounts access (today it's a fixed role list, not a configurable permission)?

### Supplier Payment

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| *Triggered by an approved, verified bill* | — | — | *System, on payment* | Payment Authorizer | Reconciler | Finance Approver |

### Expense / Cost Line (project or admin)

| Create | Review | Approve (verify) | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Requester / Project Officer | — | Accounts Reviewer / Finance Approver | *System, on verify* | *depends on funding route — see Petty Cash / Payment Voucher* | — | Finance Approver |

### Petty Cash Requisition & Disbursement

| Create | Review | Approve | Post | Pay (disburse) | Reconcile (surrender) | Reverse |
|---|---|---|---|---|---|---|
| Requester | — | Finance Approver (with a distinct Management Approver path for over-budget exceptions) | *System* | Accounts Preparer | Accounts Reviewer | Finance Approver |

**Open question (→ Workflow 5):** should disbursement and surrender-verification always be
different people, as they functionally are today, or is that already WNG's expectation confirmed?

### Payment Voucher (Spend Voucher)

*This is the one transaction type where today's system already implements a genuine three-way
functional split — used here as the reference pattern for every other row in this table.*

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Requester / Accounts Preparer | — | Finance Approver | Payment Authorizer | *(same as Post)* | Reconciler | Finance Approver / Payment Authorizer |

**Open question (→ Workflow 4):** should Approve and Post require two distinct people even for
low-value vouchers, or only above a value threshold WNG defines?

### Payroll Run

| Create (prepare) | Review | Approve (lock) | Post | Pay (authorize) | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Accounts Preparer / HR | — | Finance Approver | *System, on lock* | Payment Authorizer | Reconciler | Management Approver (controlled reversal only) |

**Open question (→ Workflow 10):** should this follow the same three-distinct-people pattern as
Payment Vouchers, and should Finance (not HR) hold the "Approve/Post" authority here?

### Journal Reversal (any source)

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| *N/A — reverses an existing document* | — | Finance Approver | *System, on approval* | — | — | — |

### Bank/Cash Reconciliation

| Create (import) | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| Reconciler | — | — | — | — | Reconciler | Finance Approver (reopen a closed reconciliation) |

### Accounting Period Close

| Create | Review | Approve (close) | Post | Pay | Reconcile | Reverse (reopen) |
|---|---|---|---|---|---|---|
| — | Accounts Reviewer (runs the checklist) | Finance Approver | — | — | — | Management Approver |

### Chart of Accounts / System Configuration

| Create | Review | Approve | Post | Pay | Reconcile | Reverse |
|---|---|---|---|---|---|---|
| System Administrator | Finance Approver | Finance Approver | — | — | — | System Administrator |

---

## Segregation-of-duties principle carried forward from Phase 1

Regardless of which functional roles WNG ultimately assigns to which real people, the Phase 1
audit's clearest lesson is: **the strength of a control should not depend solely on headcount.**
Today, one system role (`Accounts`) can legally create, approve, post, reverse, and close a period
for the same transaction type, relying only on a same-person runtime check to prevent one
individual from doing everything on the *same document*. The proposed model above intentionally
separates Accounts Preparer from Accounts Reviewer from Finance Approver from Payment Authorizer as
**distinct functions**, so that if WNG has enough people to fill them separately, the system's
permission structure — not just a runtime check — enforces the separation.

**REQUIRES WNG CONFIRMATION**, for every row above:
1. Which real position(s) at WNG fill each functional role?
2. Where WNG has fewer people than functional roles for a given transaction type, which functions
   are allowed to be combined, and which must never be combined regardless of headcount?
3. Do any of the proposed mappings above conflict with how WNG actually wants a given transaction
   type to flow? (This document proposes a draft, not a final design.)

---

*Full context for each transaction type's current-state behaviour is in
`docs/finance-redesign/current-state/07_APPROVAL_AND_PERMISSION_MATRIX.md`. Every open question
above is consolidated in `03_WNG_FINANCE_DECISION_REGISTER.md`.*
