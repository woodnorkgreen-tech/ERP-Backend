# 76A — P0 Financial & Cost Integrity Closure, Part 2

**Procurement, Stores & Finance accounting integrity**

**Date:** 2026-10-13
**Baselines:** `76_CROSS_MODULE_FINANCIAL_COST_FLOW_AUDIT.md`, `76A_P0_FINANCIAL_COST_INTEGRITY_CLOSURE_PART1.md`
**Scope:** P0-3, P0-4, P0-5, P0-6 and the company-paid authority control, under WNG decisions A–E.
**Code:** in the working trees of `ERP-Backend` (on `master@f8c19a7`) and `ERP-Frontend` (on `master@043b7c3`). **Nothing is committed, pushed or deployed.**
**Boundary kept:** no production access or data, no worker started, no queued job processed, no historical remediation, no account number chosen, no governed mapping activated outside tests, no cutover, no chart completion, no W8, no 76B.
**Verdict:** 76A COMPLETE — P0 FINANCIAL & COST INTEGRITY CONTROLS CLOSED; CROSS-MODULE WORKFLOW IMPROVEMENTS REMAIN. The qualifications that sit behind that verdict are in §22 and should be read with it.

> **Superseded in part by `76A_FINAL_ACCOUNTING_INTEGRITY_ACCEPTANCE.md` (76A-3, same date).** Three behaviours described below changed there: a bill's receipt value is now computed from its lines and is no longer typed (§4, §19 item 1); a price difference on stock still on hand now revalues that stock instead of going to variance (§4, §6); and damage, waste and company use with no approved account are now refused rather than held (§9). Bill reversal and credit notes (§19 item 2) now exist.

---

## 1. Executive summary

All four accounting findings and the authority control are implemented and tested by account and amount.

| Item | What was wrong | Now |
|---|---|---|
| **P0-3** Receipt ↔ invoice | The bill debited Accrued Expenses for its own net value, whatever the receipt had accrued. Any difference stayed in the accrual and in stock value for ever. | The bill clears exactly the receipt value it covers. Any difference is posted as a purchase price variance to an account Finance has approved, or the bill is not verified. |
| **P0-4** Non-stock purchases | A PO line that was not a Stores item accrued to Inventory and never became a cost. | Its receipt is the cost: Dr WIP or expense / Cr Accrued Expenses. It never touches Inventory. The bill adds no second cost. |
| **P0-5** Stock without a GRN | Stores could receive bought stock by hand. The shelf gained stock the ledger never saw. | Refused: "Purchased stock must be received through the approved GRN." Stock that was not bought enters as a controlled adjustment that posts to the ledger in the same transaction. |
| **P0-6** Damage, waste, company use, adjustments | No accounting, or a project fallback. | Each has its own posting on its own governed account. None is a project cost. |
| **Decision E** | `finance.costs.verify` alone could create a Payment and reduce the float. | The verifier must also hold `finance.petty_cash.create_disbursement`. Enforced on the server. |

Three things you should know before relying on the table:

1. **Four accounts are not approved, so four kinds of transaction cannot post at WNG yet.** Purchase price variance, company use of stock, damage and waste have no account until Finance approves one in Finance Setup. The software will not choose. This is configuration, not a software gap, but it is real (§16, §22).
2. **A supplier bill has no lines or quantities.** The system therefore cannot work out for itself which part of a receipt a bill covers. The person entering the bill states it when the price differs. An unstated difference on a part bill is not hidden — it shows as "received, not invoiced" on the order — but nothing forces it closed (§19).
3. **Most of this was drafted on 7–9 October and left unfinished.** This session verified that draft rather than rewriting it, found and fixed one missed path (scrapping a board in Stores posted nothing), fixed seven type errors and five stale frontend tests, and ran the regression. See §17.

Tests: 19 Part 2 tests, 1,744 backend tests in the regression, 423 frontend tests — all passing.

---

## 2. Decisions implemented

| Decision | Implemented as |
|---|---|
| **A** GRN sets received value; bill sets liability and tax; difference is explicit | `bills.receipt_value_covered` + `price_variance_reason`; variance leg in `JournalPostingService::supplierInvoiceLegs`; three-way match checks it |
| **B** Non-stock never enters Inventory | `ProcurementCostProducer::isNonStock`; receipt posts `receipt-actual` cost line |
| **C** No manual receipt of bought stock | `StockMovementPoster::assertExceptionalReceipt`; board ingest and "type a new balance" shut |
| **D** Damage, waste, company use, counts are not project cost | `InventoryLog::movement_reason`; `StockMovementPostingService::postNonProjectReduction` |
| **E** Payment authority for company-paid verification | `CostVerificationService::maySettleCompanyPaid`, checked where the Payment is created |

---

## 3. Final procurement accounting map

| Event | Quantity | Inventory (GL) | Project cost | AP | VAT | Payment |
|---|---|---|---|---|---|---|
| PO approved | — | — | commitment | — | — | — |
| GRN, stocked line | received | Dr Inventory / Cr Accrued | accrued, not actual | — | none | — |
| GRN, non-stock project line | confirmed | — | **one actual**; Dr WIP / Cr Accrued | — | none | — |
| GRN, non-stock overhead line | confirmed | — | none; Dr expense / Cr Accrued | — | none | — |
| Supplier bill verified | — | — | none | Cr AP | Dr Input VAT, from the bill | — |
| ↳ same bill | | Dr Accrued (receipt value covered); Dr/Cr Purchase price variance | | | | |
| Supplier payment | — | — | none | Dr AP | — | Cr bank or float |
| Stores issue to project | out | Cr Inventory / Dr WIP | **one actual** | — | — | — |
| Company use | out | Cr Inventory / Dr governed expense | none | — | — | — |
| Damage / waste | out | Cr Inventory / Dr governed loss | none | — | — | — |
| Stock not bought (opening, found, correction) | in | Dr Inventory / Cr Opening Equity or Inventory Adjustments | none | — | — | — |
| Approved stock count | ± | Inventory ↔ Inventory Adjustments | none | — | — | — |

---

## 4. P0-3 — GRN ↔ Supplier Bill true-up

**ROOT CAUSE.** `supplierInvoiceLegs` debited Accrued Expenses for the bill's net. The GRN had credited it for quantity × order price. The two were never compared.

**BUSINESS DECISION.** A.

**BEFORE.** PO and GRN 100 × 1,000; bill 105,000 net. Accrued Expenses left 5,000 in debit, Inventory still 100,000, no record of why.

**IMPLEMENTATION.** A bill against an order carries the receipt value it covers, at order prices. Left blank, it covers its own net value, which is what every earlier bill did. Billed-to-date on an order is now measured on that receipt basis, so a price difference is neither extra quantity billed nor a reason to fail the quantity check. A stated difference needs a written reason.

**AFTER / GL EFFECT.**

```
Dr Accrued Expenses          100,000   receipt value covered
Dr Purchase price variance     5,000   (Cr when the supplier charged less)
Dr Input VAT                  16,800   from the bill
Cr Accounts Payable          121,800
```

**PROJECT COST EFFECT.** None. The bill creates no cost line.

**QUANTITY.** Unchanged controls: a bill cannot cover more than is still billable on the order, nor more than has been received. Inventory is never increased by a bill.

**REVERSAL.** A verified bill has no reversal in this system (Report 76; unchanged). See §19.

**TEST EVIDENCE.** `test_b_a_price_difference_is_refused_until_an_account_is_approved_then_posted_as_variance`, `test_b_a_lower_invoice_posts_a_negative_variance…`, `test_c_partial_receipts_and_staged_bills_reconcile_at_every_step` (40 received, 30 billed, 10 billed, 60 received, 60 billed; accrual checked at every step).

## 5. VAT handling

The GRN posts quantity × order price and has no VAT leg. This was already so; it was checked, not changed. Input VAT is posted only from the bill, through the VAT treatment already configured on it. No tax rule was added.

VAT that the treatment marks as not recoverable is part of what the bill costs, so it falls into the variance against the receipt value. Whether WNG wants it there is a question in §20.

## 6. Purchase price variance

No account function existed. One was added as a governed item, `posting.purchase_price_variance`, with no reference code, no suggestion and no default. Until it is approved and active:

- the three-way match shows the check **PURCHASE PRICE VARIANCE ACCOUNT REQUIRED** and the bill cannot be verified;
- nothing is posted to Inventory, Accounts Payable or any expense instead.

A bill with no price difference is unaffected and verifies as before.

## 7. P0-4 — Non-stock purchases

**ROOT CAUSE.** `postGoodsReceipt` accrued every accepted line to Inventory. A line with no library material was marked `not_stocked`, was never issued, and so never became a cost.

**BUSINESS DECISION.** B.

**HOW STOCK IS TOLD FROM NON-STOCK.** No new flag. A line that names a library material, or that Stores has put on the shelf, is stock. Otherwise its expense category decides: the "Direct materials" family is stock; every other family is not. This is the same line the direct-bill form already draws.

**BEFORE.** Installation service, 80,000: Dr Inventory / Cr Accrued. Project actual 0, for ever.

**AFTER / GL EFFECT.** Dr the account its expense category names (WIP for a project line, expense for overhead) / Cr Accrued Expenses. If the category has no account mapped, the line is not posted and the receipt reports **ACCOUNTING CONFIGURATION REQUIRED — EXPENSE CATEGORY ACCOUNT**.

**PROJECT COST EFFECT.** Project line: one actual, at receipt. Overhead line: none.

**RECEIPT REQUIREMENT.** The existing GRN is the service confirmation. No second workflow was built. A non-stock line is confirmed by the person recording the receipt, since there is no shelf for Stores to confirm it onto.

**REVERSAL.** Reversing the receipt line backs out the actual, the journal and restores the commitment.

**TEST EVIDENCE.** `test_d_a_non_stock_project_purchase_is_costed_once…`, `test_e_a_non_stock_overhead_purchase_is_an_expense…`, `test_d_a_non_stock_line_whose_category_has_no_account…`, `test_l_a_non_stock_receipt_reversal_backs_out_the_project_cost`.

## 8. P0-5 — Stock receipts without a GRN

**ROOT CAUSE.** Four routes added stock with no GRN and no journal.

| Route | Class | Now |
|---|---|---|
| Receive Stock with a GRN line | Procurement receipt | Unchanged |
| Receive Stock without a GRN line | Was anything | Refused unless it carries a non-purchase reason |
| Board ingest (`/boards/ingest`) | Procurement receipt by another door | Refused |
| Stock settings "set balance" | Count adjustment by one person | Refused; use a stock count |
| Material desk "receive" | Adjustment | Same reason rule |
| Board and material returns | Return | Unchanged |
| Approved stock count | Count adjustment | Unchanged; already posts |

**BUSINESS DECISION.** C.

**IMPLEMENTATION.** A receipt with no GRN line must name one of three reasons — opening balance, stock found, correction — carry a written explanation and a value, and be made by a Stores reviewer (`stores.review`, the authority that approves counts). No GRN or PO is created to make it fit.

**GL EFFECT.** Dr Inventory / Cr Opening Balance Equity (opening) or Inventory Adjustments (found, correction), posted inside the receipt's own transaction. If the account is not available the receipt is refused and stock does not move.

**PROJECT COST EFFECT.** None.

**REVERSAL.** Stock out, both legs reversed, both movements kept. A second reversal is refused.

**TEST EVIDENCE.** `test_f_purchased_stock_cannot_be_received_without_a_goods_receipt_note`, three `test_g_…` cases.

## 9. P0-6 — Damage, waste, company use, adjustments

**ROOT CAUSE.** A write-off, and an issue with no project, changed the shelf count and posted nothing.

**BUSINESS DECISION.** D.

| Reason | Requires | Debit | Project cost |
|---|---|---|---|
| Project issue | project | WIP (unchanged) | one actual |
| Company use | department, purpose | governed "stock used by the company" | none |
| Damage | description | governed "damaged stock" | none |
| Waste | description | governed "waste and scrap" | none |
| Count adjustment | counted and approved by different people | Inventory Adjustments | none |

An issue must now be for a project or for the company. An issue for nobody is refused. A job reference typed on a company-use issue does not make it a project cost.

**Found in this session.** Scrapping a board that is still in Stores has its own action, which wrote the movement with no value. The damage posting therefore resolved to "no value" and recorded nothing, leaving the board's value in ledger Inventory. It now carries the board's value to the damage account (`test_i_a_board_scrapped_in_stores…`).

**Left as it is, and reported as §7.4 asks.** A board scrapped after it was issued, or scrapped at its quarantine return review, keeps its cost on the project. That is existing behaviour ("scrapped with no project return credit"). It was not changed.

**When the account is not approved.** The stock movement stands, because the stock has physically gone. The posting is held as failed, names the missing account, shows in the Stores finance exceptions list and posts on retry once the account is approved. Nothing is posted elsewhere in the meantime. This differs from exceptional receipts, which are refused outright. It is a judgement; see §20.

**TEST EVIDENCE.** `test_h_company_use…`, `test_i_and_j_damage_and_waste…`, `test_i_a_board_scrapped…`, `test_k_a_count_adjustment…`.

## 10. Reversal behaviour

| Path | Reversal | Refused when |
|---|---|---|
| GRN line | Accrual and journal backed out; commitment restored; line kept and marked reversed | Stock is on the shelf (reverse that first); a bill already covers it |
| Non-stock receipt | Actual, journal backed out; commitment restored | A bill already covers it |
| Exceptional receipt | Stock, Inventory and offset reversed | Already reversed |
| Company use, damage, waste | Inventory restored, expense or loss backed out; AP untouched | Already reversed; write-off from a roll or of a board |
| Count adjustment | A new count posts the opposite entry | A single line of a count cannot be reversed alone |

Each was tested by reversing, then reversing again. The second attempt changes nothing.

## 11. Supplier Bill behaviour

A verified bill posts: Accounts Payable, input VAT, withholding tax, the accrual it clears, and the variance. It does not create a cost line, an inventory receipt or a second expense. Asserted in tests A, B and D (`CostLine::where('source_type', Bill::class)->count() === 0`).

Receipt lines are marked settled only when bills cover the whole accrual on the order, not on the first part bill (this was Report 76 P1-13).

## 12. Single Economic Cost proof

Test A follows one stocked purchase from order to issue and asserts project actual at each step: 0 after PO, 0 after GRN, 0 after bill, 0 after payment, then 40,000 after Stores issues 40 of the 100 units — the issue, and only the issue, is the cost. Test D does the same for a service: 80,000 after receipt, still 80,000 after bill and payment.

## 13. Project cost proof

| Event | Project actual | Test |
|---|---|---|
| Stocked: PO, GRN, bill, payment | 0 | A |
| Stocked: Stores issue | one | A |
| Non-stock project: receipt | one | D |
| Non-stock project: bill, payment | no second | D |
| Overhead purchase | 0 | E |
| Company use, damage, waste, board scrap | 0 | H, I, J |
| Exceptional receipt, count | 0 | G, K |

## 14. Inventory ↔ GL proof

Tests G, H, I, J and K assert the stock quantity and the Inventory account balance together after each movement and after each reversal. Every test ends by asserting no journal is unbalanced.

## 15. Company-paid permission control

The permission that already governs outgoing payments is `finance.petty_cash.create_disbursement`. It gates petty cash disbursements, direct supplier payment and the Finance payment queues. No new permission was created.

| User holds | Company-paid cost | Out-of-pocket cost |
|---|---|---|
| verify only | 403, no Payment, float unchanged | verified |
| payment only | 403 | 403 |
| both | verified, one Payment, float reduced | verified |

The check sits where the Payment is created, so no caller can skip it. The screen disables Verify and shows the message. No role was changed and no permission was granted by code. **Check who at WNG holds both** before this is deployed, or company-paid costs will queue unverified.

## 16. Governance integration

Four items were added to Finance Setup → Accounting Policies: purchase price variance, stock used by the company, damaged stock, waste and scrap. Each follows proposed → approved → active. Only an active version is used; an approved one waiting to be activated is not. An approved account that has since been closed is refused. None is required for the installation to be "ready".

**Not fully governed, stated plainly.** Exceptional receipts and counts use the two account functions that stock counts already used (Inventory Adjustments, Opening Balance Equity). Those resolve through the approved mapping first, but fall back to the reference chart code if no mapping is approved and that account exists. That is the existing Report 70B behaviour, reused as the brief asked. It is not the strict "no default" rule the four new items follow.

## 17. Tests

| Suite | Result |
|---|---|
| `Report76APart2Test` | 19 passed |
| Backend regression: Finance, Procurement, ProcurementStores, Stores, CostCollector, PettyCash, Projects, MaterialsLibrary, Unit | 1,734 passed on `db_report76a2_test` |
| `W7LabourConcurrencyTest`, `RequisitionReceiverDisbursementConcurrencyTest` | 10 refused that database by name; all 10 pass on `db_scratch_test` |
| `Report76AIntegrityTest` (Part 1) | passed |
| Frontend, procurement-stores and finance | 423 passed |
| Vite build | passed |
| `git diff --check` | clean, both repos |
| `event:list` | no application listener registered twice |
| Type check | 267 errors, none on a line this work added |

On the type check: the recorded baseline is 256. The tree stood at 276 when this session began. Seven were on new lines and were fixed, along with two older ones. The other eleven above the baseline are on committed lines and were left alone.

Five frontend tests failed at first. They drove a receipt with no delivery and an issue with no project, which the screens now refuse. The tests were updated to give a reason and a department.

Evidence: `76a2-evidence/`.

## 18. Historical-data implications

Read-only, on the local rehearsal copies. **Nothing was repaired.**

| Finding | Rehearsal target | Live-source copy |
|---|---|---|
| Stock receipts with no GRN and no journal | 13 | 12 (the source has no GRNs at all) |
| Issues with no project and no posting | 9 | 9 |
| Stock adjustments with no accounting | 3 | 3 |
| Non-stock line accrued to Inventory | 1 (3,000) | — |
| Bill net below its receipt accrual | 1: accrued 10,000, bill net 8,620.68, 1,379.32 left in the accrual | — |
| Ledger Inventory against stock at average cost | 12,250 against 10,750 | no ledger |

**SOFTWARE FIXED GOING FORWARD:** all of the above paths.
**HISTORICAL DATA REQUIRES REMEDIATION:** all of the above rows. Stock already on hand at cutover will need an opening position; that is a cutover task.

The 10,000 against 8,620.68 row matters beyond history: see §20, item 1.

## 19. Remaining software gaps

1. **Bills have no lines.** A price difference on a part bill is caught only if the preparer states it, or when it would push billing past what was received. An unstated lower price leaves a visible "received, not invoiced" amount on the order with no way to close it. Bill lines are a redesign and were out of scope.
2. **No reversal or credit note for a verified bill.** Pre-existing. A variance posted in error cannot be backed out through the application.
3. **No PO close or cancel.** Pre-existing; the order position reports received, billed and paid separately and deliberately claims no closure. For 76B.
4. **Orders are not forced to be priced net of VAT.** See §20, item 1.
5. **Procurement and Stores routes still lack permission middleware** (Report 76). The new reversal and adjustment actions check permissions themselves.
6. **Dev database is not migrated.** One migration adds columns to `bills`, `goods_receipt_note_items`, `inventory_logs` and `stores_finance_postings`. Until it runs on a database, the affected screens will fail there.

## 20. Remaining WNG decisions

1. **Are purchase order prices exclusive of VAT?** The receipt accrues at the order price. If orders carry VAT-inclusive prices, as the rehearsal bill suggests, every bill will show a difference equal to the VAT. That is not a price variance and should not go to that account. The rule needs stating and probably enforcing on the order.
2. **Which accounts** for purchase price variance, company use, damage and waste.
3. **Non-recoverable VAT:** to the variance account, as now, or to the cost of the goods?
4. **Damage with no approved account:** hold the posting, as now, or refuse the write-off?
5. **Who holds both** cost verification and payment authority.
6. **Waste on a project:** should some waste be charged to the job? Today none is, except boards scrapped after issue.

## 21. Recommended next phase

Before deploy: answer §20 items 1, 2 and 5; migrate; run `finance:posting-health`. Then 76B: order close and cancel, bill lines, bill reversal or credit note, route permissions.

## 22. Verdict

**76A COMPLETE — P0 FINANCIAL & COST INTEGRITY CONTROLS CLOSED; CROSS-MODULE WORKFLOW IMPROVEMENTS REMAIN**

The reason for COMPLETE and not PARTIAL: on each of the four paths the software now either posts the right accounts or refuses and says what is missing. None still posts to the wrong place without saying so.

**SOFTWARE CAPABILITY COMPLETE** for P0-3, P0-4, P0-5, P0-6 and Decision E.

**CONFIGURATION / ACCOUNTANT APPROVAL REQUIRED** for the four governed accounts. Until they are approved, a bill with a price difference cannot be verified, and company use, damage and waste are held unposted. This does not reopen a P0, because nothing is mis-posted in the meantime, but those transactions do not complete.

What would make this PARTIAL: if WNG reads §19 item 1 or §20 item 1 as a P0. A reasonable accountant might. Both leave a difference visible but unresolved, where Decision A asks for it to be explicitly identified. If so, the gap is bill lines and a net-price rule on the order, and P0-3 stays open until they exist.
