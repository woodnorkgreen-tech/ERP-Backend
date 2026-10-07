# Report 75R-C — Financial Requisition Policy Decision Closure

Date: 2026-10-06 · Baseline: Report 75R-B · Backend `master` @ `5a5a3db`, frontend `master` @ `b82f278`, uncommitted. Nothing was committed, pushed, deployed or approved on anyone's behalf. No production data was touched.

---

## 1. Decisions implemented

| # | WNG decision | Result |
|---|---|---|
| 1 | All requisition disbursements are held in Staff Advances until accounted for | Put forward as the proposed answer on the three Finance Setup items. **Proposed, not approved.** |
| 2 | Overspend is never reimbursed automatically | Enforced for receiver-paid and one-payment requisitions. The legacy automatic reimbursement entry is removed. |
| 3 | Finance releases an unused approved balance; the requester cannot | Enforced. The release permission is **left unassigned**: the Finance role is not unambiguous (§4). |
| 4 | Approver must not be payer; payer may reconcile and close | Enforced on the server, on both payment routes, with no override. |

The workflow was not redesigned. No GL account was created.

---

## 2. Advance account treatment

Flow, unchanged: Requisition → Payment → Staff Advances → Accountability → Expense / WIP / Return. A payment is a money movement and creates no expense and no project cost. The expense or WIP is recognised when accountability is accepted.

**What changed.** The three Finance Setup items from Report 75R-B (Employee, Supplier, Other approved recipient) now carry WNG's decision as their proposed answer: Staff Advances (1300), with the reason shown. In 75R-B they deliberately had no proposed answer.

**What did not change.** The items are not approved. They show as *Suggested* until someone with authority approves them through Finance Setup; only then do they read as approved and in force. Until then the ledger keeps posting to Staff Advances, labelled on the requisition page as existing behaviour that is not yet approved.

**One thing to be aware of.** Earlier today WNG decided that a Super Admin may approve and apply any Finance setting in one step. That is a real approval through the system, recorded under that person's name, so it satisfies the lifecycle. It is not the same as an accountant's approval: if WNG wants the accountant specifically to approve this policy, the accountant should be the one to press it. I did not press it.

Tested: employee, supplier and other-recipient payments each debit Staff Advances and nothing else; payment creates no project actual; accepted accountability creates the actual once.

---

## 3. Overspend rule

**Receiver-paid requisitions.** Unchanged from 75R-B, with the instruction added. A claim above the amount advanced is held as OVERSPEND REQUIRES RESOLUTION. It cannot be reconciled and blocks closure. It creates no payment, journal, cost or reimbursement. The person is told:

> "Additional expenditure above the amount advanced requires approval before Finance can disburse the additional amount."

The same sentence appears as a warning while the account is being filled in, on the receiver's card, and in Finance's refusal.

**One-payment requisitions.** These previously posted any overspend as a credit to the paying account, described as a reimbursement, with no Payment and no approval. That is removed:

- Reconciling a surrender that exceeds the advance is refused (`OVERSPEND_REQUIRES_RESOLUTION`) with the same instruction. Nothing is posted, paid or costed, and the advance stays open.
- The journal method itself now refuses to post an overspent surrender, so no other route can reach the old entry.
- Finance returns the surrender; resubmitted within the advance, it reconciles as before.

Entries already posted the old way are untouched. No historical repair was done, and none was looked for.

**How extra money is obtained** is the ordinary route: a new requisition, verified, approved, paid, accounted for. Nothing new was built for it.

---

## 4. Unused balance authority

The rule is enforced: an authorised Finance user releases an unused balance with a mandatory reason; the requester cannot release their own, even if they hold the permission; the verifier, the approver and Finance without the authority cannot. The approved amount is never reduced: the record keeps Approved, Disbursed and Released side by side.

**The permission `finance.requisitions.release_unused` is left unassigned.** The brief allowed mapping it only if one Finance role was unambiguous. It is not. Three roles hold the reconciliation authority it would naturally sit beside:

| Role | Pays / reconciles / closes | Reverses payments and journals, manages periods | Users in dev database |
|---|---|---|---|
| Accounts | yes | yes | 2 |
| Manager | yes | no | 1 |
| Admin | yes | no | 0 |

Accounts is the only one with full Finance control, which makes it the likely choice, but picking it is a business decision. **WNG must select the role or person.**

**Found in the dev database, not changed by me:** the Super Admin role there already holds this permission (7 users). The code's role matrix says it should not, and the brief says a technical administrator must not hold it merely for being one. Either it was granted deliberately or a permissions sync added it. WNG should confirm which, and remove it if unintended. I have not checked production.

---

## 5. Approver / payer segregation

The person recorded as the approver of a requisition cannot pay it. This is checked on the server:

- in the receiver payment service;
- in the one-payment disburse endpoint;
- in the shared payment service behind both.

It holds whatever permissions the person has, including the self-approval override, and there is no bypass. The message is the one specified: "This requisition must be paid by a different authorised Finance user from the person who approved it." The Process payment button is also withheld from the approver, and the page says why.

Not separated, as decided: the payer may reconcile the accountability and close the requisition. Tested with one person paying all three receivers, reconciling all three surrenders and closing. Closure still requires the requisition to reconcile.

Creator, verifier and requester restrictions are unchanged and re-tested.

---

## 6. Governance Centre status

The Finance Setup overview has a "Financial requisition rules" card. Each line shows the decision as it actually stands:

| Rule | Shown as |
|---|---|
| Advance account | **PROPOSED** until all three items are approved and in force, then **CONFIGURED / ACTIVE** |
| Overspend | **ACTIVE** — "NO AUTOMATIC REIMBURSEMENT — ADDITIONAL FUNDING REQUIRES APPROVAL." |
| Unused approved balance | **AUTHORITY ASSIGNMENT REQUIRED** until someone holds the permission, then **AUTHORITY ASSIGNED** with the names |
| Segregation of duties | **ACTIVE** — "APPROVER ≠ PAYER" |

Tested that the advance account reads PROPOSED with two of three items approved and only becomes ACTIVE with all three; and that nothing is written to the governance record by opening the screen.

---

## 7. Tests

New: `RequisitionPolicyClosureTest` (9 tests). Updated: the legacy overspend test in `Stab7PettyCashTriplePostingTest`, and one assertion in the 75R-B suite that expected no proposed answer on the advance items.

| Brief item | Covered |
|---|---|
| employee / supplier / other use Staff Advances | yes |
| payment creates no project actual; accountability creates it once | yes |
| receiver overspend: no reimbursement, no hidden payment, journal or cost | yes |
| one-payment overspend: no reimbursement | yes |
| requester cannot release own; authorised release works; reason required; approved amount unchanged | yes |
| approver cannot pay (both routes); a different Finance user can | yes |
| payer can reconcile and close when reconciled | yes |
| creator / verifier controls intact | yes |

Regression, run serially on the isolated test database across PettyCash, Finance, CostCollector, Procurement, Seeding and Unit: **1,301 passed, 1 failed**, then that one fixed and rerun green. The failure was a fixture in `Wave3PettyCashControlsTest` where the same user approved and then paid, which is exactly what decision 4 forbids; it now uses a separate approver. No production rule was relaxed. The full suite was not run a second time after that fixture change; the three affected suites were (31 passed). Reports 75R, 75R-A and 75R-B suites are green (`75rc-evidence/regression.txt`).

Frontend: 558 passed, 5 failed (the long-standing design-table failures). TypeScript 273 errors, equal to baseline. Vite build passes. `git diff --check` clean in both repositories.

Not done: the new rules card and the approver's withheld button were not looked at in a browser; they are covered by component and API tests.

---

## 8. Remaining human assignment

1. **Approve the advance account policy** in Finance Setup (three items, Staff Advances). Until then it is proposed.
2. **Assign `finance.requisitions.release_unused`** to the Finance role or person WNG chooses (candidates in §4). Until then nobody outside the dev database's Super Admins can release a balance.
3. **Confirm or remove** the release permission on the Super Admin role in the dev database, and check production.
4. Ensure at least two people can act in Finance: with approver ≠ payer, a requisition approved by the only person who can pay cannot be paid.

---

## 9. Verdict

**FINANCIAL REQUISITION POLICY CONTROLS COMPLETE — AUTHORISED ROLE/ACCOUNTING APPROVALS MAY REMAIN**
