# 53A — Expense Code → WNG Account Proposal (appendix to Report 53)

**Generated:** 2026-09-28 by `php artisan finance:account-mapping` from `database/finance/wng-coa-mapping-proposal.json`, evaluated against the real WNG chart (rehearsal copy).

**Status:** PROPOSED — for Finance/accountant review. **No expense code is activated by this proposal.** A code is active only once its reference account resolves to a postable WNG account through an *approved* map line (`ExpenseCodeSeeder` activates a code only when its account resolves).

**How to read:**
- *Reference GL*: the catalogue's own account statement.
- *Proposed WNG account*: the proposed or candidate WNG account for that reference code.
- *Classification*: the classification of that mapping (Report 53 §5).
- *CAPTURE_TIME*: the catalogue names an account only in prose, and the account is chosen per transaction.

**Totals (109 codes):** ACCOUNTANT_DECISION 86, SUITABLE_EXISTING 10, CAPTURE_TIME (account named in prose; chosen per transaction) 8, EXACT_MATCH 4, NEW_SUB_ACCOUNT 1.

| Code | Expense type | Accounting class | Job rule | Reference GL | Proposed WNG account | Classification | Active now |
|---|---|---|---|---|---|---|---|
| `DL-ALW-001` | Site allowance and overtime | Direct project cost | required | 1212 Project WIP – Direct Labour | PE-007 | ACCOUNTANT_DECISION | no |
| `DL-CAS-001` | Casual site labour | Direct project cost | required | 1212 Project WIP – Direct Labour | PE-007 | ACCOUNTANT_DECISION | no |
| `DL-CAS-002` | Casual workshop labour | Direct project cost | required | 1212 Project WIP – Direct Labour | PE-007 | ACCOUNTANT_DECISION | no |
| `DM-DC-001` | Carpet and floor covering | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-DC-002` | Artificial grass | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-DC-003` | Décor fabric and draping | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-DC-004` | Artificial greenery and flowers | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-EL-001` | Electrical cable and connectors | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-EL-002` | LED modules and strips | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-FN-001` | Paint and primer | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-FN-002` | Thinner and solvents | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-FN-003` | Filler, putty and body filler | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-FN-004` | Sandpaper and abrasives | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-FN-005` | Brushes, rollers and masking materials | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-GL-001` | Glass | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-GL-002` | Mirrors | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-001` | Mild-steel tubes | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-002` | Steel angle / channel | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-003` | Steel sheet / plate | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-004` | Aluminium sections | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-005` | Stainless-steel material | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-006` | Welding consumables | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-MT-007` | Cutting and grinding consumables | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PL-001` | Acrylic / Perspex | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PL-002` | PVC foam board | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PL-003` | Foam board | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PL-004` | ACP / aluminium composite panel | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-001` | Self-adhesive vinyl | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-002` | Flex / banner material | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-003` | Printable fabric / textile | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-004` | Wallpaper / poster paper | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-005` | Printing ink | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-006` | Lamination film | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-007` | Application tape and transfer film | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-PR-008` | Eyelets, rope and banner finishing | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-001` | MDF boards | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-002` | Plywood boards | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-003` | Chipboard / particle board | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-004` | OSB boards | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-005` | Solid timber | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-006` | Timber battens and framing | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-007` | Laminate / veneer | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-008` | Edge banding | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-009` | Wood adhesive | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `DM-WD-010` | Hardware and fasteners | Direct project cost | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `EQ-GEN-001` | Generator hire | Direct project cost | required | 1215 Project WIP – Equipment & Site | — | ACCOUNTANT_DECISION | no |
| `EQ-HIR-001` | Equipment hire | Direct project cost | required | 1215 Project WIP – Equipment & Site | — | ACCOUNTANT_DECISION | no |
| `EQ-SAF-001` | Site safety and PPE | Direct project cost | required | 1215 Project WIP – Equipment & Site | — | ACCOUNTANT_DECISION | no |
| `EQ-SCF-001` | Scaffolding and access hire | Direct project cost | required | 1215 Project WIP – Equipment & Site | — | ACCOUNTANT_DECISION | no |
| `EQ-TOL-001` | Site tools and consumables | Direct project cost | required | 1215 Project WIP – Equipment & Site | — | ACCOUNTANT_DECISION | no |
| `NE-001` | Petty-cash float top-up | Balance sheet / Not an expense | not_allowed | 1030 Petty Cash Float | PETTY-001 | EXACT_MATCH | no |
| `NE-002` | Transfer between company accounts | Balance sheet / Not an expense | not_allowed | Receiving cash/bank account | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-003` | Staff advance / imprest issued | Balance sheet / Not an expense | optional | 1300 Staff Advances | STD-001 | NEW_SUB_ACCOUNT | no |
| `NE-004` | Staff advance retired with receipts | Balance sheet / Not an expense | conditional | Relevant expense, WIP, inventory or asset account | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-005` | Unused advance returned | Balance sheet / Not an expense | conditional | 1030 Petty Cash Float or bank | PETTY-001 | EXACT_MATCH | no |
| `NE-006` | Supplier deposit / advance | Balance sheet / Not an expense | optional | 1310 Supplier Advances | — | ACCOUNTANT_DECISION | no |
| `NE-007` | Refundable deposit paid | Balance sheet / Not an expense | optional | 1320 Refundable Deposits | — | ACCOUNTANT_DECISION | no |
| `NE-008` | Material bought for store | Inventory asset | conditional | 1200 Raw-material Inventory | — | ACCOUNTANT_DECISION | no |
| `NE-009` | Material issued from store to project | Internal stock movement | required | 1211 Project WIP – Direct Materials | COS-008 | ACCOUNTANT_DECISION | no |
| `NE-010` | Material returned from project to store | Internal stock movement | required | 1200 Raw-material Inventory | — | ACCOUNTANT_DECISION | no |
| `NE-011` | Prepaid rent | Prepayment asset | not_allowed | 1340 Prepaid Expenses | — | ACCOUNTANT_DECISION | no |
| `NE-012` | Prepaid insurance | Prepayment asset | not_allowed | 1340 Prepaid Expenses | — | ACCOUNTANT_DECISION | no |
| `NE-013` | Input VAT recoverable | Tax asset | conditional | 1330 Input VAT Recoverable | — | ACCOUNTANT_DECISION | no |
| `NE-014` | Withholding tax remitted | Liability settlement | conditional | 2120 Withholding Tax Payable | — | ACCOUNTANT_DECISION | no |
| `NE-015` | VAT or corporation tax paid | Liability / tax settlement | not_allowed | Relevant tax payable or income-tax receivable | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-016` | Loan principal repayment | Liability settlement | not_allowed | 2300 Loans Payable | — | ACCOUNTANT_DECISION | no |
| `NE-017` | Owner drawings / dividend | Equity movement | not_allowed | Equity / Dividends Payable | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-018` | Customer advance / deposit received | Customer liability | required | Bank / Cash (credit is 2200 Client Deposits) | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-019` | Client refund / credit note | Revenue reversal or liability settlement | required | 2200 Client Deposits or relevant revenue/returns account | — | ACCOUNTANT_DECISION | no |
| `NE-020` | Asset purchase pending review | Capital expenditure | conditional | Relevant 1400 PPE account | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-021` | Reusable hire asset built or bought | Capital expenditure / hire asset | conditional | Relevant 1500 Hire Asset account | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `NE-022` | Leasehold improvement | Capital expenditure | conditional | 1600 Leasehold Improvements | — | ACCOUNTANT_DECISION | no |
| `NE-023` | Project WIP transfer to cost of sales | Internal accounting transfer | required | Relevant 5100–5800 Cost of Sales account | — | CAPTURE_TIME (account named in prose; chosen per transaction) | no |
| `OE-CLN-001` | Cleaning supplies and services | Production overhead | not_allowed | 6700 Cleaning & Waste Disposal | OPE-014 | SUITABLE_EXISTING | no |
| `OE-COM-001` | Airtime and internet | Operating expense | not_allowed | 7200 Administration Airtime & Internet | OPE-031 | EXACT_MATCH | no |
| `OE-FIN-001` | Bank and mobile-money transaction charges | Operating expense | optional | 7800 Bank & Mobile-money Charges | FIN-003 | SUITABLE_EXISTING | no |
| `OE-MNT-001` | Machinery repair and servicing | Production overhead | not_allowed | 6200 Machinery Repairs & Maintenance | OPE-023 | SUITABLE_EXISTING | no |
| `OE-OFF-001` | Office supplies and stationery | Operating expense | not_allowed | 7150 Office Supplies & Stationery | OPE-030 | SUITABLE_EXISTING | yes |
| `OE-OFF-002` | Printer and IT consumables | Operating expense | not_allowed | 7150 Office Supplies & Stationery | OPE-030 | SUITABLE_EXISTING | yes |
| `OE-PPE-001` | Protective equipment and workshop safety | Production overhead | not_allowed | 6600 PPE & Workshop Safety | — | ACCOUNTANT_DECISION | no |
| `OE-TRP-001` | Office and admin transport | Operating expense | not_allowed | 7400 Office Transport | OPE-032 | SUITABLE_EXISTING | no |
| `OE-UTL-001` | Workshop electricity | Production overhead | not_allowed | 6100 Workshop Electricity | OPE-012 | SUITABLE_EXISTING | no |
| `OE-UTL-002` | Office rent and electricity | Operating expense | not_allowed | 7100 Office Rent & Electricity | OPE-022 | SUITABLE_EXISTING | no |
| `OE-WEL-001` | Staff welfare | Operating expense | not_allowed | 7600 Staff Welfare | OPE-029 | EXACT_MATCH | no |
| `OE-WSC-001` | Workshop consumables and small tools | Production overhead | not_allowed | 6400 Small Tools & Workshop Consumables | OPE-006 | SUITABLE_EXISTING | no |
| `OE-WST-001` | Waste collection and disposal | Production overhead | not_allowed | 6700 Cleaning & Waste Disposal | OPE-014 | SUITABLE_EXISTING | no |
| `PF-ACC-001` | Crew accommodation | Direct project cost | required | 1217 Project WIP – Project Facilitation | COS-006 | ACCOUNTANT_DECISION | no |
| `PF-MEA-001` | Site meals and refreshments | Direct project cost | required | 1217 Project WIP – Project Facilitation | COS-006 | ACCOUNTANT_DECISION | no |
| `PF-PDM-001` | Out-of-town per diem | Direct project cost | required | 1217 Project WIP – Project Facilitation | COS-006 | ACCOUNTANT_DECISION | no |
| `PU-FUE-001` | Generator fuel | Direct project cost | required | 1216 Project WIP – Project Utilities | — | ACCOUNTANT_DECISION | no |
| `PU-PWR-001` | Site power | Direct project cost | required | 1216 Project WIP – Project Utilities | — | ACCOUNTANT_DECISION | no |
| `PU-WST-001` | Site waste removal | Direct project cost | required | 1216 Project WIP – Project Utilities | — | ACCOUNTANT_DECISION | no |
| `PU-WTR-001` | Site water and sanitation | Direct project cost | required | 1216 Project WIP – Project Utilities | — | ACCOUNTANT_DECISION | no |
| `RW-LAB-001` | Rework labour | Direct project cost | required | 1219 Project WIP – Rework & Warranty | COS-018 | ACCOUNTANT_DECISION | no |
| `RW-MAT-001` | Rework materials | Direct project cost | required | 1219 Project WIP – Rework & Warranty | COS-018 | ACCOUNTANT_DECISION | no |
| `RW-SNG-001` | Snag rectification on site | Direct project cost | required | 1219 Project WIP – Rework & Warranty | COS-018 | ACCOUNTANT_DECISION | no |
| `SC-FAB-001` | Fabrication subcontract | Direct project cost | required | 1213 Project WIP – Subcontractors | COS-020 | ACCOUNTANT_DECISION | no |
| `SC-INS-001` | Specialist installation | Direct project cost | required | 1213 Project WIP – Subcontractors | COS-020 | ACCOUNTANT_DECISION | no |
| `SC-PRN-001` | Print subcontract | Direct project cost | required | 1213 Project WIP – Subcontractors | COS-020 | ACCOUNTANT_DECISION | no |
| `SC-PRO-001` | Professional and consultancy fees | Direct project cost | required | 1213 Project WIP – Subcontractors | COS-020 | ACCOUNTANT_DECISION | no |
| `TL-CRW-001` | Crew transport | Direct project cost | required | 1214 Project WIP – Transport & Logistics | COS-016 | ACCOUNTANT_DECISION | no |
| `TL-CUR-001` | Courier and dispatch | Direct project cost | required | 1214 Project WIP – Transport & Logistics | COS-016 | ACCOUNTANT_DECISION | no |
| `TL-FUE-001` | Fuel for project transport | Direct project cost | required | 1214 Project WIP – Transport & Logistics | COS-016 | ACCOUNTANT_DECISION | no |
| `TL-HIR-001` | Truck and vehicle hire | Direct project cost | required | 1214 Project WIP – Transport & Logistics | COS-016 | ACCOUNTANT_DECISION | no |
| `TL-HND-001` | Loading and handling | Direct project cost | required | 1214 Project WIP – Transport & Logistics | COS-016 | ACCOUNTANT_DECISION | no |
| `VS-INS-001` | Event insurance | Direct project cost | required | 1218 Project WIP – Venue & Statutory | — | ACCOUNTANT_DECISION | no |
| `VS-PRM-001` | County permits and licences | Direct project cost | required | 1218 Project WIP – Venue & Statutory | — | ACCOUNTANT_DECISION | no |
| `VS-SEC-001` | Site security | Direct project cost | required | 1218 Project WIP – Venue & Statutory | — | ACCOUNTANT_DECISION | no |
| `VS-VEN-001` | Venue and space hire | Direct project cost | required | 1218 Project WIP – Venue & Statutory | — | ACCOUNTANT_DECISION | no |
