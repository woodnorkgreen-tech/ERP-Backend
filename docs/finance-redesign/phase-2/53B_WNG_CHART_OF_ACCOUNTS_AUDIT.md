# 53B — WNG Chart of Accounts Audit (appendix to Report 53)

**Source:** the rehearsal copy (`wng_source_rehearsal.chart_of_accounts`) of snapshot `erpsystem-20260928-0918`.

**Balances:** none are shown or read.

**Column meanings:**
- *Implied parent*: from QuickBooks `Parent:Child` naming. `parent_id` itself is empty for every account.
- *History uses*: rows in the source petty-cash register (DATA-1-excluded history) coded to this account name. The only ERP-side usage evidence; the real ledger history is in QuickBooks.
- *Proposed mapping*: from `database/finance/wng-coa-mapping-proposal.json`.

| ID | Code | Name | Category | account_type | Implied parent | Postable | Active | Origin | History uses | Proposed mapping |
|---|---|---|---|---|---|---|---|---|---|---|
| 121 | `2160` | Net Payroll Payable | liability | balance_sheet | — | yes | yes | inserted by target migration | — | — |
| 123 | `7150` | Office Supplies & Stationery | expense | opex | — | yes | yes | inserted by target migration | — | — |
| 122 | `7550` | Salaries & Wages | expense | opex | — | yes | yes | inserted by target migration | — | — |
| 43 | `ADM-001` | Administration expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 44 | `ADM-002` | Administration expenses:Courier & Postage | expense | — | ADM-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 45 | `ADM-003` | Administration expenses:Depreciation Expense | expense | — | ADM-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 46 | `AMO-001` | Amortisation expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 1 | `AP-001` | Accounts Payable (A/P) | liability | — | — | yes | yes | WNG (QuickBooks import) | — | accounts_payable; paying account AP |
| 2 | `AR-001` | Accounts Receivable (A/R) | asset | — | — | yes | yes | WNG (QuickBooks import) | — | accounts_receivable |
| 3 | `AR-002` | Accounts Receivable (A/R) - EUR | asset | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 47 | `ATM-001` | Atm charges | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 48 | `BD-001` | Bad Debts | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 5 | `CASH-001` | Cash Account | asset | — | — | yes | yes | WNG (QuickBooks import) | 1 | — |
| 15 | `COS-001` | Change in inventory - COS | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 16 | `COS-002` | Cost of Sales | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 17 | `COS-003` | Cost of Sales:Branding | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 12 | — |
| 18 | `COS-004` | Cost of Sales:Courier - Projects | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 19 | `COS-005` | Cost of Sales:Fabrication | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 20 | `COS-006` | Cost of Sales:Field Facilitation | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 1 | wip_project_facilitation (candidate); cos_project_facilitation (candidate) |
| 21 | `COS-007` | Cost of Sales:Jomat hardware | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 22 | `COS-008` | Cost of Sales:Materials | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 270 | wip_direct_materials (candidate); cos_direct_materials (candidate) |
| 23 | `COS-009` | Cost of Sales:Motor bike and motor vehicle fuel | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 50 | — |
| 24 | `COS-010` | Cost of Sales:Paints & Hardware | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 25 | `COS-011` | Cost of Sales:Parking Fees | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 11 | — |
| 26 | `COS-012` | Cost of Sales:Plotting & Cutting | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 27 | `COS-013` | Cost of Sales:Printing | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 22 | — |
| 28 | `COS-014` | Cost of Sales:Team Meals | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 287 | — |
| 29 | `COS-015` | Cost of Sales:Teams accomodation | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 1 | — |
| 30 | `COS-016` | Cost of Sales:Transport & Delivery | expense | — | COS-002 | yes | yes | WNG (QuickBooks import) | 467 | wip_transport_logistics (candidate); cos_transport_logistics (candidate) |
| 31 | `COS-017` | Discounts given - COS | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 33 | `COS-018` | Other - COS | expense | — | — | yes | yes | WNG (QuickBooks import) | — | wip_rework_warranty (candidate); cos_rework_warranty (candidate) |
| 34 | `COS-019` | Overhead - COS | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 36 | `COS-020` | Subcontractors - COS | expense | — | — | yes | yes | WNG (QuickBooks import) | — | wip_subcontractors (candidate); cos_subcontractors (candidate) |
| 49 | `DEL-001` | Delivery/Trolley expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 37 | `DIV-001` | Dividend disbursed | equity | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 6 | `EQB-001` | Equity Bank | asset | — | — | yes | yes | WNG (QuickBooks import) | — | bank_default; paying account BANK-MAIN |
| 38 | `EQE-001` | Equity in earnings of subsidiaries | equity | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 50 | `EXD-001` | EXCISE DUTY | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 35 | `EXP-001` | Pasting Expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 51 | `FIN-001` | Finance cost | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 52 | `FIN-002` | Finance cost:Bad debts | expense | — | FIN-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 53 | `FIN-003` | Finance cost:Bank charges | expense | — | FIN-001 | yes | yes | WNG (QuickBooks import) | — | bank_charges (candidate) |
| 54 | `FIN-004` | Finance cost:Interest expense | expense | — | FIN-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 55 | `FIN-005` | Finance cost:Mpesa charges | expense | — | FIN-001 | yes | yes | WNG (QuickBooks import) | 70 | — |
| 8 | `FK-001` | Faulu Kenya | asset | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 7 | `FMB-001` | Family Bank | asset | — | — | yes | yes | WNG (QuickBooks import) | — | paying account BANK-FAMILY |
| 57 | `INS-001` | Insurance - Disability | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 58 | `INS-002` | Insurance - General | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 59 | `INS-003` | Insurance - Liability | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 32 | `INV-001` | Inventory Shrinkage | expense | — | — | yes | yes | WNG (QuickBooks import) | — | inventory_adjustments (candidate) |
| 56 | `ITX-001` | Income tax expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 9 | `KCB-001` | KCB Bank | asset | — | — | yes | yes | WNG (QuickBooks import) | — | paying account BANK-KCB |
| 61 | `LDO-001` | Loss on discontinued operations, net of tax | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 60 | `LPF-001` | Legal and professional fees | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 63 | `MAE-001` | Meals and entertainment | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 65 | `MBMV-001` | Motor bike and motor vehicle car wash | expense | — | — | yes | yes | WNG (QuickBooks import) | 34 | — |
| 66 | `MBMV-002` | Motor bike and motor vehicles Repair | expense | — | — | yes | yes | WNG (QuickBooks import) | 32 | — |
| 62 | `MC-001` | Management compensation | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 64 | `MED-001` | Medical expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 10 | `NCBA-001` | NCBA | asset | — | — | yes | yes | WNG (QuickBooks import) | — | paying account BANK-ALT |
| 11 | `NIC-001` | NIC Dollar Account | asset | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 39 | `OBE-001` | Opening Balance Equity | equity | — | — | yes | yes | WNG (QuickBooks import) | — | opening_balance_equity |
| 40 | `OCI-001` | Other comprehensive income | equity | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 99 | `OGAE-001` | Other general and administrative expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | 22 | — |
| 67 | `OPE-001` | Operating Expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 68 | `OPE-002` | Operating Expenses:Advertising/Promotional | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 69 | `OPE-003` | Operating Expenses:Audit & Accountancy Fees | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 70 | `OPE-004` | Operating Expenses:Commissions and fees | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 71 | `OPE-005` | Operating Expenses:Consultancy Fees | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 72 | `OPE-006` | Operating Expenses:Consumables | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | catalogue 6400 (candidate) |
| 73 | `OPE-007` | Operating Expenses:Corporate social Responsibility | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 8 | — |
| 74 | `OPE-008` | Operating Expenses:Director Expenses | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 75 | `OPE-009` | Operating Expenses:Director Expenses:Courier - Director's Errands | expense | — | OPE-008 | yes | yes | WNG (QuickBooks import) | — | — |
| 76 | `OPE-010` | Operating Expenses:Dues and subacriptions | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 77 | `OPE-011` | Operating Expenses:Dues and subscriptions | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 78 | `OPE-012` | Operating Expenses:Electricity & Water | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 17 | catalogue 6100 (candidate) |
| 79 | `OPE-013` | Operating Expenses:Equipment Rental | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 80 | `OPE-014` | Operating Expenses:Garbage Collections | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | catalogue 6700 (candidate) |
| 81 | `OPE-015` | Operating Expenses:Generator fuel, Repair and Maintenance | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 1 | — |
| 82 | `OPE-016` | Operating Expenses:Insurance premium | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 83 | `OPE-017` | Operating Expenses:Licenses and permits | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 1 | — |
| 84 | `OPE-018` | Operating Expenses:Office Casual/Stipend | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 85 | `OPE-019` | Operating Expenses:Office expenses | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 53 | — |
| 86 | `OPE-020` | Operating Expenses:Packaging and Delivery | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 87 | `OPE-021` | Operating Expenses:Professional Fees | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 88 | `OPE-022` | Operating Expenses:Rent & lease Payments | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | catalogue 7100 (candidate) |
| 89 | `OPE-023` | Operating Expenses:Repairs and Maintenance | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 33 | catalogue 6200 (candidate) |
| 90 | `OPE-024` | Operating Expenses:Samples | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 11 | — |
| 91 | `OPE-025` | Operating Expenses:Security And Fees | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 92 | `OPE-026` | Operating Expenses:Set Up Casuals | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 93 | `OPE-027` | Operating Expenses:Shipping & Delivery Expense | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 94 | `OPE-028` | Operating Expenses:Site Visist | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | — |
| 95 | `OPE-029` | Operating Expenses:Staff Welfare | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | catalogue 7600 |
| 96 | `OPE-030` | Operating Expenses:Stationery & Printing | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | — | catalogue 7150 (candidate) |
| 97 | `OPE-031` | Operating Expenses:Telephone & Internet | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 6 | catalogue 7200 |
| 98 | `OPE-032` | Operating Expenses:Transport & Delivery | expense | — | OPE-001 | yes | yes | WNG (QuickBooks import) | 19 | catalogue 7400 (candidate) |
| 100 | `OSE-001` | Other selling expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 102 | `OTE-001` | Overtime expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 101 | `OTEA-001` | Other Types of Expenses-Advertising Expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 103 | `PE-001` | Payroll Expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 104 | `PE-002` | Personnel Expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 105 | `PE-003` | Personnel Expenses:Housing levy | expense | — | PE-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 106 | `PE-004` | Personnel Expenses:Nita Levy | expense | — | PE-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 107 | `PE-005` | Personnel Expenses:NSSF Expense | expense | — | PE-002 | yes | yes | WNG (QuickBooks import) | — | — |
| 108 | `PE-006` | Personnel Expenses:Salaries | expense | — | PE-002 | yes | yes | WNG (QuickBooks import) | — | salaries_expense (candidate) |
| 109 | `PE-007` | Personnel Expenses:Wages-Direct Labour | expense | — | PE-002 | yes | yes | WNG (QuickBooks import) | 26 | wip_direct_labour (candidate); cos_direct_labour (candidate) |
| 12 | `PETTY-001` | Petty cash | asset | — | — | yes | yes | WNG (QuickBooks import) | — | petty_cash_float; paying account PC-MAIN |
| 110 | `PS-001` | Printing Supplies | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 41 | `RE-001` | Retained Earnings | equity | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 112 | `RE-002` | Rider's Expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 111 | `RI-001` | Return Inwards | revenue | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 13 | `SBM-001` | SBM | asset | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 42 | `SC-001` | Share capital | equity | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 14 | `STB-001` | Stanbic Bank | asset | — | — | yes | yes | WNG (QuickBooks import) | — | paying account BANK-STANBIC |
| 4 | `STD-001` | Short Term Debtors | asset | — | — | yes | yes | WNG (QuickBooks import) | — | staff_advances (candidate) |
| 113 | `SUP-001` | Supplies | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 114 | `TSE-001` | Travel expenses - selling expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 115 | `UCBPE-001` | Unapplied Cash Bill Payment Expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 116 | `UE-001` | Uncategorised Expense | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 117 | `UTIL-001` | Utilities | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 118 | `VP-001` | Vat Penalty | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 119 | `WE-001` | Wage expenses | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
| 120 | `WNGA-001` | WNG Give aways | expense | — | — | yes | yes | WNG (QuickBooks import) | — | — |
