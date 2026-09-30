# 59 — Finance Frontend Stream B Closure: W1 Legacy Boundary Extraction & Final Validation

**Date:** 2026-09-29
**Environment:** local development / DDEV only
**Production:** untouched
**Verdict:** **STREAM B COMPLETE — W1 BOUNDARY CLOSED, LEGACY MODAL RETIRED**
(M-Pesa remains *blocked by business configuration*; browser screenshots remain unavailable — §23.)

---

## 1. Executive Summary

W1 now has exactly two deliberate surfaces:

| Surface | Route | Responsible for |
|---|---|---|
| **Finance Sales & Receivables** | `/finance/invoices`, `/finance/invoices/:id`, `/finance/receipts` | invoice book and lifecycle, receipts, verification, correction, allocation, client money held, credit notes, evidence, receivable position |
| **Project Billing** | `/finance/project-billing/:id` (index at `/finance/project-receivables`) | commercial basis / quote exception, project deposit terms, production release/override, split receipts, project history — and links into the Finance workspace |

`EnquiryFinanceModal.vue` (1,626 lines) has been **deleted**, with its spec. Every live capability it held was traced to a new home first (§10); none was lost. The Project Billing page is composed of seven small components and does not repeat any invoice or receipt workflow.

The credit-note accounting defect from Report 58 §37 was confirmed and **corrected**: a draft credit note no longer reduces any balance, ageing figure, allocation capacity, client position or billing headroom. Issuing it moves all of them — and Accounts Receivable in the ledger — together. A new issue-time check prevents a credit note from undercutting money applied while it was a draft.

| Check | Result |
|---|---|
| Backend full suite | **1,539 tests / 10,865 assertions PASS** (544 s; see §19) |
| Finance feature suite | **467 tests / 2,841 assertions PASS** (457 + 10 new) |
| Dedicated W1 group (9 files) | 100 tests PASS |
| Frontend suite | **30 files / 229 tests PASS** (213 − 6 retired modal tests + 22 new) |
| ENG-1 `vue-tsc --build --force` | **256 diagnostics; error set identical to `HEAD` — 0 new, 0 removed** |
| Production build | **PASS** (1,930 modules) |
| API contract | **163 Finance call sites, 0 unmatched**; W1 + Project Billing 32/32 |
| Production / migrations | untouched / none |

---

## 2. Previous State (Report 58)

Verdict *PARTIAL — functional with documented legacy W1 dependencies*. The modal remained the only home for four project-specific capabilities, and Report 58 §37 documented — and a test deliberately pinned — that a draft credit note reduced `withNetTotal()`.

**Session note.** On taking over, the working tree held a one-line uncommitted change to `ProjectInvoice::scopeWithNetTotal()` (drafts excluded), written at 05:37 by a concurrent Codex session running this same brief. The user stopped that session and asked this one to take over. That edit was reviewed and kept; it was incomplete (the SQL state filter, allocation cap, billing cap, client position and issue path still counted drafts) and is completed here (§11–12).

---

## 3. Audit of the Legacy Dependencies

The modal was read in full. It held the four capabilities Report 58 named **and six more** that the redesigned screens did not yet cover. Each was audited individually.

| Function | Genuine Project context? | Backend route | Permission | Was in | Now in | Finance link |
|---|---|---|---|---|---|---|
| Quote waiver / no-quote exception | **Yes** — decides the project's billable amount | `POST projects/enquiries/{e}/quote-waiver` | `finance.receivables.billing_basis` | modal, Receipts tab | `CommercialBasisPanel` | Invoice detail → Project billing |
| Project receivables terms (deposit % before production) | **Yes** — per-project mobilisation gate | `PUT …/receivables-terms` | `billing_basis` | modal | `DepositTermsPanel` | — |
| Split receipt across projects | **Yes** — one transfer, several jobs (§6) | `POST …/payments` (`received_amount` > `amount`); `GET/POST projects/receivables/receipts/…` | `record` | modal form + index "Cash to allocate" tab | `ReceiptRecorder` (split total), `SplitReceiptsPanel`, `UnallocatedReceiptsList`, `ReceiptAllocationForm` | Receipt list → project billing |
| Production release / override | **Yes** — operational gate | `POST …/release` | `release`; below target also `override` | modal footer + details | `ProductionReleasePanel` | — |
| Project billing history | Yes | `governance_audit_logs` (via new projection) | receivables read | modal Audit tab | `BillingHistoryPanel` | — |
| Project money position (incl. revenue/cost/margin) | Yes | `ClientFinancialPositionService` (via projection) | `finance.receivables.read` | modal Invoices tab | `FinancePositionPanel` | View invoices / receipts / client position |
| Receipt correction (before posting) | **No** — general receipt lifecycle | `PUT …/payments/{p}` | `finance.receivables.correct` | modal | **Finance Receipts** (`ReceiptCorrection`) | — |
| Receipt evidence (upload / open) | No | `POST …/payments` (multipart) | `record` | modal | `ReceiptRecorder`, Receipts list | — |
| Invoice & credit-note evidence (list/download; credit-note upload) | No | `…/invoices/{i}/attachments[/…/download]` | read / `billing_basis` | modal | **Invoice detail** (Evidence section, credit-note form) | — |
| Credit-note VAT and discount per line | No | `POST …/credit-notes` | `reverse` | modal (Report 58's new form lacked it) | **Invoice detail** credit-note form | — |
| Master payment-term admin (create / activate / default) | **No** — company-wide master data | `POST/PUT finance/payment-terms` | `billing_basis` | modal Invoices tab | **Finance setup → Payment terms** | — |

---

## 4. Quote Waiver / No-Quote Exception

**Live control confirmed.** Invoicing requires a positive billing basis (`createProjectInvoice`). Without an approved quote, the only live route to one is the project quote waiver: an agreed price (> 0), a reason of ≥ 15 characters, recorded against `quote_waived_by` / `quote_waived_at` with a `GovernanceAuditLog`. The backend refuses it outright when an approved quote exists.

**Finding.** The invoice-level no-quote exception (`no_quote_exception_*` fields, approver must hold `override`) exists in the backend but has **never had a UI** — neither the modal nor the Report 58 editor sent those fields. It is shown read-only on invoice detail when present; no UI was invented for it.

`CommercialBasisPanel` shows: whether an approved basis exists, whether an exception is required, the exception amount, reason, actor, timestamp and status (*Approved quote* / *Controlled exception* / *No agreed price — billing blocked*). The form is rendered only when the backend's `set_commercial_basis` action is allowed; otherwise the backend's reason is shown. The frontend cannot manufacture an exception: the route's permission middleware and the approved-quote refusal are unchanged, and both are exercised by tests.

---

## 5. Project Receivables Terms vs Finance Payment Terms

There were never two payment-term definitions — but the modal made them look like one setting:

- **Finance master payment terms** (`payment_terms`: *Net 30*, custom, default) — one company-wide list that each invoice picks to propose its due date. Now managed at **Finance setup → Payment terms** (`/finance/setup/payment-terms`); read by anyone with receivables read, changed only with `billing_basis`.
- **Project receivables term** — `project_enquiries.mobilization_threshold_percentage`, the deposit share that must be received and verified before production. Managed in `DepositTermsPanel`, with the last change's actor, time, from/to and reason from the audit log.

The deposit panel says in words that invoice payment terms live in Finance setup.

---

## 6. Split Receipt Across Projects

**Genuinely needed and intentionally supported.** The backend models one bank transfer as a `ClientReceipt` (`received_amount`) with one `EnquiryPayment` share per project. Recording with `received_amount > amount` leaves a remainder on the client receipt; `POST projects/receivables/receipts/{r}/allocations` creates another project's share, which is then verified independently on that project. Allocation over the remainder is refused under a row lock. This is not duplicated by invoice allocation, which applies a verified share to an invoice.

Extracted into focused components:

- `ReceiptRecorder` — "This transfer also covers other projects" + *Total of the whole transfer*; refuses a total below the project's share.
- `SplitReceiptsPanel` (Project Billing) — receipt, client(s), total, every project share with verification state, the invoices each share settles, and what is left unallocated.
- `UnallocatedReceiptsList` (Project billing index, "Cash to allocate") — cross-project list of receipts with money left.
- `ReceiptAllocationForm` — allocates the remainder to a project found by **server search** (the old tab offered only the ≤ 50 projects loaded on the page).

**Observation (not changed).** `FinanceService::allocateReceipt()` does not require the target project to belong to the same client. That may be legitimate (an agency paying for several clients), so the backend was not changed; the UI now warns and escalates the confirmation when the target project's client differs from the receipt's. For Finance to decide.

---

## 7. Production Deposit Release / Override

Kept as a Project control. `ProductionReleasePanel` shows blocked/ready state, the release (early or not), the releaser, time and reason (from the gate's own audit log) and a post-release breach warning. Rules are the backend's: `release` permission; below target also `override`, plus a mandatory reason (`ReleaseFinanceGateAction`). The button appears only when the backend's `release` action is allowed; early release needs the reason and a warning confirmation.

---

## 8. Project Billing Page

`GET api/finance/project-billing/{enquiry}` (new, read-only, `ProjectBillingController`) composes the existing authorities — `FinanceService::getPaymentProgress()`, `ClientFinancialPositionService`, the project's `GovernanceAuditLog`, and `ReceivablesActions::forProjectBilling()` — and adds no calculation. Access is `ProjectFinancialAccess::canReadReceivables()`, the same as the legacy `finance-progress` read. The money position is included only for `finance.receivables.read` holders, matching its own endpoint. People are `{id, name}` only.

`ProjectBillingView` composes:

| Section | Component |
|---|---|
| Commercial Basis | `CommercialBasisPanel` |
| Billing Terms | `DepositTermsPanel` |
| Finance Position | `FinancePositionPanel` (invoiced, received, outstanding, held for client, remaining to invoice; drafts shown as *pending*; revenue/cost/margin) |
| Production Deposit Control | `ProductionReleasePanel` |
| Split receipts | `SplitReceiptsPanel` |
| History | `BillingHistoryPanel` |
| Finance Links | View invoices · View receipts · View client position · New invoice |

Record receipt opens the shared `ReceiptRecorder` pre-set to the project.

---

## 9. Duplicated W1 Implementation Removed

Removed with the modal: its invoice register, invoice form with browser pricing, check/return/issue/void buttons, credit-note lifecycle, invoice allocation rows, receipt register with verify/correct/reverse, and its private copies of `formatCurrency`/`apiErrorMessage`. Project Billing contains none of these (asserted by test). `ProjectReceivablesIndex` rows now open the Project Billing page; its hand-built "Cash to allocate" table and allocation code were replaced by `UnallocatedReceiptsList`.

**Not carried, deliberately:** the modal's in-browser invoice line preview (gross/discount/net/tax) and its "exceeds remaining billable" preview. Report 58 moved pricing to the server (`InvoicePricer`), and the backend returns the exact over-billing refusal; re-adding browser arithmetic would reintroduce a second calculation.

---

## 10. `EnquiryFinanceModal` Retirement

Precondition met: each row in §3 has a working, tested replacement. Then deleted:

- `src/modules/finance/receivables/components/EnquiryFinanceModal.vue`
- `tests/unit/finance/enquiryFinanceModal.spec.ts` (its assertions are covered by `w1.spec.ts` and `projectBilling.spec.ts`)

Old deep links `/finance/project-receivables?enquiry={id}` now redirect (route guard) to `/finance/project-billing/{id}`, and `projectBillingLink()` points there. A test asserts the file no longer exists. **Zero live capability lost.**

---

## 11. Credit-Note Accounting Defect — Investigation

**Lifecycle confirmed.** A credit note is a `ProjectInvoice` row with `credits_invoice_id`, stored negative. `createCreditNote()` makes a draft; `issueCreditNote()` requires a different user holding `reverse`, sets `issued`, and **only then** posts `ReceivablesPostingService::postCreditNoteIssued()` (Dr Revenue, Dr Output VAT, Cr Accounts Receivable). Voiding an issued note reverses that entry.

**Therefore** counting a draft made the receivables book disagree with Accounts Receivable in the ledger. No test or document asserted that drafts should have a financial effect: every existing credit-note balance test issues the note first, and the only assertion of the draft effect was Report 58's deliberate pin.

**Every reader inspected:**

| Reader | Before | After |
|---|---|---|
| `ProjectInvoice::scopeWithNetTotal()` (list, detail, per-project list, ageing) | non-void notes | issued only (`EFFECTIVE_CREDIT_STATUS`) |
| `InvoiceState::whereState()` SQL (state/overdue filters) | non-void | issued only |
| Allocation cap (`allocatePaymentToInvoice`) | non-void | issued only, via `ProjectInvoice::effectiveNetTotal()` |
| Over-billing cap (`createProjectInvoice`) | a draft credit note **freed** headroom | draft credit notes excluded; draft invoices still reserve |
| `ClientFinancialPositionService` | invoiced / revenue / outstanding counted **draft invoices and draft notes** | issued documents only; drafts reported as `draft_invoiced` and `pending_credit_notes` |
| `WorkInProgressReleaseService::billedFraction()` | posted documents only | unchanged (already correct) |
| `createCreditNote()` remaining-to-credit cap | non-void (drafts reserve crediting headroom) | **unchanged, deliberately** — a document limit that stops two drafts over-crediting, not a balance |
| Void of an invoice with a non-void credit note | refused | unchanged |

**New invariant at issue.** Since a draft no longer reduces allocation capacity, money can be applied after the draft was raised. `issueCreditNote()` now locks the credited invoice and refuses the issue if the net after the note would fall below what is already allocated ("Reverse the excess allocation first, or void this credit note"), mirroring the check `createCreditNote()` already made.

---

## 12. Credit-Note Outcome

| State | Behaviour now |
|---|---|
| Draft | Visible as pending: `pending_credit_amount` on invoice detail ("… in credit notes awaiting issue — not yet deducted"), a *Pending — no effect until issued* label, and the position's `pending_credit_notes`. **No** change to balance, ageing, allocation capacity, client position, billing headroom or ledger. |
| Issued | Reduces the invoice's net total, balance, ageing, allocation capacity and client position — all equal to Accounts Receivable in the ledger. |
| Void (of an issued note) | Existing controlled reversal (reversing journal); every reader returns to the original figures. |

Regression tests (`ProjectBillingClosureTest`) assert each row across **every** reader, including the AR ledger balance. The draft test was **mutation-checked**: restoring the old `!= 'void'` rule makes it fail ("detail_net moved on a DRAFT credit note").

The credit-note form on invoice detail now carries per-line VAT treatment (defaulting to the invoice's) and discount, as the modal's did, plus optional evidence.

---

## 13. One Source of Financial Truth

For one invoice, with a draft credit note and after issuing it, the test compares: Finance invoice detail, Finance invoice list, per-project invoice list, receivables ageing row, client position (`amount_invoiced`, `invoice_outstanding`) and the Accounts Receivable ledger balance. They agree in both states (100,000 → 60,000) and after voiding the note (back to 100,000). Allocation capacity is checked in both states. No screen computes a figure: Project Billing, invoice detail and the receipt list render backend values only.

---

## 14. M-Pesa

**BLOCKED BY BUSINESS CONFIGURATION**, unchanged. `ReceiptRecorder` still shows exactly *"M-Pesa receiving account has not yet been configured by Finance."* with no selector. No account was created or mapped.

---

## 15. My Actions

Revalidated (`FinanceWorkQueueTest`, `w1.spec.ts`):

- Check / Issue / Correct invoice → `/finance/invoices/{id}`
- Verify receipt → `/finance/receipts?receipt_id={id}`

My Actions remains navigation only. It has no project-billing work types (waiver, deposit change and release are not queued), so nothing links to Project Billing from it; no backend URL referenced the old `project-receivables` deep link. **Observation:** issuing a draft credit note is a maker/checker step with no My Actions item — a candidate for a later stream, not added here.

---

## 16. Permissions

All checks are permissions; no role names were introduced.

| Control | Enforced by (backend) | Shown by (frontend) |
|---|---|---|
| Invoice read | `finance.receivables.read` (projections) | route + nav |
| Prepare / issue | `billing_basis` (route) + `ReceivablesActions` | backend `actions` |
| Check / return | `invoice_check` + preparer ≠ checker | backend `actions` |
| Void / credit note / reverse receipt | `reverse` | backend `actions` |
| Receipt record / allocate / split allocate | `record` | `actions` / `can()` |
| Receipt verify | `verify` + recorder ≠ verifier (`SelfApproval`) | backend `actions` |
| Receipt correct | `correct` | backend `actions` |
| Commercial exception (waiver) | `billing_basis` + approved-quote refusal | `set_commercial_basis` action |
| Deposit terms | `billing_basis` | `change_deposit_terms` action |
| Production release / early override | `release` / `override` + reason | `release` action |
| Payment-term admin | `billing_basis` | `can()` |
| Project billing read | `canReadReceivables` (position needs `read`) | route |

---

## 17. Maker/Checker

Re-run and passing: invoice preparer ≠ checker, returned-invoice correction by the preparer only, then independent re-check (`ReceivablesWorkspaceTest`, `InvoiceReviewWorkflowTest`); receipt recorder ≠ verifier unless `approvals.self_approve` (`ReceivablesWorkspaceTest`, `ClientFinancialPositionTest`); credit-note preparer cannot issue (`CreditNoteTest`, `ProjectBillingClosureTest`). The extraction touched none of these rules; the new issue-time allocation check is an additional guard.

---

## 18. Frontend Tests

New `projectBilling.spec.ts` (22 tests): Project Billing composition and Finance links; invoice/receipt non-duplication; position figures from the API with drafts as pending; record-receipt gating; quote exception (not offered with approved quote, backend reason without permission, documented save, actor/time/reason display); deposit terms change and history; release refused / early release with reason and confirmation / released-by and breach; split receipt display, server-searched allocation with other-client warning, split total recording; draft credit note pending and not deducted; issued credit note effect; credit-note VAT/discount; receipt correction and evidence; payment-term admin permissions; modal file removed; old deep-link redirect.

`w1.spec.ts` fixtures gained `pending_credit_amount` / `evidence_path`, and one selector was made specific. **Full suite: 30 files, 229 tests, PASS.**

---

## 19. Backend Tests

All runs sequential against `db_test`; a competing-run check preceded each.

- New `ProjectBillingClosureTest`: **10 tests / 158 assertions PASS**.
- Dedicated W1 group (ProjectBillingClosure, ReceivablesWorkspace, CreditNote, ClientFinancialPosition, InvoiceReviewWorkflow, ReceivablesAgeing, ReceivablesPosting, DataIntegrityInvariants, FinanceWorkQueue): **100 tests PASS** after one expected update — Report 58's pinned draft-balance assertion now asserts the corrected value (80,000, with 10,000 pending).
- Finance feature suite: **467 tests / 2,841 assertions PASS**.
- Full backend suite: **1,539 tests / 10,865 assertions PASS** in 544.30 s (Report 58 baseline 1,529 + the 10 new tests).

---

## 20. API Contract

`route:list --json` against every `api.*` call in `src/modules/finance`: **163 call sites, 0 unmatched** (15 base-constant URLs were resolved by hand). W1 + Project Billing: **32/32** endpoint shapes matched, including the new `GET finance/project-billing/{enquiry}`. The modal's calls were removed only after their consumers were gone; every route it used remains in use.

---

## 21. ENG-1

`npx vue-tsc --build --force --pretty false`: **256 diagnostics**. The normalised error set was diffed against a pristine `git archive HEAD` copy: **0 new, 0 removed**. All new Project Billing, Payment Terms, receipt-correction and W1 files have zero diagnostics.

---

## 22. Build

`npm run build`: **PASS**, 1,930 modules; `ProjectBillingView` 25.4 kB, `PaymentTermsView` 4.9 kB. Existing chunking warnings unchanged.

---

## 23. Visual Validation — Limitation

No browser automation (Playwright, Puppeteer, Chromium) is installed, and none was installed for this. No dev server was started, so nothing could reach the production API; the development `VITE_API_BASE_URL` is `/system` (proxied to local DDEV). Invoice list, invoice detail, receipts and Project Billing are validated by component tests against backend-shaped fixtures, the build and the contract check, **not by screenshots**.

---

## 24. Files Changed

### Backend
- `app/Modules/Finance/Models/ProjectInvoice.php` — `EFFECTIVE_CREDIT_STATUS`, issued-only `withNetTotal()`, `effectiveNetTotal()`
- `app/Modules/Finance/Support/InvoiceState.php` — issued-only net in state filters
- `app/Modules/Projects/Http/Controllers/EnquiryController.php` — allocation cap, over-billing cap, issue-time credit check, client on unallocated receipts
- `app/Modules/Finance/Services/ClientFinancialPositionService.php` — issued-only figures, `draft_invoiced`, `pending_credit_notes`
- `app/Modules/Finance/Controllers/ProjectBillingController.php` (new)
- `app/Modules/Finance/Support/ReceivablesActions.php` — `forProjectBilling()`
- `app/Modules/Finance/Controllers/ReceivablesController.php` — `pending_credit_amount`, receipt `evidence_path`
- `routes/api.php` — `GET finance/project-billing/{enquiry}`
- `tests/Feature/Finance/ProjectBillingClosureTest.php` (new); `ReceivablesWorkspaceTest.php` (pin updated)

### Frontend
- New: `views/ProjectBillingView.vue`; `components/project-billing/{CommercialBasisPanel, DepositTermsPanel, FinancePositionPanel, ProductionReleasePanel, SplitReceiptsPanel, BillingHistoryPanel, ReceiptAllocationForm, UnallocatedReceiptsList}.vue`; `components/ReceiptCorrection.vue`; `setup/views/PaymentTermsView.vue`; `receivables/projectBilling.spec.ts`
- Changed: `receivables/w1.ts`, `ReceiptRecorder.vue`, `ReceiptListView.vue`, `InvoiceDetailView.vue`, `InvoiceListView.vue`, `ProjectReceivablesIndex.vue`, `router/finance.ts`, `navigation.ts`, `w1.spec.ts`
- Deleted: `EnquiryFinanceModal.vue`, `tests/unit/finance/enquiryFinanceModal.spec.ts`

No migration. Nothing committed or pushed.

---

## 25. Known Issues / Decisions for Finance

1. **M-Pesa** — blocked until Finance activates and ledger-links a mobile-money source.
2. **Cross-client split allocation** — the backend allows a receipt share on another client's project; the UI warns. Finance to confirm whether it should be refused (§6).
3. **Invoice-level no-quote exception** — backend-only, no UI; the project quote waiver is the live control (§4).
4. **Credit-note issue has no My Actions item** (§15).
5. **Visual evidence** — not produced (§23).
6. **WIP on credit notes** — pre-existing and documented in `createCreditNote()`: a credit note does not reverse Work-in-Progress already released to Cost of Sales. Out of scope.

---

## 26. Stream B Completion Matrix

| Capability | Status |
|---|---|
| Cross-project invoice list, filters, detail | COMPLETE (Report 58) |
| Prepare / check / return / correct / issue / void | COMPLETE |
| Receipts: record (incl. split, evidence), verify, correct, reverse | COMPLETE |
| Allocation (invoice) and split allocation (project) | COMPLETE |
| Client money held | COMPLETE |
| Credit notes: VAT/discount lines, evidence, independent issue | COMPLETE |
| Draft ≠ accounting effect; one source of truth | COMPLETE — corrected and proven across all readers |
| Payment terms: master list (Finance setup) vs project deposit term | COMPLETE — separated |
| Quote waiver / controlled exception | COMPLETE — Project Billing |
| Production release / override | COMPLETE — Project Billing |
| Project billing history and position | COMPLETE — Project Billing |
| Legacy modal | **RETIRED** — zero live capability lost |
| My Actions links | COMPLETE |
| M-Pesa | BLOCKED BY BUSINESS CONFIGURATION |
| Visual validation | NOT AVAILABLE — documented |

---

## 27. Next Stream

With the W1 boundary closed, the next stream is **Stream C — W2 Purchasing & Payables**: Purchase Requisition → Purchase Order → GRN → Supplier Bill → WHT → Supplier Payment, including the known role-based supplier-bill verification permission issue. No Stream C work is included here.

---

## 28. Final Verdict

### STREAM B COMPLETE — W1 BOUNDARY CLOSED, LEGACY MODAL RETIRED

Cross-project Finance functions and project-specific commercial controls are now two deliberate surfaces, joined by links rather than by a shared implementation. `EnquiryFinanceModal` is gone with no live capability lost, the draft-credit-note defect is corrected and proven against the ledger, and every validation gate passed. M-Pesa configuration and screenshot evidence remain the documented, non-blocking limitations.
