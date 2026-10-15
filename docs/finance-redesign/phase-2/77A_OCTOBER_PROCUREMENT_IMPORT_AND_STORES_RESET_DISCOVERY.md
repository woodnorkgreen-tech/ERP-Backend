# 77A — October Procurement Import & Stores Reset: Discovery and Design

**Date:** 2026-10-13
**Stage:** A — discovery and design only.
**Changed:** nothing. No code, no schema, no data, no stock, no posting, no deploy. The only files written are this report and `77a-evidence/proc-row-register.csv`.
**Source file:** `/home/cosmas/Desktop/proc.ods`, SHA-256 `dc12d30f82cdd8d93bb3774eb236d09d381b84f74409384a45ba486791b38b5f`, 28,079 bytes.
**Status:** design ready for review. **Stage B is blocked on six decisions and four pieces of data that only WNG can supply** (§12).

---

## 1. Executive summary

The spreadsheet's totals are right. Almost nothing else needed to post it is in the file.

| Question | Answer |
|---|---|
| Rows | 81 purchase rows, one sheet, no formulas |
| Totals | Gross 860,846.75 · VAT_2% 12,081.98 · NetPayable 848,764.76 — **all three match the reported figures exactly** |
| Purchase dates | **None.** No date column, no date-typed cell |
| Confirmed as October purchases | **None can be confirmed.** 48 of the 66 coded rows carry August or September project codes |
| What `VAT_2%` is | **Withholding VAT**, on the evidence — not input VAT. Needs Finance to confirm (§5.3) |
| Evidence of payment | **None.** The PaymentMethod column holds supplier paybill, till and phone numbers, not transaction references |
| Rows clean enough to import as they stand | **0.** Every row lacks a date and payment evidence. 10 rows lack nothing else |
| Project codes checkable here | **0.** The local copy of live data ends on 26 July 2026; the sheet's codes are August–October. There is no route to production from this workstation |
| ERP support for withholding VAT | **None exists** |
| Existing import pattern to build on | Yes — the petty cash offline batch |
| Existing opening-inventory feature | Yes — covers bulk stock; needs an upload and three controls added |

**Recommended design in one paragraph.** Record each historical purchase as a supplier **bill with no purchase order** (the ERP's existing "direct bill"), marked `PRE_GO_LIVE_PROCUREMENT` and tied to its import row. That path already posts the expense, the payable and the project's actual cost, creates no GRN and touches no stock. Payments are recorded only where WNG supplies evidence. Stock on hand is established separately by the existing opening-inventory count. The two are reconciled so that what is still on the shelf is not also charged to a project.

**The decision that shapes everything else** is the ledger cutover date: whether the ERP's general ledger carries October as live transactions, or opens after it from a trial balance. The same rows must be posted completely differently in the two cases (§6.1). I recommend neither until Finance says which is true.

---

## 2. Existing architecture

### 2.1 What exists

| Area | What the ERP has | Relevance |
|---|---|---|
| **Supplier bill without a PO** ("direct bill") | `bills.purchase_order_id` NULL, own expense code, optional project. Verification posts Dr expense-code account, Dr input VAT, Cr WHT payable, Cr Accounts Payable, and records the project's actual cost once. No GRN, no stock. | **The vehicle for historical purchases** |
| Supplier payment | One `Payment` per paying account through `PaymentSettlementService`; settles AP only | Used only with evidence |
| Bill reversal / credit note | Added in Report 76A-3 | The undo for a wrongly imported row |
| Cost capture (`/api/costs`) | Out-of-pocket, company-paid, unpaid-invoice | Alternative vehicle; not recommended (§6.2) |
| Project cost ledger | `cost_lines` via `CostCollectorService`; refuses a financially closed project | Unchanged |
| Expense codes | Carry the debit account and the job rule (required / optional / not allowed) | Row classification |
| Tax | `vat_treatments` (recoverable, eTIMS flag); `wht_categories` = income withholding tax at 3% and 5% only | No withholding VAT |
| **Opening inventory** | `StockCount` in `opening_inventory` mode: seeded from active library items, counted by one person, approved by another, re-baselines an existing bulk balance by ledger adjustment, posts Dr Inventory / Cr opening stock | **The vehicle for the Stores reset** |
| Stock received without a purchase | Receive Stock with reason *opening balance*; needs `stores.review` | Boards and rolls |
| **Batch import pattern** | `PettyCashOfflineBatch`: file SHA-256 (a repeat upload is refused), rows with row number, payload, errors, status, `posted_type` / `posted_id`; batch states uploaded → invalid / ready → posting → posted | **The pattern to follow** |
| Other imports | Materials workbook, employees, attendance, assets, print history | Not reusable for money |
| Readiness tooling | `finance:readiness`, `finance:posting-health`, `stores:readiness`, `migration:target-readiness`, `inventory:audit-master-data` | Go-live screen reads these |

### 2.2 What does not exist

- No procurement import of any kind.
- No withholding VAT.
- No historical / pre-go-live transaction marker on any document.
- No upload of a physical count; counts are typed line by line.
- No opening-balance facility for supplier balances.
- No go-live readiness screen (the checks exist as console commands).

### 2.3 State of the code this must build on

Reports 76A Part 2 and 76A-3 are **uncommitted in both repositories and deployed nowhere**. Their three migrations have not run on any development or production database. 76A-3's verdict is PARTIAL. This design depends on parts of that work (§10) and must not be built until it is committed and reviewed.

---

## 3. Spreadsheet analysis

Read directly from the file's XML, so values are the stored values, not displayed ones.

### 3.1 Structure

| | |
|---|---|
| Sheets | 1: `Sheet1` |
| Rows | 82: header on row 1, data on rows 2–82 |
| Headers | ProjectCode, Client, ItemDescription, Qty, GrossTotal, VAT_2%, NetPayable, SupplierName, PaymentMethod, ETR_Status |
| Formulas | None. Every figure is a typed number |
| Numeric columns | All 81 rows numeric in Qty, GrossTotal, VAT_2%, NetPayable. None missing, none negative |
| Date cells | None |
| File metadata | Created 2026-10-13 19:29, saved 19:31, one editing cycle of 94 seconds |

**The file is a copy.** It was created and saved within two minutes today. It is a paste of something else, not the working record. The original may hold dates, formulas or columns this copy lost. I recommend asking for it.

### 3.2 Totals

| | Recalculated | Reported | Difference |
|---|---:|---:|---:|
| GrossTotal | 860,846.75 | 860,846.75 | 0.00 |
| VAT_2% | 12,081.98 | 12,081.98 | 0.00 |
| NetPayable | 848,764.76 | 848,764.76 | 0.00 |

Gross − VAT_2% − Net = **0.01**. It comes from row 14 alone (18,500 − 318.96 = 18,181.04; the sheet says 18,181.03). Every other row is exact.

### 3.3 Project codes

37 distinct values in 81 rows.

| Kind | Rows | Gross | Note |
|---|---:|---:|---|
| Job number (`WNG-mm-yyyy-nnn`) | 34 | 288,884.00 | |
| Enquiry number (`ENQ-mm-yyyy-nnn`) | 32 | 252,552.75 | An enquiry, not a confirmed job |
| Internal (`ADM…`) | 12 | 245,850.00 | Not ERP codes (§3.3.1) |
| Free text | 3 | 73,560.00 | "Kiserian Solar", "Flex cable", "Padded Seats" |

Month in the code: **September 40 rows, October 18, August 8, none 15.** A project code's month is when the enquiry or job was opened. It says nothing about when something was bought, and it is not used as a date anywhere in this design.

Problems that need a person:

- **Codes carry free-text suffixes** ("WNG-10-2026-003 Loreal"). The code is extractable; the suffix is kept as a note.
- **The same number under both prefixes:** `ENQ-09-2026-081 Merchandise` (row 24) and `WNG-09-2026-081 Merchandise` (row 73), same client. In the ERP an enquiry and its job have *different* numbers (for example ENQ-07-2026-122 became WNG-07-2026-074), so one of these is probably mistyped.
- **One code, three clients:** `ENQ-09-2026-139` has Pristine Flooring (7 rows), Eland Swift (1) and MKTE (1).
- **One code, two clients:** `WNG-09-2026-088` is Discmen Entertainment on 9 rows and "Leakey Storage" on 1.

#### 3.3.1 The ADM codes

`ADM001 Tyres`, `ADM003 Drills`, `ADM009 Artist`, `ADM009/09/26/07 Printing`, `ADM013 Stores`, `ADM017 ICT`. The ERP's cost centres are coded ADMIN, HR, FIN, IT and so on. None of the ADM codes exists in the ERP. WNG must supply what each means.

### 3.4 Suppliers

44 spellings, 43 after ignoring case. The local data copy holds 6 suppliers; one plausibly matches ("RAI MDF" ↔ "Raimdf").

- About a dozen are first names only: Paul, Sharon, Kennedy, Dismas, Lawrence, Nyiva, Lindi, Chester, Hanah, Leakey, Amaya.
- **No row has a KRA PIN, address, contact or invoice number.**
- Row 64 names two suppliers in one row: "Pemar+Desa".
- Largest: Shivneri 139,600 · Promo Hub 95,600 · Paints Hardware 88,560 (16 rows) · Tristate 66,000 · Raimdf 50,600.

### 3.5 `VAT_2%`

| Basis | Rows | Gross |
|---|---:|---:|
| Exactly 2% × (gross ÷ 1.16) | 51 | — |
| Zero | 28 | 160,512.75 |
| Does not fit | 2 | rows 11 and 64 |

- Row 11: 90.00 on 4,500 is 2% of gross, not of gross ÷ 1.16.
- Row 64: 124.14 on 7,500 fits a base of 7,200, as if a 300 item was left out.

Interpretation is in §5.3.

### 3.6 ETR status

| Value | Rows | Gross |
|---|---:|---:|
| YES | 64 | 748,956.75 |
| NO ETR | 6 | 20,000.00 |
| YES DUP | 5 | 27,330.00 |
| YES DUP x2 | 1 | 18,500.00 |
| DEPOSIT | 2 | 16,800.00 |
| PENDING | 1 | 4,960.00 |
| "NO LOCAL - Paid 294 Topup 6" | 1 | 300.00 |
| "-" | 1 | 24,000.00 |

- "YES DUP" is not defined in the file. It may mean a duplicate copy of the receipt. It needs an answer before any of those 6 rows claims VAT.
- **18 rows say ETR YES and carry no VAT_2%.** Either those suppliers are not VAT-registered, or withholding was not applied. The file cannot say which.

### 3.7 Payment column

| What the cell contains | Rows | Gross |
|---|---:|---:|
| Supplier's paybill, till or bank account | 37 | 450,534.75 |
| Supplier's name only ("Chester", "Paints hardware") | 21 | 83,520.00 |
| Supplier's phone number | 8 | 28,892.00 |
| "Cheque", no number | 7 | 214,100.00 |
| A note of part payment, top-up or refund | 4 | 63,020.00 |
| Bare number | 2 | 2,280.00 |
| Nothing ("-") | 2 | 18,500.00 |

**No row contains a transaction reference, a payment date, an amount paid or a paying account.** A paybill number says where money could be sent. It is not evidence that it was. All 81 rows are therefore *payment unverified*.

Five rows carry a note that hints at actual payment (four in this column, one in the ETR column):

| Row | Note | Reading |
|---|---|---|
| 2 | "Paid 294 Topup 6" | 300 paid in total — the 2% was withheld, then given back |
| 47 | "Deposit 4200" of 6,000 | 1,800 outstanding |
| 48 | "Deposit 6000" of 10,800 | 4,800 outstanding |
| 78 | "Paid 39271 Topup 4913.8" | 44,184.80 paid — equals NetPayable, so the 2% was withheld |
| 18 | "Refund Ibrahim IT" | An employee paid and is owed 1,260 |

The 21 "supplier name only" rows look like purchases on account, which would make them unpaid. That is a guess and is not acted on.

### 3.8 Duplicates and oddities

No two rows match on supplier, description and amount. Three pairs match on supplier and amount:

| Rows | Supplier | Amount | Assessment |
|---|---|---:|---|
| **43 and 81** | Nicmaa Enterprises | 9,800 | Same item ("Padded Eam seats 2pcs + Transport"), different codes ("Padded Seats" internal, "WNG-10-2026-001 Visit Rwanda"). **Likely one purchase entered twice** |
| 20 and 63 | Chester | 1,600 | Different paints, different projects. Probably separate |
| 60 and 69 | Lindi | 1,750 | Thinner, different projects. Probably separate |

Other:

- **Row 24** is a balance: "A3 gift bags Balance (16372.75-9660)". The purchase was 16,372.75; 9,660 was paid earlier and is not in this file.
- **Row 18:** "6pcs @290" is 1,740; the gross is 1,260.
- **22 rows** describe more than one item ("Tack nails + Velvet 2m + Foam sheet") under one quantity and one amount. They cannot be mapped to catalogue materials without splitting.
- **3 rows** include a service in a goods line (transport, hire).

### 3.9 What kind of spend this is

A proposal for review, not a classification:

| Nature | Examples | Destination |
|---|---|---|
| Fabrication materials for a job | Boards, perspex, paint, tube | Project cost |
| Merchandise bought for a client | Gift hampers 95,600, gift bags, trophy, balloons | Project cost; never Stores stock |
| Hire and services | Seats and tables hire, transport | Project cost |
| Printing consumables | Ink and stickers, 205,600 across rows 27–28 | Department expense, or stock if still held |
| Workshop and Stores consumables | Sandpaper, lipping, body filler | Department expense or opening stock |
| Possible assets | Tyres for KCT082T 24,000; solar switchgear 18,800; flex cable 44,960 | Asset or repairs — **Finance decides** |

### 3.10 Row register

`77a-evidence/proc-row-register.csv` holds all 81 rows with source row number, a stable row identity and every flag. The raw payment column is deliberately left out of it: it holds supplier phone and bank account numbers, which do not belong in a repository.

| Flag | Rows |
|---|---:|
| No purchase date | 81 |
| Payment unverified | 81 |
| Code month is not October | 48 |
| Code is an enquiry, not a job | 32 |
| More than one item in the description | 22 |
| ETR "YES" with no VAT_2% | 18 |
| ADM code needs mapping | 12 |
| ETR not a plain "YES" | 17 |
| Possible duplicate | 6 |
| No project code | 3 |

---

## 4. Field mapping

| Source field | ERP field | Transformation | Validation | Exception |
|---|---|---|---|---|
| (file) | `import_batch.file_sha256`, stored original | Hash on upload | Same hash already uploaded → refuse | Open the existing batch |
| (row number) | `import_row.source_row`, `row_identity` | Sheet + row + content hash | Unique per batch | — |
| ProjectCode | `bill.project_enquiry_id`, or `department_id` | Extract `WNG-` / `ENQ-` code; look up job number, then enquiry number; ADM via a WNG-supplied map | Must exist; must not be financially closed | Unknown, closed, ambiguous, or free text → **unresolved allocation**; never guessed |
| Client | Check only | Compare with the matched project's client | Must agree | Mismatch → review |
| ItemDescription | `bill.notes`, cost line description | Kept verbatim | — | Mixed items → split, or post as one unitemised line by decision |
| Qty | Memo on the row | None | > 0 | Not used for stock |
| GrossTotal | `bill.amount` | None | > 0; = Net + withheld | Mismatch → review |
| VAT_2% | `bill.wht_amount` under a **withholding VAT** category | None | Fits 2% × taxable value, or is explained | Rows 11, 64; **whole column blocked until §5.3 is confirmed** |
| NetPayable | Check only: `bill.payableAmount()` | Derived, never stored | Gross − withheld, to the cent | Row 14 (0.01) |
| *(absent)* input VAT | `bill.vat_amount`, `vat_treatment_id` | From the tax invoice, per row | Treatment governs recoverability | **Not in the file.** No VAT is claimed without it |
| *(absent)* purchase date | `bill.bill_date` | Supplied per row | In an open period; not after upload | **Not in the file.** Row cannot post |
| *(absent)* invoice number | `bill.supplier_invoice_number` | Supplied, or `PGL-{batch}-{row}` by approved convention | Duplicate check per supplier | — |
| SupplierName | `bill.supplier_id` | Match by normalised name; create only on approval | KRA PIN where VAT is claimed | Unmatched → review; two suppliers in one row → split |
| *(absent)* expense category | `bill.expense_code_id` | Chosen per row | Active; job rule satisfied | Unclassified → review |
| PaymentMethod | `import_row.payment_instruction` (text) | **Stored as an instruction only** | — | Never creates a payment |
| *(absent)* payment evidence | `Payment` | Date, amount, reference, paying account | Account exists; amount ≤ balance | Missing → bill stays unpaid and flagged *unverified* |
| ETR_Status | `bill.etims_invoice_no`, row flag | "YES" alone is not a number | Number required to claim VAT | DUP, PENDING, NO ETR → no VAT claim |

Nothing is corrected silently. A row that fails a check keeps its original values and its status says why.

---

## 5. Recommended database design

Additive only. No existing table is restructured.

### 5.1 Tables

**`procurement_import_batches`**
`id`, `batch_number`, `import_type` (`PRE_GO_LIVE_PROCUREMENT`), `original_filename`, `stored_path`, `file_sha256` (unique), `sheet_name`, `row_count`, `source_totals` (json), `ledger_treatment` (§6.1; null until Finance decides), `date_convention` (null unless approved), `status`, `uploaded_by/at`, `validated_by/at`, `reviewed_by/at`, `approved_by/at`, `executed_by/at`, `cancelled_by/at`, `cancel_reason`, `result_summary` (json).

**`procurement_import_rows`**
`id`, `batch_id`, `source_sheet`, `source_row`, `row_identity` (unique), `source_values` (json, never updated), `normalised_values` (json), `matched` (json: supplier, project, department, expense code, treatment), `resolution` (json: who resolved what, when), `errors`, `warnings`, `status`, `posting_attempts`, `last_error`, `bill_id`, `journal_entry_id`, `imported_at`.
Unique on (`batch_id`, `source_sheet`, `source_row`).

**`procurement_import_row_allocations`** — one purchase across several projects, or part consumed and part in stock.
`row_id`, `project_enquiry_id` | `department_id` | `to_opening_stock` (bool), `quantity`, `amount`, `expense_code_id`. Amounts must sum to the row.

**`procurement_import_payments`** — evidence, before it becomes a Payment.
`row_id`, `paid_on`, `amount`, `reference`, `payment_source_id`, `evidence_attachment_id`, `status`, `payment_id`. Unique on (`payment_source_id`, `reference`).

**Columns on existing tables**
- `bills.origin` (null = live; `PRE_GO_LIVE_PROCUREMENT`) and `bills.import_row_id` (unique, nullable).
- `stock_counts.upload_sha256`, `stock_counts.upload_path`; `stock_count_items.zero_confirmed`.

### 5.2 States

Batch: Uploaded → Validation Required → Ready for Review → Approved → Importing → Partially Imported | Completed | Failed; Cancelled from any state before Importing.
Row: parsed → invalid | needs resolution → ready → approved → importing → imported | failed; excluded (with a reason).

### 5.3 Idempotency

- A file is uploaded once (hash).
- A row creates at most one bill (`bills.import_row_id` unique). A retry finds the bill and continues.
- The bill's journal number is derived from the bill id, as now; posting twice returns the first entry.
- Payments use the settlement engine's idempotency key, `pgl-import:{row}:{n}`.
- Execution takes a row lock on the batch and uses locking reads for "already imported". Report 76A-3 found that a plain read after a lock sees a stale snapshot on this database; the same rule applies here.

---

## 6. Accounting treatment

### 6.1 The decision that comes first: where the ledger starts

| | **A. Ledger is live for the period** | **B. Ledger opens after it** |
|---|---|---|
| Meaning | The ERP's general ledger starts before these purchases. They are ordinary transactions in it | WNG's books to the cutover date are kept elsewhere and come in as an opening trial balance |
| Each row posts | Dr expense or WIP, Dr input VAT, Cr withholding, Cr AP, dated when bought | **No profit-and-loss entry.** The expense is already inside the opening balances |
| Project cost | Posted with its journal | Recorded on the project with no journal of its own |
| Unpaid bills | AP from the bill | Opening AP: Dr a governed take-on account / Cr AP, by supplier |
| Payments | Real payments from real accounts | None. Bank and cash already reflect them |
| Risk if chosen wrongly | Expense counted twice | Expense missing from the ledger |

The file cannot settle this. It depends on the cutover date and on whether an opening trial balance exists, and both are Finance's. The importer carries the treatment as a batch setting with **no default**, and refuses to approve a batch until it is set. Mixing the two inside one batch is refused.

If the ERP's ledger is to be WNG's book of record from 1 October 2026, **A is cleaner**: real documents, real dates, real period, and normal reports work for October. That is a recommendation conditional on a fact I do not have.

### 6.2 Why a bill and not a cost capture

Cost capture also records a cost with no stock. It is not recommended because it has no supplier document: no invoice number, no duplicate-invoice check, no three-part verification, and a weaker link to supplier balances. A bill gives all of those plus the reversal built in 76A-3.

One rule must be relaxed deliberately: a direct bill refuses the "Direct materials" expense family, because a live materials purchase belongs on a purchase order. Historical purchases are the exception that rule did not foresee. The relaxation applies **only** to bills with `origin = PRE_GO_LIVE_PROCUREMENT`, which only the importer can create.

### 6.3 `VAT_2%` — withholding VAT, to be confirmed

Evidence it is VAT withheld at 2% of the taxable value:

1. 51 rows equal 2% × (gross ÷ 1.16) to the cent. That is 2% of the VAT-exclusive value, the statutory withholding-VAT base.
2. NetPayable is gross less this amount: what the supplier receives.
3. Row 78's two payments sum to NetPayable, not gross.

Evidence against treating it as settled:

4. Row 2 was topped up to the full gross.
5. 18 rows with an ETR have none.
6. Only appointed agents withhold VAT. **Whether WNG is one is not something I can know and I have not assumed it.**

What it is not: input VAT. Input VAT on those 51 rows would be about 94,942.62 (16/116 of gross). That figure is my arithmetic, not a source value, and nothing is posted from it.

**The ERP cannot hold this today.** Its withholding categories are income tax at 3% and 5%. The structure fits — a bill carries one withheld amount, one category and its own liability account, and pays the supplier gross less withheld — so the proposal is a new category, "Withholding VAT 2%", with its own governed liability account. Two limits: a bill cannot carry income withholding and VAT withholding together (no row here needs both), and the withholding-tax schedule must keep the two apart or the KRA returns will be wrong.

Until Finance confirms items 4–6 and approves the account, **no row with a non-zero VAT_2% can be approved**.

### 6.4 Input VAT

Not in the file. Per row, from the tax invoice: the amount and the treatment. Rows with no ETR, "DUP" or "PENDING" claim nothing until resolved. No rate is applied by assumption.

### 6.5 Posting, under treatment A

| Row kind | Entry |
|---|---|
| Project purchase, consumed | Dr the expense code's WIP or cost account / Cr AP (gross − withheld) / Cr withholding VAT payable / Dr input VAT if supported. One project actual |
| Company or department purchase | Dr the expense code's expense account, with the cost centre. No project cost |
| Possible asset | Held for Finance; not posted as an expense by default |
| Part still in stock | That part is **not** project cost. See §7.4 |
| Payment, with evidence | Dr AP / Cr the paying account, through the existing engine |
| Employee paid personally (row 18) | A reimbursement owed to the employee, not a supplier payable |

No GRN, no stock receipt, no Stores issue is created for any row.

---

## 7. Inventory reset design

### 7.1 What is there now

Local copy of live data: 33 stock rows, 23 non-zero, 257 units in total, all bulk; no boards registered; **the last stock movement is dated 5 May 2026.** Five months without a movement supports resetting. It does not prove the balances are wrong, and the target must be inspected before anything is reset.

### 7.2 Use the existing opening count

`StockCount` in opening mode already does most of what is asked:

| Requirement | Today |
|---|---|
| Authorised user | Yes |
| Current against proposed quantity | Yes (`system_quantity`, `counted_quantity`) |
| Opening unit value and total | Yes (`opening_unit_cost`) |
| Review and approval by another person | Yes |
| Stock and ledger together | Yes, one transaction; governed opening-stock account since 76A-3 |
| Existing balance re-baselined, not deleted | Yes, by adjustment movement |
| No GRN | Yes |
| History kept | Yes |
| Reversal | A further count posts the opposite entry |
| Controlled items not given invented identities | Yes: serialised, board and roll materials start at zero |

### 7.3 What must be added

1. **Upload of the physical count**, matched to the count's lines by material code, with unmatched materials and unit mismatches listed. Same batch pattern as §5.
2. **"Not counted" must not become zero.** A line with no counted quantity is left out of the approval. Zero is posted only where `zero_confirmed` is set by a person.
3. **Environment confirmation and export.** Before approval: the target database is named on screen and confirmed, and `inventory:audit-master-data` output is stored with the count.
4. **Boards and rolls** come in through Receive Stock with reason *opening balance*, one identity per physical unit. Offcuts are registered as boards with their own dimensions. This is manual and slow by design: each has an identity nobody should invent in bulk.
5. **One opening count.** A second opening-mode count on a warehouse that already has an approved one is refused; later corrections are cycle counts.

The Materials Library, SKUs, categories, units and suppliers are not touched by any of this.

### 7.4 Stock that was bought in the import and is still on the shelf

The risk: a purchase is charged to a project by the import, and what remains of it is also counted into opening stock. The same cost is then in the job and in Inventory.

Rule: for each imported row, purchased = consumed + remaining.

- **Consumed** → project cost.
- **Remaining** → not project cost. Allocated `to_opening_stock`, debited to the same opening-stock account the count credits.

The count then debits Inventory and credits opening stock for the whole shelf, and the import has debited opening stock for the part that was bought in the period. The purchase lands in Inventory once and in the job once.

This needs, per row, how much is still held. **That is in Stores' manual records, not in this file.** Without it the safe default is that an imported material purchase is fully consumed, and the count is the only source of what is on hand — which is correct only if the count's valuation is not also counted as cost. The Go-Live screen shows the comparison by material.

---

## 8. Screens

Built from the existing Finance Control and Stores design components. No new visual system.

**Procurement Import** (Finance › Setup): upload · batch summary with source totals against parsed totals · row table with filters by flag · resolve panel per row (supplier, project or department, category, date, VAT, split) · payment evidence · reconciliation (source total = imported + excluded + unresolved) · approve · results with links to each bill and entry · CSV export of exceptions.

**Opening Inventory** (Stores › Stock control): upload count · matched and unmatched · current against proposed with value · explicit zero confirmations · approve · posting result.

**Go-Live Readiness** (Finance › Setup): import completion · unresolved allocations · unverified payments · supplier balances · opening stock against ledger Inventory · missing governed accounts · failed postings · migration and queue state from the existing readiness commands.

---

## 9. Required permissions

New, none granted to anyone:

| Permission | Allows |
|---|---|
| `procurement.import.upload` | Upload a file |
| `procurement.import.resolve` | Match, split, enter dates and evidence |
| `procurement.import.review` | Mark a batch ready |
| `procurement.import.approve` | Approve a batch |
| `procurement.import.execute` | Run the import |
| `procurement.import.audit` | View history and originals |
| `stores.opening.upload` | Upload a physical count |
| `stores.opening.approve` | Approve the opening count |
| `stores.opening.execute` | Post it |

Rules: the approver is not the uploader or the last resolver, unless the existing self-approval permission is held. Executing also needs the authority the underlying act needs — `finance.payables.verify` for bills, `finance.petty_cash.create_disbursement` for any payment.

---

## 10. Dependencies on Report 76A-3

| Needed | From | State |
|---|---|---|
| Bill reversal, to undo an imported row | 76A-3 | Uncommitted |
| Governed opening-stock and inventory-adjustment accounts | 76A-3 | Uncommitted; **not approved by Finance** |
| Stock and ledger in one transaction | 76A-3 | Uncommitted |
| Exceptional receipt for boards and rolls | 76A Part 2 | Uncommitted |
| Recorded, retryable cost postings | 76A Part 1 | Committed; migration not run on any target I can see |

76A-3's open items that touch this work:

- **Core control accounts still fall back to the reference chart.** An import would post AP and Input VAT to whatever the reference code resolves to. On a target whose chart identity is unsettled, that is unacceptable for a take-on. This should be closed first.
- **Non-recoverable VAT on non-stock purchases.** Does not affect direct bills, which already put it in the expense.
- The others (project issues not atomic, boards and rolls not revalued) do not affect an import that moves no stock.

---

## 11. Risks

| Risk | Consequence | Control |
|---|---|---|
| Wrong ledger treatment | October expense doubled or missing | No default; batch cannot be approved without it |
| VAT_2% posted as input VAT | VAT over-claimed by 12,081.98 and the liability missed | Column blocked until confirmed |
| Dates invented | Costs in the wrong period | Row cannot post without a date or an approved convention |
| Paybill number read as payment | Bank or M-Pesa reduced for money that may not have moved, or twice | Payment only from evidence; unique reference per account |
| Rows 43 and 81 both imported | 9,800 counted twice | Flagged; one must be excluded with a reason |
| Remaining stock in both job and Inventory | Cost doubled | §7.4 |
| Project guessed | Wrong job profitability | Unresolved state; no fallback project |
| Closed project | Refused by the cost ledger | Shown at validation, not at posting |
| Supplier duplicates | Split balances | Match first; create only on approval |
| Uncounted treated as zero | Real stock written off | Explicit zero only |
| Reset on the wrong database | Loss of real records | Named confirmation and export first |
| Import built on unreviewed code | Defects carried into a take-on | 76A work committed and reviewed first |

---

## 12. Blockers

### 12.1 Decisions only WNG can make

1. **Ledger cutover date and treatment** (§6.1).
2. **Is WNG an appointed VAT withholding agent**, and is `VAT_2%` that withholding? If yes, the liability account (§6.3).
3. **Date convention**, if the real dates cannot be found.
4. **Are these October purchases?** 48 rows carry earlier codes. If some predate the period, they need a different treatment or exclusion.
5. **Enquiry-only codes:** may cost be charged to an enquiry that has no job?
6. **Possible assets** (tyres, solar switchgear, flex cable): asset, repair or expense.

### 12.2 Data only WNG has

7. Purchase date and supplier invoice number per row — ideally the original working file.
8. Payment evidence: date, amount, reference, account.
9. The ADM code list.
10. From Stores' manual records: what was issued to which job, and what remains.

### 12.3 Technical

11. Reports 76A Part 2 and 76A-3 committed, reviewed and migrated on a staging database.
12. Access to a staging copy of the **current** target. The local copy ends in July 2026. Project and supplier matching cannot be tested against it.
13. Finance approves the opening-stock account and, if confirmed, the withholding VAT account.

---

## 13. Proposed implementation sequence (Stage B, not started)

1. Commit and review the 76A work; migrate staging.
2. Close the control-account fallback from 76A-3.
3. Import tables, upload, parse, row register, validation. No posting. Dry-run report against staging.
4. Matching and resolution screens; exception export.
5. Withholding VAT category, if confirmed.
6. Bill creation with origin marker; allocations; execution with idempotency; reversal.
7. Payment evidence and settlement.
8. Opening inventory: upload, explicit zero, environment confirmation.
9. Go-Live Readiness screen.
10. Tests 1–20 from the brief, with exact amounts and stock.
11. Dry-run reconciliation on staging; cutover procedure.
12. Real import and reset only on explicit authorisation.

## 14. Recommended decisions

1. Ask for the original file before anything else. This one is a two-minute copy.
2. Confirm withholding-agent status with Finance first; it changes 53 rows.
3. Settle the ledger cutover date; if the ERP is the book of record from 1 October, use treatment A.
4. Use a supplier bill with no purchase order as the vehicle, marked by origin.
5. Use the existing opening count for the reset; add the upload and the three controls.
6. Treat every payment as unverified until evidence is attached.
7. Do not build Stage B on uncommitted code.
