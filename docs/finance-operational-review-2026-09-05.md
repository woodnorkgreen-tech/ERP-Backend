# Finance operational review — 5 September 2026

## Operating conclusion

The local Finance application and API are running. The setup/readiness endpoint passes against the existing local database, including petty-cash reconciliation. This review improves the operational subledger; it does not certify WNG's statutory accounts or production deployment.

Open the local setup screen at https://erp-frontend.ddev.site:5173/finance/setup and sign in with a Finance-authorized account.

## First principles and business controls

| Question | Required invariant | Implementation and evidence |
| --- | --- | --- |
| What money do we actually hold? | The displayed petty-cash balance equals ledger credits less debits. | Added a readiness reconciliation computed in one SQL statement, avoiding inconsistent reads during a concurrent payment. Existing cash tests cover top-ups, disbursements, allocation, voids and insufficient-funds rollback. |
| What did a project consume? | Budget, commitment, goods received and actual consumption are different facts. | Readiness now expects journals only for accrued and actual costs. Budget and approved purchase commitments do not falsely block operation. Material-cycle regression tests cover inventory/WIP settlement and prevention of duplicate project charges. |
| Can corrections rewrite history? | A reversal is an additional opposite posting, dated when corrected. | Account summaries, exports and month-end arithmetic now include reversed originals alongside their compensating entries. New tests prove same-month net zero and unchanged historical-month totals after a later reversal. |
| Can a configured account actually accept money? | Every resolved journal account must be active and postable. | Cost posting validates every leg, including explicit payment-source and tax mappings. Readiness checks inactive/missing accounts and explicitly requires inventory and input-VAT control accounts. |
| Does payroll reconcile? | Actual journal legs must agree with gross pay, deductions and net payment. | Added validation before any payroll journal is written. Inconsistent payslips and inactive bank accounts are rejected transactionally; tests verify no partial payment journal survives. |
| When must Finance remit WHT? | A monthly reconciliation period is not a payment deadline. | Removed the VAT-style monthly WHT deadline. API, UI and close checklist show the five-working-day remittance rule without inventing an exact date from the cost-incurred date. |
| Can management trust the scope of a report? | Included and excluded workflows must be described accurately. | Coverage now acknowledges payroll explicitly posted from HR. Other revenue, opening-balance and bank accounting remain outside this operational ledger. |
| When are purchased goods available? | Dock acceptance is distinct from Stores confirmation. | Updated the procurement lifecycle fixture for supplier-per-item orders and the separate Stores-confirmation step. |

## Corrections delivered

- Preserved original and reversing journals in accounting reports and exports.
- Corrected false-positive readiness failures for commitments and false-negative checks for inactive account mappings.
- Added petty-cash balance reconciliation to the setup screen's existing integrity panel.
- Prevented cost and payroll postings to unusable accounts; prevented payroll headers from disguising inconsistent journal legs.
- Corrected WHT deadline guidance and retained monthly grouping only as a reconciliation view.
- Fixed Finance TypeScript errors: optional receivables summaries/counts, a moved guide-component import, and readiness labels incompatible with the configured JavaScript target.
- Corrected stale procurement integration fixtures to use the current API and receiving controls.

## Tax source and limits

KRA's current [withholding-tax guidance](https://www.kra.go.ke/individual//filing-paying/types-of-taxes/individual-withholding-tax) states that withheld tax must be remitted within five working days after deduction. Checked 5 September 2026. Some older KRA FAQ pages still show a monthly date; the specific current withholding-tax guidance was used.

The monthly WHT report is based on verified cost records and does not establish each actual deduction date, public-holiday calendar, remittance, or certificate. Its `due_date` is now null and `remittance_rule` explains the applicable timing. VAT retains its separate monthly due-date setting. This change does not alter historical tax amounts or submit returns.

## Local operational evidence

- Backend DDEV web/database and frontend DDEV/Vite are running.
- Frontend finance setup route returns HTTP 200; its proxied finance API returns HTTP 401 when unauthenticated, as expected.
- All local database migrations were applied at review time.
- September 2026 accounting period is open.
- Readiness: 77 active postable accounts; all configuration checks pass. No petty-cash balance mismatch, missing cost/voucher journals, missing posting periods, unbalanced/mismatched journal lines, or failed stores postings detected by the endpoint.
- August close checklist passes in dry-run mode. No accounting period was closed.
- Existing business records were inspected read-only. No historical journals were rewritten, no policy approvals fabricated, and no external deployment or Git push performed.

## Verification

The broad Finance/CostCollector/PettyCash regression run passed **307 tests / 3,405 assertions**. Frontend Finance tests passed **6 tests**; the production bundle builds successfully.

The full frontend type checker still reports **259 errors outside Finance**. No remaining errors were reported under `src/modules/finance`. This means the Finance corrections type-check within the application run, but the repository-wide type-check command is not green. Build-size warnings and PHP dependency deprecation notices also remain.

Final targeted backend validation passed **130 tests / 624 assertions**, covering the Finance suite, payroll integrity, project workflow contracts, receivables summaries and quote approval integrity. This overlaps the 307-test run; the counts are not additive. The final rerun reused the isolated `db_test` schema already created by the preceding full migration run, with transactional test isolation and the repository's `_test` database-name guard retained.

The procurement lifecycle test now proves supplier-per-item PO creation, dock receipt without premature stock availability, and Stores confirmation against an active library material. New Finance regression tests prove reversal netting, historical-period stability, commitment treatment, cash reconciliation and account usability. Payroll regressions prove inconsistent totals and inactive-bank payments are rejected without partial journals.

## Deployment and accounting boundaries

After refreshing remote references, the backend remains on `feat/cost-collector` with no ahead/behind divergence; the frontend remains on `master`, two commits ahead of `origin/master`. Both working trees were clean before this work and now contain the review changes.

Changes are reviewable in the two working trees. The backend and frontend must be released together because the WHT API now returns a nullable due date plus remittance guidance. These changes need no new database migration.

The readiness endpoint checks the invariants named above; it does not reconcile physical cash, bank statements, supplier statements, tax filings or the statutory accounting package. Authenticated browser journeys and production environment checks have not been performed. A passing local check must not be presented as proof that every production transaction or historical tax return is correct.
