# 76A-3 — Final Accounting Integrity Acceptance

**Date:** 2026-10-13
**Baselines:** Report 76, 76A Part 1, 76A Part 2
**Code:** working trees of `ERP-Backend` (on `master@f8c19a7`) and `ERP-Frontend` (on `master@043b7c3`). **Nothing is committed, pushed or deployed. No development or production database was migrated.** All tests ran on isolated test databases.
**Boundary kept:** no production access, no data mutation, no cutover, no chart execution, no historical remediation, no old queue jobs, no W8, no 76B. No account number, tax eligibility, authorisation or opening balance was invented. No governed mapping was activated outside test fixtures.

**Verdict: 76A-3 PARTIAL — SPECIFIC ACCOUNTING INTEGRITY GAPS REMAIN.**

The four areas the brief names as blocking are built and tested: supplier bill allocations, stock-to-ledger atomicity for the movements listed, the net-of-VAT basis, and credit corrections. The verdict is PARTIAL because four things in the brief are not met as written. They are listed in §13 and are the reason, not a formality.

---

## 1. Executive summary

| Area | Status |
|---|---|
| Supplier bill lines matched to specific accepted receipt quantities | Built, tested, race-tested |
| Receipt value computed by the server; manual override removed | Built, tested |
| Price difference: stock on hand revalued, the rest to a governed variance account | Built, tested, for bulk stock |
| VAT that cannot be recovered treated as cost, not variance | Built for stock on hand; **not** for non-stock or issued stock (§13) |
| Purchase orders priced net of VAT; supplier's quote retained | Built in the API, tested; **no screen** (§13) |
| Supplier credit notes (price and quantity, partial) | Built, tested |
| Bill reversal | Built, tested |
| Close-out of received value never invoiced | Built, tested; API only |
| Damage, waste, company use, board scrap, counts, roll counts, exceptional receipts: stock and ledger together or neither | Built, tested |
| Opening balance and inventory adjustment on governed accounts with no reference fallback | Built, tested |
| Company-paid dual permission | Unchanged from Part 2; its test passes |
| Separate status dimensions and exception guidance | API complete; bill screens updated; order and receipt screens not |
| Additive, reversible migration; legacy bills readable | Built; rollback tested |

**Two defects were found by the new tests and fixed.** Both would have shipped otherwise:

1. **Four invoices could bill the same receipt at once.** The receipt row was locked, but the "already billed" figure was read from a snapshot taken before the lock was acquired. A forked race test produced four successes where one was allowed. The read is now a locking read; the same test now produces one.
2. **A bill could clear an accrual that was never raised.** Verifying a bill against a receipt whose accrual had not been posted debited the accrual account anyway. About sixty existing tests depended on this without noticing. The three-way match now checks that the receipts are in the ledger.

Tests: 1,763 backend tests pass (1,752 on the isolated database, 11 on the scratch database their guards require). 426 frontend tests pass. Build passes. Type errors: 267, none on a line this work added.

---

## 2. Accounting policy: implemented, and what needs Finance sign-off

Each of these is implemented as software. **None has been approved by an accountant.** That approval is not claimed anywhere in this report.

| Policy | Implemented as | Sign-off needed |
|---|---|---|
| Purchase prices are net of VAT | New orders carry `price_basis = net`; a tax-inclusive quote is normalised and the quote kept | Yes |
| Earlier orders are not reinterpreted | `price_basis` NULL; untouched | Yes — see §11 |
| GRN accrues at order price, no VAT | Unchanged | — |
| Bill clears exactly the receipt value its lines are allocated to | `bill_line_receipt_allocations` | Yes |
| A price difference on stock still on hand revalues that stock | Inventory and weighted-average cost move together | **Yes — this changes stock values** |
| A price difference on stock no longer on hand, on non-stock purchases, on boards and rolls goes to purchase price variance | Governed account; refused if not approved | Yes |
| Non-recoverable VAT on stock on hand is stock cost | Same revaluation | Yes |
| Input VAT without an eTIMS number is posted and held out of the return | Existing rule, kept; now shown on the invoice | Yes — see §13 |
| Valuation method | Weighted average per material, unchanged | — |
| A supplier credit moves no stock | Enforced; quantity credits re-open the receipt accrual | Yes |
| A paid bill is not reversed underneath its payment | Refused | — |
| A stock movement that cannot be posted does not happen | Refused, naming what is missing | Yes — see §8 |

---

## 3. Before and after posting matrix

| Event | Before 76A-3 | After 76A-3 |
|---|---|---|
| Bill, same price | Dr Accrued (bill net) / Cr AP, Dr VAT | Dr Accrued (value of the receipts its lines cover) / Cr AP, Dr VAT |
| Bill, price differs, stock on hand | Dr/Cr Purchase price variance | **Dr/Cr Inventory**; average cost moves |
| Bill, price differs, stock issued | Dr/Cr Purchase price variance | Unchanged: governed variance |
| Bill, price differs, part issued | all variance | Split by quantity still on hand |
| Bill, VAT not recoverable, stock on hand | variance | **Inventory** |
| Bill against a receipt with no accrual | Dr Accrued anyway | **Refused** |
| Supplier price credit | not possible | Dr AP / Cr Input VAT / Cr Inventory or variance |
| Supplier quantity credit | not possible | Dr AP / Cr Input VAT / **Cr Accrued** (receipt uninvoiced again) |
| Bill reversal | not possible | Mirror of the bill's entry; revaluation taken back |
| Received, never invoiced | stood in the accrual for ever | Dr Accrued / Cr governed close-out account |
| Damage, waste, company use with no approved account | stock moved; posting held as failed | **Refused; stock does not move** |
| Stock count, roll count, exceptional receipt | reference-chart account if none approved | Governed account or refused |
| Roll count with no account mapped | quantity adjusted, "mapping required" recorded | Refused |

---

## 4. Supplier bill lines and matching

**Model.** `bill_lines`: order line, quantity, net unit price, discount, net, VAT, gross, receipt value covered, cost difference, reason, status. `bill_line_receipt_allocations`: bill line → GRN line, quantity, accrued value.

**Receipt value.** Read from the receipt's own posted accrual, so the two cannot disagree. Taking the last of a receipt takes whatever value is left, so cents do not strand (3 units at 33.33, billed 1 then 2, clears 99.99 exactly).

**Guards.**

- A receipt quantity is billed once across all live bills, net of credits and close-outs. Locking read under a row lock.
- One line covers one receipt line once (unique key).
- An order line appears once per bill.
- Lines must equal the bill's net to the cent.
- Document VAT must agree with the rate on the taxable lines within a cent per line; it is then apportioned by largest remainder so line VAT always sums to the document.
- `receipt_value_covered` in a request is refused.

**Invoice ahead of the goods.** A line billing more than has been received is saved as *unmatched* and cannot be verified. Verification re-tries the match, so it passes once Stores receives the goods.

**Compatibility.** A bill sent as one amount with no lines is still accepted. Its lines are derived at verification by allocating its net at order prices, oldest receipt first, and marked `derived`. By construction it states no price difference. Bills verified before lines existed have none and are read exactly as recorded.

**Partial billing is never inferred complete.** Receipt lines are marked settled only when the whole accrual on the order is covered.

**Close-out.** Finance may release received value that will never be invoiced: once per receipt line, only what is uninvoiced, only while no unverified bill is waiting on the order, only to an approved account.

---

## 5. VAT and price variance

Net, VAT and gross are held on every bill line and on every new order line.

**Price or non-recoverable-VAT difference on a bill line** = line net + VAT that cannot be recovered − receipt value covered. It is split:

- **Bulk stock still on the shelf:** Inventory. Measured as the billed quantity, in stock units, that Stores has shelved, up to what the material has on hand. The weighted-average cost changes so that quantity × average changes by exactly the amount posted.
- **Everything else:** the governed purchase price variance account. That is stock already issued or written off, goods received but not yet shelved, non-stock purchases, and boards and rolls.

If any part must go to variance and no account is approved, the bill is not verified and the match says **PURCHASE PRICE VARIANCE ACCOUNT REQUIRED**. If all of it goes to stock on hand, no variance account is needed.

**Tax document.** Whether a VAT treatment needs an eTIMS invoice is its existing `requires_etims` setting. WNG's existing rule is that such an invoice is verified and posted, then held out of the VAT return as at risk. I first built a blocking check and then removed it: it contradicted that rule and its test. The invoice now shows the position before verification. Whether to block instead is a Finance decision (§13).

---

## 6. Valuation and project cost reconciliation

The valuation method is unchanged: one weighted-average cost per material, no receipt layers. Because there are no layers, "still on hand" cannot be traced to a particular delivery. The quantity rule in §5 is the most that method supports, and it is stated here so nobody reads more into it.

Tests assert, after each step, that stock quantity × average cost equals the Inventory account:

- 100 received at 1,000; 40 issued; billed at 1,050. Inventory 63,000, variance 2,000, average 1,050. Project actual stays 40,000. The next 10 issued cost the job 10,500.
- 10 received, all issued, billed at 900. Inventory 0, variance −1,000.

Cost already on a job is not re-opened and not doubled. No bill creates a cost line. Project actual: one at Stores issue for stock; one at receipt for non-stock.

---

## 7. Supplier credits and reversals

**Credit note.** Against the lines of a verified, line-matched bill.

- *Price credit* (quantity 0): Dr AP, Cr Input VAT, Cr Inventory for stock on hand or variance otherwise. Un-bills nothing.
- *Quantity credit:* also releases the receipt allocations, newest first. Those receipts are received-not-invoiced again and can be invoiced again or closed out.

Refused: more than the line, more than is still billed, more than is still owed on the bill, the same supplier credit number twice, a reversed bill. Withholding is returned in proportion.

**Reversal.** Needs both `finance.payables.verify` and `finance.journals.reverse`. Refused while anything has been paid or credited. Mirrors the bill's entry, takes back the stock revaluation where the stock is still there and moves the remainder to variance where it is not, releases every allocation, and reverses a direct bill's project cost. A second request changes nothing (also enforced by a unique key).

**Neither moves stock.** Goods sent back to a supplier are a Stores return. The screen says so.

After a credit the bill remains verified and payable for its reduced balance; the supplier balance, bill balance and order billing position all follow.

---

## 8. Stock and ledger atomicity

| Movement | How it is atomic |
|---|---|
| Damage, waste, company use | Posted in the movement's own transaction. Failure rolls the movement back. |
| Board scrap in Stores | Same hook; the board's status change rolls back too. |
| Stock count | Already one transaction; now requires the governed account. |
| Roll count review | Was "adjust, then record that mapping is missing". Now refused whole. |
| Exceptional receipt | One transaction since Part 2; now requires the governed account. |
| Reversal of the above | Journal reversed in the reversal's transaction. |
| Bill, credit note, bill reversal, close-out | Revaluation and entry in one transaction. |

Refused movements name the missing account or the closed period. Tested: closed period, missing damage, waste, company-use, adjustment and opening-balance accounts.

**Traced and left as they are:**

- **Project issues and returns** post after the stock commits, through the recorded outbox from Part 1. A failure is visible and retryable and never double-posts, but stock and ledger are not one transaction. The brief's list does not include them; I am stating it rather than leaving it to be assumed (§13).
- **Offcuts** enter through the board return workflow, which is that same path.
- **Board scrapped after issue, or at quarantine return review:** cost stays on the project. Existing behaviour.

**Not built:** a pending or quarantine bucket for bulk stock. Boards have Quarantine and rolls have a hold. Bulk stock that cannot be posted is refused and stays in available quantity. If the stock is physically broken, the count is wrong until the account is approved.

---

## 9. Governance mappings and permissions

Seven governed accounts, each with no reference code, no suggestion and no default:

| Item | Needed for |
|---|---|
| Purchase price variance | Any bill or credit whose difference cannot go to stock on hand |
| Stock used by the company | Issues with no project |
| Damaged stock | Write-offs, board scrap in Stores |
| Waste and scrap | Write-offs recorded as waste |
| Inventory adjustments *(new)* | Stock counts, roll counts, stock found, corrections |
| Opening stock *(new)* | Opening inventory |
| Received, never invoiced *(new)* | Receipt accrual close-out |

**Not strict:** Inventory, the receipt accrual, Accounts Payable, Input VAT and withholding still resolve through the existing account map, which uses an approved mapping first and the reference code otherwise (§13).

Permissions. Nothing was granted to anyone.

| Action | Requires |
|---|---|
| Record a credit note, close out an accrual | `finance.payables.verify` |
| Reverse a bill | `finance.payables.verify` and `finance.journals.reverse` |
| Verify a company-paid cost | `finance.costs.verify` and `finance.petty_cash.create_disbursement` |
| Exceptional receipt | `stores.review` |

---

## 10. Tests and failures

| Suite | Result |
|---|---|
| `Report76A3AcceptanceTest` | 17 passed |
| `Report76A3MigrationTest` | 1 passed |
| `Report76A3BillingConcurrencyTest` (forked, 4 racers) | 1 passed, on `db_scratch_test` |
| `Report76APart2Test` | 19 passed |
| `Report76AIntegrityTest` (Part 1) | passed |
| Backend regression: Finance, Procurement, ProcurementStores, Stores, CostCollector, PettyCash, Projects, MaterialsLibrary, Unit | 1,752 passed (1,751 in the full run, plus one test added afterwards and run with the three Report 76A suites) |
| Three concurrency classes | refuse `db_report76a2_test` by name (11 tests); all 11 pass on `db_scratch_test` |
| Frontend, procurement-stores and finance | 426 passed (3 new) |
| Vite build | passed |
| `git diff --check` | clean, both repos |
| `event:list` | no application listener registered twice |
| Type check | 267 errors, 0 on lines this work added |

**Pre-existing, not introduced here:** the 267 type errors (recorded baseline 256; the tree was at 269 before Part 2).

**Existing tests changed, and why.** Nothing was changed to make a failure disappear without a stated reason.

| Tests | Change | Reason |
|---|---|---|
| Seven bill suites | Receipts now fire the receipt event | They verified bills against receipts never posted to the ledger |
| `SupplierLedgerRailTest`, `SupplierInvoiceTaxTest` | Accrual expected flat, not +50,000 | The +50,000 was the over-clearing defect |
| Eight stock suites | State the governed stock accounts in setup | Stock postings no longer fall back to the reference chart |
| Part 2 test H | Held posting → refusal | §8 |
| Part 2 tests B | Variance → stock revaluation | §5 |

**Coverage against the brief's matrix.** A, B, D, E, F, H, I, J, K: covered. C: covered, with eTIMS as advisory. G: damage, waste, company use, board scrap, counts and their reversals covered here and in Part 2; rolls by the existing roll suites, which pass under the new rules; **offcuts have no new test**.

Evidence: `76a3-evidence/`.

---

## 11. Legacy data implications

Nothing was repaired or backfilled.

- **Orders before this rule** have no price basis. The rehearsal copy contains a bill of 8,620.68 net against a 10,000 receipt, which suggests at least one order was priced VAT-inclusive. Billing such an order by line will show the VAT as a price difference. The bill screen warns when an order's basis was never recorded. Each open legacy order needs a person to decide.
- **Receipts with no posted accrual** can no longer be billed until posted. The rehearsal target has 2 receipts, both accrued. The live-source copy has none. Any that exist at cutover will surface as a failed match with a named action.
- **Bills verified before lines** are untouched.
- **Stock already on hand at cutover** needs an opening position on the approved opening-stock account.
- **Part 2's historical findings** (13 receipts without a GRN, 9 issues with no posting, 3 adjustments, one non-stock line in Inventory) are unchanged and still need separately authorised remediation.

---

## 12. Target readiness and deployment prerequisites

Not verified on any target. In order:

1. Commit; review.
2. Migrations, in order: `2026_10_07_000001` (finance_event_postings, Part 1), `2026_10_08_000001` (Part 2), `2026_10_13_000001` (this). The third depends on the second.
3. Finance approves and activates the seven accounts in §9. Until then: no stock count, opening balance, write-off, company-use issue or close-out can be recorded, and any bill with a difference off the shelf cannot be verified. **Deploying before these are approved will stop Stores write-offs and counts.**
4. Confirm who holds each permission pair in §9.
5. Decide the open legacy orders (§11).
6. Run `finance:posting-health`.

---

## 13. Unresolved risks

**P0 — the reasons this is PARTIAL**

1. **Non-recoverable VAT and price differences on non-stock purchases and on issued stock go to the variance account, not to the project or expense that bore the cost.** The brief asks that non-recoverable tax be recognised as inventory or expense cost and not automatically as variance. That is met only for stock on hand. A service billed at 82,000 against 80,000 confirmed leaves the project at 80,000 and 2,000 in variance.
2. **Core control accounts still fall back to the reference chart.** The brief says no fallback to reference accounts for any financial movement. Inventory, the receipt accrual, Accounts Payable, Input VAT and withholding use an approved mapping where one exists and the reference code where none does.
3. **Project issues and returns are not atomic with the ledger.** Recorded, visible and retryable, but two steps.
4. **Boards and rolls take no revaluation.** Their value is held per unit; a price difference on them is all variance.

**P1**

5. No quarantine bucket for bulk stock that cannot be posted.
6. No screen for tax-inclusive quotes on an order, for accrual close-out, or for the new statuses on order and receipt screens. Requisition prices are assumed net.
7. Credit notes only against line-matched order bills. A direct bill or an old lineless bill can be reversed but not part-credited. No supplier refund document: a credit beyond the unpaid balance is refused.
8. eTIMS is advisory at verification.
9. A purchase-order *update* that fails the tax-quote check returns a server error rather than a validation message.
10. Offcut registration has no dedicated test under the new rules.
11. Procurement and Stores routes still rely on in-action permission checks (Report 76).

---

## 14. Recommended 76B entry criteria

1. Finance signs off §2, in particular stock revaluation on a price difference and the split rule.
2. A decision on each P0 in §13: accept as policy, or build. Items 1 and 2 are the ones I would build before 76B.
3. The seven accounts approved and active on the target.
4. Migrations run and `finance:posting-health` clean on the target.
5. Open legacy orders reviewed for price basis.
6. This work committed and reviewed. It is large and currently exists only as an uncommitted working tree.
