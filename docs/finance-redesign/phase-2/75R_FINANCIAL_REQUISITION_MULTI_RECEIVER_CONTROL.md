# WNG ERP — Report 75R

Financial Requisition Verification & Multi-Receiver Disbursement Control  
Date: 6 October 2026 (Africa/Nairobi)

## 1. Executive summary

This side implementation delivers independent pre-Finance verification and a parent/receiver read projection. It does **not** deliver the complete multi-receiver payment/accountability lifecycle. The outstanding work is software work, not merely business policy confirmation.

The authoritative requisition, its lines, Employee/User records, Payment engine, document sequences and audit log are reused. New requisitions require an assigned independent verifier. Certification does not approve, pay, confirm receipt, accept expenditure or close a requisition. Material changes revoke certification. The UI shows actual parent payments without inventing receiver-level payment allocations.

No deployment, Finance cutover, production migration, production data change, chart completion or W8 was performed. Existing uncommitted Report 75 work was preserved. Report 75 governance module files were not edited. Only the shared permission registry/default role matrix were extended as necessary for explicit verification authority; their pre-existing Report 75 changes and the shared route file changes were preserved.

## 2. Existing workflow discovered

The Financial Requisition screen is backed by `PettyCashRequisitionController` and `PettyCashRequisition`, despite its historical petty cash naming. It already accepts non-float paying accounts through the unified Payment architecture.

Before this change, creation wrote a `pending` requisition. Finance approved it, then paid its entire total in one transfer. Parent statuses subsequently became `disbursed`, `received`, `surrender_pending`, `surrender_returned` or `surrendered`. The controller supports approval rejection, editing/reapproval, signature links, evidence attachments, surrender submission, correction, reconciliation and controlled surrender reversal.

### Required architecture answers

| Question | Finding |
| --- | --- |
| 1. Authoritative Financial Requisition? | `PettyCashRequisition`, table `petty_cash_requisitions`. Procurement's separate `Requisition` is not the Financial Requisition. |
| 2. Multiple lines? | Yes: `PettyCashRequisitionItem`, table `petty_cash_requisition_items`, linked by `requisition_id`. |
| 3. Receiver storage? | Parent and line `payee_id` reference Employee; `payee_name` and `payee_phone` also permit external names. Type `recipient_mode` is `single` or `per_item`. |
| 4. Several intended receivers today? | Lines can name several people, but Finance pays one parent amount; individual line signatures do not identify separate cash transfers. The inspected API does not automatically create unrelated requisitions. |
| 5. Actual money movement? | `Payment`, table `payments`, with paying account, method, amount, processor, date and requisition FK. |
| 6. Settlement service suitable? | Its cash-side engine is reusable. It does not own requisition obligations, receiver allocations or their approval limits. Caller transactions and locks remain necessary. |
| 7. Accountability object? | Parent surrender fields, `PettyCashSurrenderItem`, surrender review history and `PettyCashSurrenderReversalService`. Submission and Finance reconciliation are separate events. |
| 8. Project cost object? | CostCollector `CostLine`/`cost_lines`, produced through existing cost lifecycle services. A request or approval is not an actual expense. |
| 9. Extend what? | Existing parent, requisition lines, Payment FK/relationships, existing document sequence, Finance attachments/audit/read workspace, surrender items/reviews, existing posting/reversal services. |
| 10. What would duplicate architecture? | A second Financial Requisition table, second payment engine, duplicate Employee/Supplier directory, separate surrender system or a parallel project-cost/GL producer. None was introduced. |

Requester is `user_id` (nullable for public requests), department is `department_id`, project/enquiry links are `project_id`/`enquiry_id`, and supplier bill settlement uses `bill_id`. Employee/User are authoritative people records. Existing permission decisions use Spatie permissions and `PettyCashPolicy`, with a global Super Admin bypass; verification deliberately checks an explicit grant instead.

Other inspected integrations: `SpendVoucherSettlementService` uses `PaymentSettlementService`; supplier and salary advance/payroll payment services also reuse it. These distinct obligations are not merged into Financial Requisition lines by this implementation. Generic Finance evidence is stored as `FinanceAttachment` through the existing morph relationship.

## 3. Existing architecture reused

Reused components: Financial Requisition parent/items; Employee and assignable User scopes; `DocumentNumber`; `Payment` and `PaymentSettlementService`; `PettyCashService`; `PettyCashActions`; Finance read workspace; `GovernanceAuditLog`; Finance attachments; existing posting, surrender and CostCollector services.

Existing `PettyCashDisbursementAllocation` records slice a payment across float top-ups; `PaymentAllocation` records link settlements to CostLines. Neither currently represents payment slices against requisition receiver lines. Reusing those names without examining their foreign keys would confuse different obligations.

The new verification migration adds fields to the parent and one explicit permission. It does not create a payment, recipient or surrender table. Verification fields are deliberately excluded from public mass assignment.

## 4. Gaps found

The parent exposed a `HasOne` payment relationship. Payment execution insists on the entire approved parent amount and refuses a second active payment. There is no durable requisition-line-to-payment allocation bridge. Parent advance posting stores one journal link; surrender reconciliation and reversal select one paying account through `disbursement`. Receiver signatures are line/parent facts, not per-transfer confirmation records. Cancellation of unused approved value and closure rules are not implemented.

These are coordinated software changes still needed before multi-receiver transfers can be enabled safely. A relationship change alone would leave incorrect surrender amounts, account routing and reversal behavior.

## 5. Parent requisition design

One parent remains authoritative. A new `disbursements()` collection complements the existing compatibility relationship. The detail projection sums **all active Payments** with the parent's FK; voided records remain visible but are excluded from paid value. Requested value comes from the parent; current verified value is shown only with valid certification; approval is based on recorded approval evidence.

Outstanding = approved − active paid. Negative outstanding is exposed as an over-disbursement exception instead of hidden. Unknown cancellation value is `null`, not zero. Closure is `not_evaluated`, not falsely complete. The projection does not persist duplicated financial totals.

Creation now uses the locked `DocumentNumber::REQUISITION` sequence instead of the former PCR text/MAX calculation. Historical references are retained. Number generation callers already run in transactions. Sequence initialization/reconciliation for an eventual production rollout requires review against historical REQ references; no production sequence was changed here.

## 6. Verification design

Creation and editing require `responsible_verifier_id`. The selector contains assignable active Users with `finance.requisitions.verify`; it publishes only ID and name. The server validates both eligibility and creator separation. No self-verification threshold or implicit exception was invented. No role receives verification authority automatically. The permission is registered in the existing all/grouped catalogues with a human-readable label; default Super Admin grants explicitly exclude it, alongside the existing separate accounting authority exclusions.

States: `pending_verification`, `verified`, `returned_for_correction`, `re_verification_required`; a legacy null state is presented as `not_verified`. New creation submits for verification in the same transaction as parent/line creation and audit recording. Editing/resubmission requires new verification. A separate saved-draft action remains a gap.

`POST requisitions/{id}/verify` accepts `verified` or `returned_for_correction`. It checks the explicit permission, active account, assigned verifier, independent creator, parent state, positive lines/receiver presence and parent/line total equality. Parent and lines are locked for review. Correction requires a nonblank reason. Duplicate reviews cannot add another certification to a document already reviewed.

Certification records `verified_by`, `verified_at`, comment and a fingerprint of the material envelope. Approval holds a parent lock through its write and requires current certification. The payout controller and locked cash service also require current certification, protecting the alternative payout path. Existing documents are not silently grandfathered as verified.

Exact invalidating parent fields: department, purpose, project ID/name, enquiry, venue, bill, requisition type/category, custom fields, frozen type definition, assigned verifier, parent payee ID/name/phone and requested total. Exact line fields: description, remarks, details, amount and payee ID/name/phone; adding/deleting a line also invalidates certification. Eloquent hooks preserve an audit event and revoke certification; restoring the old value does not resurrect it. A fingerprint check also detects writes that bypass model hooks. Receipt/signature metadata and approval timestamps do not invalidate request verification.

The existing create/update API automatically submits the edited request; it does not leave the user with an unsubmitted correction. Legacy approved unpaid requests must be corrected/resubmitted and independently verified before payout. A rollout/backfill decision remains necessary; no historical approvals were modified. Anonymous public requests have no authoritative creator User ID, so creator/verifier independence can only be enforced against recorded internal requester identity. Resolving that identity before certification remains a public-workflow software/policy gap.

## 7. Receiver allocation design

Existing requisition lines remain the intended allocation records. The read projection groups lines by authoritative Employee ID, preserving each line ID, purpose and amount. Two lines for Steve can therefore show one requested receiver total. Names without an authoritative identity remain separate unresolved lines, preventing two people with the same name from being silently merged.

Supplier/service-provider and approved-other-recipient references are not yet implemented. Existing free-text external beneficiaries remain a legacy capability; receiver identity governance is an explicit remaining software gap.

## 8. Child disbursement design

Target relationship: requisition line/receiver allocation → payment-allocation bridge → existing Payment. The bridge must preserve the parent FK, underlying line, amount and receiver identity. One Payment may cover several allocations for the same receiver; one allocation may be paid by several Payments.

The target `REQ-…-D01` reference should be claimed while holding the parent lock, using the existing sequence architecture. The Payment's existing global PAY reference should remain intact. Neither child-reference issuance nor this bridge is implemented. The UI shows real existing Payment references and does not fabricate child transfers.

## 9. Partial disbursement

Parent projection states are `not_disbursed`, `partially_disbursed`, `fully_disbursed`, or `over_disbursement_exception`. KES 50,000 paid against KES 75,000 approved is partial with KES 25,000 outstanding. A void reduces paid value.

This is a truthful read projection, **not** a partial-payment execution feature. The legacy execution service still requires one full parent payment.

## 10. Multiple payments per receiver

The target bridge supports instalments and grouping independently of requisition lines. Receiver-level paid amounts are currently `null`/`not_linked`; legacy parent payment value is not apportioned by guesswork. Obligation-level concurrency locks, overpayment prevention, request-bound idempotency and grouped payment execution remain to be implemented and tested.

## 11. Finance processing

Normal approval and payout actions now require current verification. Normal pending-approval/approved Finance projections filter for recorded verified status; action eligibility repeats the current fingerprint check. The creator register exposes verification-state filters, and assigned verifiers can see their own assigned requests without gaining the entire Finance queue.

The parent detail shows requested, approved, active paid and outstanding value, intended receiver cards, recorded payments and independent verification/receipt/accountability state. A receiver-specific Process Payment action is not enabled. Existing full-parent processing remains the only payment write path.

## 12. Receipt confirmation

Verification and payment do not create receipt confirmation. Existing parent/line signature controls remain in place. The projection explicitly describes the old parent confirmation as `confirmed_parent_receipt`, not confirmation of every receiver payment.

A per-Payment confirmation record with actor, time, evidence, issue and controlled receiver authority remains software work. Existing public signing tokens must not be reused as an unrestricted authority to confirm other receivers' transfers.

## 13. Accountability/surrender

The existing surrender system remains authoritative. `surrender_pending` means submitted/under Finance review; `surrender_returned` means correction; accepted requires the reconciled state and reconciliation timestamp. Paid and received do not imply accepted accountability.

Receiver/allocation links to existing surrender items/reviews are missing. Multi-source cash return routing, partial receiver surrender, acceptance aggregation and reversal attribution must be added to this existing system, rather than creating a second surrender workflow.

## 14. Project-cost integration

`PettyCashCostProducer` and CostCollector retain their current commitment/actual-cost lifecycle. Requisition approval can create a commitment; advance cash movement is not a second expense; accepted surrender and its existing CostLine/GL linkage remain the expense path. The new verification/read services never create CostLines or journals.

Future child settlement must retain this separation and correctly release or reduce commitments; emitting a direct-expense event for an advance would double-count costs. No claim is made that the incomplete child workflow has passed W6 integration tests.

## 15. Accounting/GL integration

Existing `JournalPostingService`, `PettyCashAdvancePoster`, `PaymentReversalService` and surrender reversal remain authoritative. Existing posting uses account mappings and period controls. No hard-coded account IDs or new journal entries were introduced.

Multi-receiver support still requires per-Payment advance failure/retry tracking and surrender/refund routing across actual paying accounts. Keeping one parent journal link while permitting multiple transfers would be insufficient. Existing period checks must continue to apply to posting and reversals.

## 16. Cancellation/unused balance

Not implemented. Unknown cancellation amount is displayed as unknown, not as paid or as zero. The future controlled action needs immutable cancellation allocations, reason, actor, time, approval where required and rules preventing cancellation of already-paid value. Approved KES 75,000 / paid KES 50,000 / unused KES 25,000 must remain those three separate facts.

## 17. Parent closure

No automatic closure was introduced. Type-specific rules for completed/cancelled allocations, unexplained outstanding balances, required confirmations, accepted accountability and unresolved exceptions need explicit implementation. Which types require accountability/confirmation and which authority accepts unused balances require WNG confirmation.

## 18. Permissions

| Action | Current authority / remaining work |
| --- | --- |
| Create request | Existing creator endpoints; verifier selection is mandatory. A distinct create-requisition permission is not yet enforced by this report. |
| Verify | New explicit `finance.requisitions.verify`; assigned active User only; independent creator. Super Admin bypass does not apply. |
| Approve | Existing `reviewRequisition` / `finance.petty_cash.edit_disbursement`, plus existing self-approval control and new verification prerequisite. |
| Finance review | Existing Finance workspace/report visibility and review abilities. |
| Process payment | Existing `create` / `finance.petty_cash.create_disbursement`, existing self-payment rules, new current-verification prerequisite. |
| Confirm receipt | Existing requester/public-signature authority; receiver-specific authority remains a gap. |
| Review accountability | Existing surrender/reconcile/reversal controls; distinct receiver review permission remains a gap. |
| View requisition | Creator, assigned verifier, or existing all-requisitions authority. |

Existing approval policy still has a Super Admin bypass. This report does not silently redesign Report 75 authority policy or claim that approval/review/payment privileges are fully separated. WNG role assignment is required before the new verifier selector has eligible users.

## 19. Audit story

Verification uses `GovernanceAuditLog`, scoped to the existing parent model and ID. Submission, correction return, verification and material invalidation include actor/time/message and relevant context. Prior verified actor/time/fingerprint are preserved in invalidation events. The screen renders readable messages and correction comments instead of raw audit rows.

The complete created → approved → receiver-paid → confirmed → accountability-accepted story is not yet unified. Existing Finance audit, payment and surrender review records remain available, and the new panel does not invent events missing from those records.

## 20. Search/reporting

Added creator-register filters for awaiting verification, verified and verification-returned requests. The register scopes ordinary users to created/assigned requests. Existing reference/purpose/category/date/source search remains available.

New detail totals use all active Payments. Receiver outstanding payment reports, employee-received totals from linked allocations, per-transfer confirmation queues, receiver accountability queues, unused value and closure reporting remain incomplete. Existing Finance projections still have legacy singular-payment fields alongside the new truthful `controls` projection; replacing all consumers remains software work.

## 21. Frontend

Added a searchable controlled independent-verifier selector to the shared authenticated/public creation form. Added verification review/correction controls on the parent detail, with backend action eligibility, server error messages and retained comments on failure. Added receiver/line cards and independent receipt/accountability labels. Added verification-state filtering to the existing register.

The form automatically submits through the existing create/update flow. Draft save, receiver-type selectors, group/instalment processing, per-transfer confirmation/accountability and cancellation/closure actions remain unavailable.

## 22. Mobile

Receiver cards use a one-column layout on narrow screens and expand to two columns. Totals use a compact two/four-column grid. Review actions wrap and comments remain full-width. No wide receiver table was introduced. Browser/device interaction has not been visually exercised; mobile behavior is based on the responsive implementation and component tests.

## 23. Tests

Durable command output is stored in `75r-evidence/`.

| Check | Result |
| --- | --- |
| Focused backend verification/project allocation | Passed: 78 tests / 292 assertions, including verification, all declared parent fingerprint fields, receiver grouping/voids, create/update schema/type compatibility, CostCollector re-approval and petty cash permissions. |
| Existing permission registry invariants | Passed: 9 tests / 1,103 assertions. |
| Frontend verification and existing requisition screen | Passed: 8 tests in 2 files. |
| Final Vite build | Passed. Existing bundle-size/Browserslist warnings remain. |
| TypeScript | Failed: 273 diagnostics elsewhere in the project; none in the Report 75R changed files. Unrelated source files were not modified. |
| PHP syntax | Passed for all changed PHP application, migration, route and test files. |
| Backend/frontend `git diff --check` | Passed. |
| Broader Finance/payment/CostCollector/project-cost/permissions regression run | Initial run exercised 422 tests / 4,120 assertions: 400 passed, 5 errors and 17 failures from unverified legacy fixtures/precondition expectations. Those fixtures were updated through real verification. The affected rerun passed 98 of 99; its sole remaining re-approval fixture was corrected and passed with its complete CostCollector suite in the final 78-test run. All observed failures were retested; the full 422-test filter was not repeated after fixture-only corrections. |

The first two backend invocations overlapped on db_test and collided during migrations; those runs were discarded. A standard serial RefreshDatabase run completed the schema. Its first failure was an obsolete permission-name fixture, corrected to the current constants. Repeated runs reuse that fully migrated schema through a validation-only bootstrap guarded to **exactly `db_test` and APP_ENV=testing**; every test still uses transactional fixture rollback. Tests that invalidate the reusable schema can rebuild it through the standard Laravel refresh mechanism. The bootstrap checks that the new verification migration is installed. It does not modify production configuration or data.

The downstream accounting fixture helper runs the real submission/review service with a distinct active permission-bearing verifier; it does not disable the verification gate or forge a fingerprint. The amended re-approval test updates the line amount, repeats verification and explicitly re-approves before expecting a renewed commitment.

The new backend suite covers assigned/unauthorised verification, explicit Super Admin authority, creator separation, inactive selection, reason-required correction/resubmission, permanent invalidation of parent/line material edits, invalidation history, unverified approval/payment refusal, duplicate review protection, and truthful partial parent payment projection without receipt/accountability fabrication.

The new frontend suite covers independent states and line traceability, denied action visibility, correction reason submission and server refusal without false success. Existing requisition screen tests were also run.

Not yet covered because the software is absent: concurrent receiver overpayment, allocation-bound idempotency, child numbering, grouped/instalment transfer execution, per-transfer confirmation, receiver accountability acceptance, multi-source refunds, unused balance cancellation and parent closure. These are required tests for the remaining implementation.

## 24. Files changed

Paths below are this report's changes, excluding pre-existing user work.

Backend:

- `app/Constants/Permissions.php` — added verification to the existing registry/labels, preserving prior edits
- `app/Constants/RolePermissions.php` — excluded verification from automatic Super Admin grants, preserving prior edits
- `database/migrations/2026_10_06_000003_add_requisition_verification.php`
- `app/Modules/Finance/PettyCash/Models/PettyCashRequisition.php`
- `app/Modules/Finance/PettyCash/Models/PettyCashRequisitionItem.php`
- `app/Modules/Finance/PettyCash/Services/RequisitionVerificationService.php`
- `app/Modules/Finance/PettyCash/Services/RequisitionControlProjection.php`
- `app/Modules/Finance/PettyCash/Services/PettyCashService.php`
- `app/Modules/Finance/PettyCash/Support/PettyCashActions.php`
- `app/Modules/Finance/PettyCash/Controllers/PettyCashRequisitionController.php`
- `app/Modules/Finance/PettyCash/Controllers/PettyCashWorkspaceController.php`
- `routes/api.php` — one verification route, preserving pre-existing edits
- `tests/Feature/PettyCash/RequisitionVerificationControlTest.php`
- `tests/Support/VerifiedFinancialRequisitionFixture.php`
- `tests/Feature/CostCollector/PettyCashCommitmentTest.php`
- `tests/Feature/Finance/PettyCashAdvancePostingTest.php`
- `tests/Feature/Finance/PettyCashSurrenderTest.php`
- `tests/Feature/Finance/PettyCashWorkspaceTest.php`
- `tests/Feature/Finance/Wave3PettyCashControlsTest.php`
- `tests/Feature/PettyCash/ExpenditureExceptionTest.php`
- `tests/Feature/Procurement/SupplierPaymentGateTest.php`
- `tests/Feature/PettyCash/RequisitionTypeManagementTest.php`
- This report and `75r-evidence/`

Frontend:

- `src/modules/finance/petty-cash/types/api.ts`
- `src/modules/finance/petty-cash/components/RequisitionVerificationPanel.vue`
- `src/modules/finance/petty-cash/views/requisitions/RequisitionForm.vue`
- `src/modules/finance/petty-cash/views/requisitions/RequisitionShow.vue`
- `src/modules/finance/petty-cash/views/requisitions/RequisitionIndex.vue`
- `src/modules/finance/petty-cash/requisitionVerification.spec.ts`

## 25. Decisions still required from WNG

Assign verification authority to specific roles/users; confirm whether any controlled self-verification exception should exist (current default forbids it); confirm eligible external-recipient records; confirm confirmation/accountability requirements by requisition type; confirm unused-balance authority and closure conditions; decide how historical unverified unpaid requisitions enter the new workflow; review document-sequence initialization and reference presentation before rollout.

No monetary threshold, approval exception, accounting policy or production backfill was invented. These policy decisions do not excuse the software gaps listed next.

## 26. Remaining software gaps

1. Durable receiver identity/type and line allocation validation for Employee, Supplier and approved external recipients.
2. Payment-to-requisition-line allocation bridge with one authoritative parent FK throughout.
3. Grouped receiver transfers and instalments using the existing settlement engine, safe child references and locking/idempotency/overpayment controls.
4. Per-transfer receipt confirmation and issue/evidence lifecycle.
5. Receiver/allocation links to existing surrender submission, Finance review, acceptance and reversal, including multi-source returns.
6. Per-Payment advance posting failure/retry and complete multi-payment accounting integration.
7. Controlled unused-balance cancellation and type-specific parent closure.
8. Separate create/approve/review/pay/confirm/accountability permissions where legacy abilities still combine duties.
9. Complete parent audit story and receiver/reporting queues; replace legacy singular-payment projections in all consumers.
10. Draft/preparation action and complete Finance/mobile processing UI.
11. Anonymous public requester identity sufficient to enforce creator/verifier independence.
12. The required integration/concurrency/GL/project-cost tests for these missing features, and a wider fixture audit beyond the selected requisition/accounting regression suites. The observed legacy fixture failures in this report were corrected.

## 27. Verdict

FINANCIAL REQUISITION MULTI-RECEIVER CONTROL PARTIAL —
SPECIFIC SOFTWARE GAPS REMAIN
