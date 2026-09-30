# 05 — Target Business Process Questions, by WNG Workflow

Phase 1.5, Part C. This document translates the Phase 1 technical audit into questions that
Management, Accounts, Procurement, Project Officers, and SOP Administration can answer without
needing to read code. Every question below is genuinely open — this document does not answer any
of them on WNG's behalf. Where the current system already behaves a particular way, that is stated
as "today" context, not as a recommendation.

Each workflow's questions also appear as rows in `03_WNG_FINANCE_DECISION_REGISTER.md`, alongside
a recommended technical direction once the answer is known.

---

## WORKFLOW 1 — Client / Project Money

**The flow:** Client/Enquiry → Approved Quote → Project → Client Invoice → Client Payment →
Payment Allocation → Outstanding Balance → Project Revenue → Project Profitability.

**What already works today.** Once a client's payment is confirmed (not just recorded — actually
verified), the system correctly books it as money owed back to the client until it's matched to a
specific invoice, and only then recognizes revenue. Voiding an invoice, or issuing a credit note,
properly undoes the accounting rather than just changing a label. This part does not need to
change.

**What's unclear or inconsistent today.**
- The system currently shows two different numbers that both get called "Outstanding" — one adds
  up everything received against the whole project, the other adds up what's actually been matched
  to a specific invoice. These can disagree, and a project can look "Fully Paid" even when a
  received payment hasn't been matched to any invoice yet.
- A payment that's been received but not yet double-checked (verified) can silently drop off the
  daily follow-up list once the project's overall balance already looks settled.
- When a credit note is issued, it correctly reduces what the client owes, but the "cost of the
  job" side of the books doesn't yet get adjusted to match — so a job's profit can look slightly
  better than it really is for a while after a credit note.
- There is no standard "payment terms" setting (like "Net 30") — every invoice's due date is typed
  in by hand.
- There's no separate field to record a discount — a discounted invoice today has to be entered as
  an already-reduced price, with no record of what the original price and discount actually were.

**Questions for WNG:**

1. **Who prepares an invoice?** (e.g. Project Officer, Accounts, Client Service)
2. **Who checks it before it goes out?** Is there a required second look, or does the preparer's
   invoice go straight out?
3. **Who has the authority to issue an invoice** (i.e. actually send it to the client and make it
   official)?
4. **Can an invoice ever be issued without an approved quote?** If yes, when, and who can authorize
   that exception?
5. **How should deposits be handled?** Should a deposit sit as "money we're holding" until an
   invoice is raised against it, or should it be treated as immediate part-payment of revenue?
6. **What should "Client Outstanding" mean** when someone asks "how much does this client still
   owe us"? Should it always mean "what's on invoices we've issued," or should it sometimes mean
   "what's left on the whole quoted contract, invoiced or not"?
7. **Should the quote balance and the invoice outstanding be shown as two separate numbers**, so
   staff can tell "how much of the contract is left to invoice" apart from "how much of what we've
   invoiced hasn't been paid"?
8. **Who verifies that an incoming client payment is genuine** (confirms it actually landed in the
   bank/mobile money account) before it counts toward anything?
9. **Who is responsible for matching (allocating) a received payment to the specific invoice it
   pays?**
10. **Who can issue a credit note** (i.e. reduce or cancel part of an invoice)?
11. **Does a credit note need a separate approval step**, or can whoever issues it also approve it?
12. **Who can void or reverse an invoice entirely**, and under what circumstances should that be
    allowed versus requiring a credit note instead?

---

## WORKFLOW 2 — Procurement to Payment

**The flow:** Purchase Request → Approval → Purchase Order → Goods/Service Confirmation → Supplier
Bill → Bill Verification → Payment Approval → Payment → Bank Reconciliation → Accounting.

**A design principle already confirmed sound and worth stating explicitly: a Purchase Order, a
Supplier Bill, and a Payment are three separate documents today, and should stay three separate
documents.** They should never be merged into one "purchase transaction" record — this separation
is what lets the system check "did we order this, did we receive it, and did we pay the right
amount" as three independent, cross-checked facts.

**What already works today.** Before a supplier bill can be paid, the system checks that the order
was approved, the supplier matches, the goods were actually received into stock, and the invoiced
amount doesn't exceed what was ordered or received. If any of those facts change after the fact,
the bill's "cleared to pay" status is automatically withdrawn. This checking mechanism should not
change.

**What's a genuine gap today.**
- Once a Purchase Order is approved, nothing currently stops someone from going back and changing
  its items, supplier, or total amount — even after it's been delivered against, billed, or paid.
- Similarly, nothing currently stops an approved order from being deleted outright, even after
  money has already been paid against it — which can wipe out the paper trail for that payment.
- Two suppliers' bills carrying the same invoice number aren't currently caught as a possible
  duplicate.
- A supplier payment's bank reference number isn't currently checked for duplicates, so the same
  bank transfer could in theory be recorded twice.
- Today, a Purchase Order can only have one Bill raised against it — if a supplier invoices in
  stages against one order, the system doesn't yet support that.

**Questions for WNG:**

1. **Who creates a Purchase Request?** (e.g. Project Officer, Site Captain, Department Head)
2. **Who checks a Purchase Request before it becomes an order?**
3. **Who approves it, and does that depend on the amount?**
4. **Who creates the Purchase Order itself once a request is approved** — is this always
   Procurement, or can others raise one directly?
5. **Who confirms goods or services were actually received**, and should that always be a different
   person from whoever raised the order?
6. **Who records a Supplier Bill when it arrives?**
7. **Who checks (verifies) that a bill matches the order and the goods received?**
8. **Who approves a bill for payment**, and is that the same person who verified it, or must it be
   someone else?
9. **At what point should Finance/Accounts get involved** in this chain — only at payment, or
   earlier (e.g. reviewing the Purchase Request itself)?
10. **Should approval authority depend on the amount of the purchase?** (e.g. a small purchase needs
    one sign-off, a large one needs two)
11. **If an approved Purchase Order genuinely needs to change** (price change from the supplier,
    quantity change, wrong item) — what should happen? Should it require a brand-new order, or a
    formal "change" to the existing one?
12. **Who should have the authority to approve a change to an already-approved order?**
13. **Should a changed order automatically require re-approval** before it can be used again to
    receive goods or raise a bill?
14. **What supporting documents should be mandatory before a supplier can be paid** — e.g. the
    original invoice, a delivery note, a goods-received confirmation? Is a scanned copy of the
    invoice itself something Finance needs to keep in the system, or is that kept elsewhere today?
15. **Does WNG's supplier billing practice ever require more than one bill against a single Purchase
    Order** (e.g. staged deliveries, each invoiced separately)? If so, this is a real functional gap
    to plan for.

---

## WORKFLOW 3 — Expenses

**The flow:** Expense Need → Expense Request → Approval → Payment → Evidence → Project/Department
Cost → Accounting.

**A design principle already confirmed sound: the Cost Collector (the system's central "what did
this cost, and which project or department does it belong to" record) should remain the one place
every expense type lands, regardless of how it was paid.** This document does not propose building
a second, parallel expense system for any of the categories below — every option considered keeps
using the existing Cost Collector as the common record.

**What already works today, and cleanly distinguishes:**
- Project expenses (tagged to a specific job) versus administrative/overhead expenses (deliberately
  not tied to a job) — this distinction is real and enforced today, not just a label.
- Petty cash expenses, which go through a full request → approval → payment → evidence → double-
  check → close cycle.
- Reimbursable expenses (a staff member paying out of pocket and claiming it back) versus a normal
  supplier payment — these are tracked as genuinely different things today.
- Supplier expenses that go through a Purchase Order versus ones that don't (a "direct bill," used
  for smaller or one-off supplier purchases that don't need a full order).

**What's a genuine gap today.**
- A "cash purchase" — someone paying cash at a shop counter, with no prior requisition — isn't
  currently tracked as its own distinct thing; it looks identical to any other cash payment in the
  records.
- A staff salary advance is currently approved and scheduled for payroll deduction, but the actual
  handing-over of that cash to the employee isn't tracked anywhere in the system at all — there's no
  record connecting "we approved this advance" to "we actually paid it."
- There's a possibility (not yet confirmed, but flagged for someone in Accounts to check against
  real data) that a petty-cash reimbursement might currently be recorded as an expense twice for
  the same receipt.

**Questions for WNG:**

1. **For each expense type below, who can request it, who approves it, and does the approval
   authority change with the amount?**
   - Project expense (site/materials, paid via petty cash)
   - Administrative/overhead expense
   - Petty cash expense generally
   - Reimbursable expense (staff paying out of pocket)
   - Staff salary advance
   - Emergency/exceptional expense (no prior request existed)
2. **Does WNG need to separately track "cash paid at a shop counter" from other petty-cash
   payments**, for control or reporting reasons — or is knowing "this was paid in cash" enough?
3. **For a staff salary advance, who currently decides how the cash is actually handed to the
   employee** (till, bank transfer, in person) — and is that step recorded anywhere today, even
   outside this system?
4. **Can you confirm, from a recent reconciled petty-cash reimbursement, whether the expense was
   recorded once or twice?** This is directly checkable from existing records and would settle
   whether the possible double-recording above is a real, live issue.

---

## WORKFLOW 4 — Payment Vouchers

**The flow:** Create → Review → Approve → Post/Pay → Reconcile → Reverse if necessary.

**This is the strongest control pattern found anywhere in the current system and should be the
reference for every other approval chain in this document.** Today, creating, approving, and
posting/paying a payment voucher each require a different person to hold a different permission —
and the system separately checks that no one person fills two of those three roles on the same
voucher.

**What's a genuine gap today.**
- If someone reviewing a voucher disagrees with it, there's currently no formal way for them to
  reject it — they can only decline to approve it and wait, or ask the original requester to
  withdraw it themselves.
- There's no rule today that says "a payment above a certain amount needs an extra sign-off" — the
  same single approval step applies whether the voucher is for a small amount or a very large one.

**Questions for WNG:**

1. **When someone reviewing a voucher disagrees with it, what should happen?** Should there be a
   formal "reject, with a reason" action available to them, separate from the requester's own
   ability to withdraw it?
2. **Should approval requirements change depending on the amount being paid?** For illustration
   only (not a proposal): a low-value payment might need one approval, a medium-value payment might
   need Finance sign-off, and a high-value payment might need Management sign-off as well. **WNG
   Management/Finance would need to supply the actual amount thresholds and who sits at each
   level** — none are assumed here.
3. **If value-based approval tiers are wanted, should they apply uniformly to all payment types**
   (petty cash, supplier payments, reimbursements), or differently depending on the type?

---

## WORKFLOW 5 — Petty Cash

**The flow:** Requisition → Approval → Disbursement → Evidence → Surrender → Verification →
Reconciliation → Close.

**What already works today, and should be preserved.** Petty cash keeps its own running ledger of
what's in the tin, built so that every entry is added once and never silently overwritten — a
correct, deliberate design that this document does not propose changing. The Fund Custody view
already checks itself for inconsistencies and flags them rather than hiding them.

**What's a genuine gap today.**
- When cash is handed out, the system currently records the cash movement correctly, but if the
  matching entry to the company's main set of books fails for some technical reason, that failure
  is only written to a hidden system log — nobody in Finance is alerted, and the disbursement still
  goes through as if nothing went wrong.
- There is a function that can permanently erase petty cash's entire history in one action. It's
  currently restricted to the most senior system access level, but a feature with this much
  destructive power arguably should not exist in the live system at all.
- Approving an exceptional, no-requisition payment currently relies entirely on the approver's
  judgement from a free-text reason — there's no indicator of how urgent it is, how much float is
  actually available, or whether the same person has made several such requests recently.
- The system currently records "who topped up the float" as the same person as "who is holding the
  cash" — these could be different people in practice, and the system doesn't have a way to record
  that difference today.
- The full petty-cash screens (request forms, approval queues, reports) are built for power users
  and may be more complex than an occasional/casual user needs for a simple request.

**Questions for WNG:**

1. **When a petty-cash payment goes out but the matching company-books entry fails to record**,
   what should happen operationally — should the payment be held back until it's fixed, or should
   it go ahead and get flagged for same-day correction by Finance?
2. **Is there any legitimate business reason a full, irreversible wipe of petty cash's history
   should be possible in the live system?** (The default assumption, absent a reason from WNG, is
   that this capability should be removed.)
3. **For exceptional (no prior requisition) petty-cash payments, does the approver need more
   information than a written reason** — e.g. how much float is currently available, or whether
   this requester has made several such requests recently?
4. **Is the "custodian" WNG needs to track the person who physically holds the cash right now, or
   is it enough to know who recorded the last top-up?** Can these ever be different people in
   practice?
5. **For occasional/casual requesters (as opposed to regular Accounts staff), what is the minimum
   information they actually need to see or fill in to raise a simple request?** This will guide
   whether a simplified request form is worth building for normal users, separate from the full
   Accounts-facing screens, without weakening any of the underlying checks.

---

## WORKFLOW 6 — Project Costing

WNG is a project-driven business, so this is arguably the single most important workflow for
Management. The target is for a project's financial picture to eventually show all of the
following reliably:

| Figure | Status today | Notes |
|---|---|---|
| Approved/Expected Revenue | **AVAILABLE & RELIABLE** | Taken from the approved quote amount for the project. |
| Amount Invoiced | **AVAILABLE & RELIABLE** | Comes directly from issued invoices. |
| Cash Received | **AVAILABLE BUT INCOMPLETE** | Available at the whole-project level, but (per Workflow 1) not always clearly split between "received" and "actually matched to an invoice." |
| Budgeted Cost | **AVAILABLE & RELIABLE** | Comes from the project's approved budget. |
| Committed Cost (ordered/approved but not yet actual) | **AVAILABLE & RELIABLE** | Tracked as its own stage in the cost record. |
| Actual Cost — Materials | **AVAILABLE & RELIABLE** | Flows end-to-end from budget through procurement/stores into a verified actual cost, and reconciles correctly against the budget. |
| Actual Cost — Labour | **NOT CURRENTLY AVAILABLE** | Labour cost today is tracked for the whole company/department, never per project — see Workflow 7. Every project's true cost is currently understated by whatever labour it actually consumed. |
| Actual Cost — Transport/Logistics | **NOT CURRENTLY AVAILABLE** | Same structural gap as labour, on a smaller scale — see Workflow 8. |
| Outstanding Commitments (ordered but not yet received/billed) | **AVAILABLE & RELIABLE** | Tracked as its own figure per project. |
| Gross Margin (single project) | **AVAILABLE BUT INCOMPLETE** | Correctly calculated from real invoiced revenue and real posted costs for one project at a time — but because labour and logistics actuals are missing, the margin shown is systematically better than the true figure. |
| Gross Margin (portfolio — "all our live jobs at once") | **NOT CURRENTLY AVAILABLE** | The one-project-at-a-time view works; there's no version of it today that lets someone scan every live project at once to spot which is doing worst. |
| Margin % | **AVAILABLE BUT INCOMPLETE** | Same caveats as Gross Margin above. |

**Important design note: the existing single-project margin calculation is built the right way —
it reads real, posted financial transactions rather than a manually-typed or duplicated number.**
Extending it to labour, logistics, and the portfolio view should build on this same calculation,
not create a second, separate one.

**Questions for WNG:**

1. **Is Management aware that every current project margin figure understates true cost**, because
   labour and (to a lesser extent) transport/logistics cost aren't yet attributed to individual
   jobs? Is closing this gap a near-term priority?
2. **Does Management currently rely on being able to compare margin across every live project at
   once** (to spot underperforming jobs), or is reviewing one project at a time acceptable for now?
3. **REQUIRES WNG POLICY DECISION**, separate from the technical build: should "cost of a project"
   include an allocated share of company overhead (rent, admin salaries, etc.), or only the direct
   costs traceable to that specific job? This affects how "true margin" should be defined before any
   of the above figures are finalized.

---

## WORKFLOW 7 — Labour Cost

The Phase 1 audit found that labour cost is not attributed to individual projects at all — it is
split only by department, company-wide. **This document does not choose a method on WNG's behalf.**
The options below are presented for WNG to select from, or to decline entirely for now.

| Option | Data required | Operational burden | Finance impact | Effect on profitability accuracy |
|---|---|---|---|---|
| **A — Employee timesheets against projects** | Each employee (or their supervisor) records hours worked, per day, against a specific project | High — requires a new daily habit for site/production staff, and a way to capture it (paper, app, etc.) | Highest — gives Finance an auditable, defensible per-project labour actual | Most accurate — margin would reflect real labour consumed per job |
| **B — Crew-day allocation** | A site/production lead records which crew worked which project, by day, without individual hour-level detail | Medium — one entry per crew per day, not per person | Medium — reasonably defensible, coarser than timesheets | Good approximation, especially where crews work on one job at a time |
| **C — Project labour allocation entered by Project Officer/Site Captain** | The person managing the job periodically estimates/records the labour cost incurred on their project | Low — no new data-capture habit for site staff, just a periodic entry by one person | Lower — relies on one person's estimate rather than a primary record | Moderate accuracy, dependent entirely on how carefully the estimate is made |
| **D — Standard labour rate allocation** | A pre-agreed rate (e.g. per crew-day, per job type) is applied automatically based on job size/duration, with no new day-to-day data capture | Lowest — nothing new for anyone to record day-to-day | Lowest — the resulting "actual" is really a standardized estimate, not a true actual, and the code's own current design deliberately avoids inventing this without WNG's explicit sign-off | Least accurate; risks a margin that looks precise but is not a true reflection of that job's real labour cost — this is exactly the outcome the current system was deliberately built to avoid guessing at |
| **E — No project-level labour allocation for now** | None | None | None | Project margin continues to exclude labour entirely, as it does today — WNG would be choosing to accept the current, known-incomplete margin figure rather than build toward a more complete one |

**Final choice: REQUIRES WNG CONFIRMATION.** Whichever option is chosen, the recommendation from
this audit is that project cost screens should clearly say "Labour: not included" rather than
silently showing zero, until whichever option is chosen is actually built and populated with real
data.

---

## WORKFLOW 8 — Logistics / Fleet Costs

Phase 1 found that vehicle maintenance costs, once approved inside the Logistics/Fleet screens,
never actually reach Finance — despite the screen telling the user the cost was "sent to finance."
Someone in Finance/Procurement currently has to re-type the same cost into the Bills screen for the
vehicle's mechanic or supplier to actually get paid, and there's no way today to check whether every
approved maintenance cost has actually been paid.

**Possible future flow (not yet built):**

```
Fleet/Logistics Cost (e.g. vehicle service, fuel, repair)
   → Approval (within Logistics)
   → Finance Cost/Bill (using the existing Bill or Cost Collector record — not a new one)
   → Payment
   → Project/Department Allocation
   → General Ledger
```

The existing Bill and Cost Collector mechanisms (already used for every other supplier cost) appear
capable of being reused here directly — this document does not propose building a separate cost
record just for logistics.

**Questions for WNG:**

1. **Should approved vehicle-maintenance and other Logistics costs be required to flow through the
   same Bill/Cost Collector process as every other supplier cost**, so the vendor can actually be
   paid and the cost reaches the accounts automatically — or is the current manual re-entry an
   acceptable, deliberate control step?
2. **Is there a manual process today for double-checking that an "approved" maintenance cost was
   actually paid?** If so, how often is it actually performed, and by whom?
3. **This document does not recommend building the automatic link yet** — it recommends first
   confirming the above, since building an automatic connection to a process WNG may want to keep
   manual (for a good reason not visible from the system) would be wasted or unwanted work.

---

## WORKFLOW 9 — Assets

The current system has an operational Asset Register (tracking equipment/vehicles, their hire, and
service history), but it has no connection at all to how that equipment was paid for, and it has no
depreciation, disposal, or gain/loss accounting of any kind. **This document does not propose
building depreciation.**

**Questions for WNG, to determine scope before anything is designed:**

1. **Should the ERP's Finance side eventually cover, for company assets (equipment, vehicles):**
   - **Acquisition cost only** — just knowing what was paid and when, with no ongoing accounting
     treatment?
   - **Acquisition + capitalization** — treating the purchase as an asset on the books, without
     yet tracking its declining value over time?
   - **Depreciation** — tracking the asset's declining book value over its useful life?
   - **Disposal accounting** — recording what happens (and any resulting gain/loss) when an asset is
     sold or scrapped?
   - **Full fixed-asset accounting** — all of the above, as a complete asset ledger?
2. **Until that scope is decided, should the operational Asset Register (used today for tracking
   hire and service history) remain entirely separate from Finance**, with no attempt to link
   acquisition cost to the register? This is the safe default absent a different instruction.

---

## WORKFLOW 10 — Payroll

**The flow:** Payroll Run → Review → Approval/Lock → Payment Authorization → Actual Money Movement
→ GL Posting → Bank Reconciliation.

**What already works today and should be preserved.** The underlying payroll calculation (gross pay,
statutory deductions, tax bands) is not in question and this document does not propose changing it.
Payroll correctly splits gross pay between direct-labour cost and general salary cost, and posts
the employer's statutory contributions as their own separate entry — both confirmed correct.

**What's a genuine gap today, and the specific focus of this workflow.** Every other kind of
payment in the system (a supplier payment, a payment voucher, a client receipt) creates its own
independent "money actually moved" record — separate from the document that authorized it — so it
can show up in bank reconciliation, in fund-custody checks, and in any future "list every payment
we've made" report. **Payroll's actual payment does not do this today** — when a payroll run is
marked as paid, the only record is a note on the payroll run itself and an entry in the company's
books; there is no independent "this specific amount left the bank on this date" record the way
every other payment type has. This means payroll payments currently cannot be seen or checked by
the same tools used for every other kind of payment.

**Also unresolved today:** locking and marking a payroll run as paid currently sit behind a single
permission, with only a same-person check preventing one individual from doing everything alone on
the same run — this is a lighter control than the one used for Payment Vouchers (Workflow 4), where
three genuinely different permissions are required.

**Questions for WNG:**

1. **Should payroll payments be given the same kind of independent "money movement" record every
   other payment type already has**, so they show up automatically in bank reconciliation and
   fund-availability checks? (The audit's recommendation is yes, as a technical fix that reuses the
   existing payment mechanism — this needs WNG's confirmation only on the scope questions below,
   not on the mechanism itself.)
2. **Should this fix apply retroactively** (creating the missing record for payroll runs already
   paid in the past), **or only going forward** from the next pay run?
3. **Should approving, locking, and authorizing payment of a payroll run require three genuinely
   different people/permissions**, the same way Payment Vouchers do today — or is the current,
   lighter same-person check sufficient for payroll specifically?
4. **Should Accounts/Finance, rather than HR, hold the authority to post payroll's entries to the
   general ledger** — or is it acceptable that HR currently holds this authority, since HR runs
   payroll end-to-end?

---

*Every question above also appears as a row in `03_WNG_FINANCE_DECISION_REGISTER.md`. No answer is
assumed anywhere in this document.*
