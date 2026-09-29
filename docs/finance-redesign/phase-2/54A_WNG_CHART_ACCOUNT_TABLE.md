# 54A — WNG Chart of Accounts: Account Table (appendix to Report 54)

**Generated:** 2026-09-28 from the completed rehearsal chart (`wng_target_rehearsal`, after `finance:complete-chart --profile=wng`).
**Machine-readable copy:** `database/finance/wng-chart-accounts.csv`.
**Accounts:** 152 — 120 existing WNG, 3 ERP-added, 29 new.

"Type" shows the account_type column. The 120 existing WNG accounts carry no account_type; classifying them for P&L sectioning is an open accountant decision (Report 54 §16), so it is shown as not set rather than inferred.

| Code | Account Name | Type | Parent | Existing/New | Semantic Function | Status |
|---|---|---|---|---|---|---|
| AP-001 | Accounts Payable (A/P) | liability (account_type not set) | — | Existing WNG | accounts_payable; payment source AP | Mapped (existing, reused) |
| AR-001 | Accounts Receivable (A/R) | asset (account_type not set) | — | Existing WNG | accounts_receivable | Mapped (existing, reused) |
| AR-002 | Accounts Receivable (A/R) - EUR | asset (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| STD-001 | Short Term Debtors | asset (account_type not set) | — | Existing WNG | staff_advances | Mapped (existing, reused) |
| CASH-001 | Cash Account | asset (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| EQB-001 | Equity Bank | asset (account_type not set) | — | Existing WNG | bank_default; payment source BANK-MAIN | Mapped (existing, reused) |
| FMB-001 | Family Bank | asset (account_type not set) | — | Existing WNG | payment source BANK-FAMILY | Mapped (existing, reused) |
| FK-001 | Faulu Kenya | asset (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| KCB-001 | KCB Bank | asset (account_type not set) | — | Existing WNG | payment source BANK-KCB | Mapped (existing, reused) |
| NCBA-001 | NCBA | asset (account_type not set) | — | Existing WNG | payment source BANK-ALT | Mapped (existing, reused) |
| NIC-001 | NIC Dollar Account | asset (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PETTY-001 | Petty cash | asset (account_type not set) | — | Existing WNG | petty_cash_float; payment source PC-MAIN | Mapped (existing, reused) |
| SBM-001 | SBM | asset (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| STB-001 | Stanbic Bank | asset (account_type not set) | — | Existing WNG | payment source BANK-STANBIC | Mapped (existing, reused) |
| COS-001 | Change in inventory - COS | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-002 | Cost of Sales | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-003 | Cost of Sales:Branding | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-004 | Cost of Sales:Courier - Projects | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-005 | Cost of Sales:Fabrication | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-006 | Cost of Sales:Field Facilitation | expense (account_type not set) | — | Existing WNG | cos_project_facilitation; wip_project_facilitation (if expense_on_capture) | Mapped (existing, reused) |
| COS-007 | Cost of Sales:Jomat hardware | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-008 | Cost of Sales:Materials | expense (account_type not set) | — | Existing WNG | cos_direct_materials; wip_direct_materials (if expense_on_capture) | Mapped (existing, reused) |
| COS-009 | Cost of Sales:Motor bike and motor vehicle fuel | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-010 | Cost of Sales:Paints & Hardware | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-011 | Cost of Sales:Parking Fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-012 | Cost of Sales:Plotting & Cutting | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-013 | Cost of Sales:Printing | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-014 | Cost of Sales:Team Meals | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-015 | Cost of Sales:Teams accomodation | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-016 | Cost of Sales:Transport & Delivery | expense (account_type not set) | — | Existing WNG | cos_transport_logistics; wip_transport_logistics (if expense_on_capture) | Mapped (existing, reused) |
| COS-017 | Discounts given - COS | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| INV-001 | Inventory Shrinkage | expense (account_type not set) | — | Existing WNG | inventory_adjustments | Mapped (existing, reused) |
| COS-018 | Other - COS | expense (account_type not set) | — | Existing WNG | cos_rework_warranty; wip_rework_warranty (if expense_on_capture) | Mapped (existing, reused) |
| COS-019 | Overhead - COS | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| EXP-001 | Pasting Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| COS-020 | Subcontractors - COS | expense (account_type not set) | — | Existing WNG | cos_subcontractors; wip_subcontractors (if expense_on_capture) | Mapped (existing, reused) |
| DIV-001 | Dividend disbursed | equity (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| EQE-001 | Equity in earnings of subsidiaries | equity (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OBE-001 | Opening Balance Equity | equity (account_type not set) | — | Existing WNG | opening_balance_equity | Mapped (existing, reused) |
| OCI-001 | Other comprehensive income | equity (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| RE-001 | Retained Earnings | equity (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| SC-001 | Share capital | equity (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| ADM-001 | Administration expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| ADM-002 | Administration expenses:Courier & Postage | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| ADM-003 | Administration expenses:Depreciation Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| AMO-001 | Amortisation expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| ATM-001 | Atm charges | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| BD-001 | Bad Debts | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| DEL-001 | Delivery/Trolley expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| EXD-001 | EXCISE DUTY | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| FIN-001 | Finance cost | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| FIN-002 | Finance cost:Bad debts | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| FIN-003 | Finance cost:Bank charges | expense (account_type not set) | — | Existing WNG | bank_charges | Mapped (existing, reused) |
| FIN-004 | Finance cost:Interest expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| FIN-005 | Finance cost:Mpesa charges | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| ITX-001 | Income tax expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| INS-001 | Insurance - Disability | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| INS-002 | Insurance - General | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| INS-003 | Insurance - Liability | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| LPF-001 | Legal and professional fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| LDO-001 | Loss on discontinued operations, net of tax | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| MC-001 | Management compensation | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| MAE-001 | Meals and entertainment | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| MED-001 | Medical expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| MBMV-001 | Motor bike and motor vehicle car wash | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| MBMV-002 | Motor bike and motor vehicles Repair | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-001 | Operating Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-002 | Operating Expenses:Advertising/Promotional | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-003 | Operating Expenses:Audit & Accountancy Fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-004 | Operating Expenses:Commissions and fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-005 | Operating Expenses:Consultancy Fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-006 | Operating Expenses:Consumables | expense (account_type not set) | — | Existing WNG | catalogue 6400 Small tools & workshop consumables; catalogue 6600 PPE & workshop safety | Mapped (existing, reused) |
| OPE-007 | Operating Expenses:Corporate social Responsibility | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-008 | Operating Expenses:Director Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-009 | Operating Expenses:Director Expenses:Courier - Director's Errands | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-010 | Operating Expenses:Dues and subacriptions | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-011 | Operating Expenses:Dues and subscriptions | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-012 | Operating Expenses:Electricity & Water | expense (account_type not set) | — | Existing WNG | catalogue 6100 Workshop electricity | Mapped (existing, reused) |
| OPE-013 | Operating Expenses:Equipment Rental | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-014 | Operating Expenses:Garbage Collections | expense (account_type not set) | — | Existing WNG | catalogue 6700 Cleaning & waste disposal | Mapped (existing, reused) |
| OPE-015 | Operating Expenses:Generator fuel, Repair and Maintenance | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-016 | Operating Expenses:Insurance premium | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-017 | Operating Expenses:Licenses and permits | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-018 | Operating Expenses:Office Casual/Stipend | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-019 | Operating Expenses:Office expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-020 | Operating Expenses:Packaging and Delivery | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-021 | Operating Expenses:Professional Fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-022 | Operating Expenses:Rent & lease Payments | expense (account_type not set) | — | Existing WNG | catalogue 7100 Office rent | Mapped (existing, reused) |
| OPE-023 | Operating Expenses:Repairs and Maintenance | expense (account_type not set) | — | Existing WNG | catalogue 6200 Machinery repairs & maintenance | Mapped (existing, reused) |
| OPE-024 | Operating Expenses:Samples | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-025 | Operating Expenses:Security And Fees | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-026 | Operating Expenses:Set Up Casuals | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-027 | Operating Expenses:Shipping & Delivery Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-028 | Operating Expenses:Site Visist | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OPE-029 | Operating Expenses:Staff Welfare | expense (account_type not set) | — | Existing WNG | catalogue 7600 Staff welfare | Mapped (existing, reused) |
| OPE-030 | Operating Expenses:Stationery & Printing | expense (account_type not set) | — | Existing WNG | catalogue 7150 Office supplies & stationery | Mapped (existing, reused) |
| OPE-031 | Operating Expenses:Telephone & Internet | expense (account_type not set) | — | Existing WNG | catalogue 7200 Administration airtime & internet | Mapped (existing, reused) |
| OPE-032 | Operating Expenses:Transport & Delivery | expense (account_type not set) | — | Existing WNG | catalogue 7400 Office transport | Mapped (existing, reused) |
| OGAE-001 | Other general and administrative expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OSE-001 | Other selling expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OTEA-001 | Other Types of Expenses-Advertising Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| OTE-001 | Overtime expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-001 | Payroll Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-002 | Personnel Expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-003 | Personnel Expenses:Housing levy | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-004 | Personnel Expenses:Nita Levy | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-005 | Personnel Expenses:NSSF Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| PE-006 | Personnel Expenses:Salaries | expense (account_type not set) | — | Existing WNG | salaries_expense | Mapped (existing, reused) |
| PE-007 | Personnel Expenses:Wages-Direct Labour | expense (account_type not set) | — | Existing WNG | cos_direct_labour; wip_direct_labour (if expense_on_capture) | Mapped (existing, reused) |
| PS-001 | Printing Supplies | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| RI-001 | Return Inwards | revenue (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| RE-002 | Rider's Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| SUP-001 | Supplies | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| TSE-001 | Travel expenses - selling expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| UCBPE-001 | Unapplied Cash Bill Payment Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| UE-001 | Uncategorised Expense | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| UTIL-001 | Utilities | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| VP-001 | Vat Penalty | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| WE-001 | Wage expenses | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| WNGA-001 | WNG Give aways | expense (account_type not set) | — | Existing WNG | — | Retained — not used by a posting function |
| 2160 | Net Payroll Payable | balance_sheet | — | ERP-added (migration) | net_payroll_payable | REMAIN — canonical posting account |
| 7550 | Salaries & Wages | opex | — | ERP-added (migration) | — | DUPLICATE — not mapped; deprecation recommended |
| 7150 | Office Supplies & Stationery | opex | — | ERP-added (migration) | — | DUPLICATE — not mapped; deprecation recommended |
| SAL-001 | Sales | revenue | — | New (Report 54) | — | Created — header (non-postable) |
| SAL-002 | Sales:Project Revenue | revenue | SAL-001 | New (Report 54) | project_revenue | Created |
| VAT-001 | Output VAT Payable | balance_sheet | — | New (Report 54) | output_vat | Created |
| VAT-002 | Input VAT Recoverable | balance_sheet | — | New (Report 54) | input_vat | Created |
| WHT-001 | Withholding Tax Payable | balance_sheet | — | New (Report 54) | wht_payable | Created |
| PL-001 | Payroll liabilities | balance_sheet | — | New (Report 54) | — | Created — header (non-postable) |
| PL-002 | Payroll liabilities:PAYE | balance_sheet | PL-001 | New (Report 54) | paye_payable | Created |
| PL-003 | Payroll liabilities:Statutory & Other Deductions | balance_sheet | PL-001 | New (Report 54) | statutory_payable | Created |
| AE-001 | Accrued Expenses | balance_sheet | — | New (Report 54) | accrued_expenses | Created |
| CD-001 | Client Deposits | balance_sheet | — | New (Report 54) | client_deposits | Created |
| IA-001 | Inventory Asset | balance_sheet | — | New (Report 54) | inventory | Created |
| WIP-001 | Work in Progress | balance_sheet | — | New (Report 54) | — | Created — header (non-postable) |
| WIP-002 | Work in Progress:Materials | balance_sheet | WIP-001 | New (Report 54) | wip_direct_materials (capitalise) | Created |
| WIP-003 | Work in Progress:Direct Labour | balance_sheet | WIP-001 | New (Report 54) | wip_direct_labour (capitalise) | Created |
| WIP-004 | Work in Progress:Subcontractors | balance_sheet | WIP-001 | New (Report 54) | wip_subcontractors (capitalise) | Created |
| WIP-005 | Work in Progress:Transport & Delivery | balance_sheet | WIP-001 | New (Report 54) | wip_transport_logistics (capitalise) | Created |
| WIP-006 | Work in Progress:Equipment Hire & Site | balance_sheet | WIP-001 | New (Report 54) | wip_equipment_site (capitalise) | Created |
| WIP-007 | Work in Progress:Project Utilities | balance_sheet | WIP-001 | New (Report 54) | wip_project_utilities (capitalise) | Created |
| WIP-008 | Work in Progress:Field Facilitation | balance_sheet | WIP-001 | New (Report 54) | wip_project_facilitation (capitalise) | Created |
| WIP-009 | Work in Progress:Venue & Permits | balance_sheet | WIP-001 | New (Report 54) | wip_venue_statutory (capitalise) | Created |
| WIP-010 | Work in Progress:Rework & Warranty | balance_sheet | WIP-001 | New (Report 54) | wip_rework_warranty (capitalise) | Created |
| COS-021 | Cost of Sales:Equipment Hire & Site | direct_cost | COS-002 | New (Report 54) | cos_equipment_site; wip_equipment_site (if expense_on_capture) | Created |
| COS-022 | Cost of Sales:Project Utilities | direct_cost | COS-002 | New (Report 54) | cos_project_utilities; wip_project_utilities (if expense_on_capture) | Created |
| COS-023 | Cost of Sales:Venue & Permits | direct_cost | COS-002 | New (Report 54) | cos_venue_statutory; wip_venue_statutory (if expense_on_capture) | Created |
| PRE-001 | Prepayments & Deposits | balance_sheet | — | New (Report 54) | — | Created — header (non-postable) |
| PRE-002 | Prepayments & Deposits:Supplier Advances | balance_sheet | PRE-001 | New (Report 54) | catalogue 1310 Supplier advances | Created |
| PRE-003 | Prepayments & Deposits:Refundable Deposits | balance_sheet | PRE-001 | New (Report 54) | catalogue 1320 Refundable deposits | Created |
| PRE-004 | Prepayments & Deposits:Prepaid Expenses | balance_sheet | PRE-001 | New (Report 54) | catalogue 1340 Prepaid expenses | Created |
| OI-001 | Office Improvement | balance_sheet | — | New (Report 54) | catalogue 1600 Leasehold improvements (capex) | Created |
