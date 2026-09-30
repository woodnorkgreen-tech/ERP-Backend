# 15 — STAB-7: Historical Impact Diagnostic (READ ONLY)

**This entire document describes a READ-ONLY diagnostic.** Every query below is a `SELECT`. None
of them writes, deletes, updates, reverses, or reopens anything. Running them against production
is safe in the sense that they cannot change data — but as with any diagnostic query against a
live system, Finance/Engineering should run them against a recent replica or backup first if one
is available, purely to avoid adding read load to production during business hours, not because of
any write risk.

**No historical remediation is performed here or implied by running this.** The output is input to
a separate, Finance/accountant-led decision about whether and how to correct the past — see
`14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md` and the Decision Register.

---

## What this diagnostic looks for

Per `14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md`, the defect has two independent, additive
symptoms on any requisition-linked petty-cash disbursement, present in the database if the
disbursement/surrender happened **before** this fix was deployed:

- **Symptom 1 — an extraneous immediate posting at disbursement time.** A `CostLine` with
  `source_type = 'App\\Modules\\Finance\\Models\\Payment'` whose `source_id` is a
  requisition-linked disbursement, and which has a non-null `journal_entry_id` (job-costed case);
  or a `journal_entries` row with `entry_no LIKE 'JE-PAY-%'` whose `source_id` is a
  requisition-linked disbursement (overhead case, via `postDirectPayment()`).
- **Symptom 2 — an extraneous independent posting at surrender time.** A surrender item's
  `CostLine` (`source_type = 'App\\Modules\\Finance\\PettyCash\\Models\\PettyCashSurrenderItem'`)
  whose `journal_entry_id` does **not** equal the requisition's own surrender clearing entry
  (`journal_entries.entry_no = 'JE-PCS-<requisition id, zero-padded to 7>'`). This is a clean,
  self-discriminating signal: after the fix, every surrender item's CostLine is stamped with
  exactly that entry's id, so any mismatch is, by construction, a pre-fix row — no date cutoff is
  needed to tell old from new.

Both symptoms resolve their expense/WIP account through the actual `expense_codes` mapping used by
that row (`default_debit_account_id`), never a hard-coded chart code — different WNG expense
categories legitimately map to different WIP/expense accounts, and the live chart may not match
any documentation.

---

## Query 1 — Requisitions with an extraneous disbursement-time posting (Symptom 1)

```sql
-- READ ONLY. Job-costed case: postFor() posted a CostLine (and its own journal entry)
-- directly against the disbursement, on top of PettyCashAdvancePoster's advance entry.
SELECT
    r.id                              AS requisition_id,
    r.requisition_number,
    r.enquiry_id,
    pe.job_number,
    p.id                              AS disbursement_id,
    p.amount                          AS disbursement_amount,
    p.payment_no,
    p.date_disbursed,
    cl.id                             AS extraneous_cost_line_id,
    cl.expense_code_id,
    ec.code                           AS expense_code,
    ec.default_debit_account_id       AS wip_expense_account_id,
    coa.code                          AS wip_expense_account_code,
    coa.name                          AS wip_expense_account_name,
    cl.journal_entry_id               AS extraneous_journal_entry_id,
    je.entry_no                       AS extraneous_entry_no,
    je.accounting_period_id,
    je.posting_date,
    cl.net_amount                     AS extraneous_debit_amount,
    r.status                          AS requisition_status
FROM petty_cash_requisitions r
JOIN payments p               ON p.requisition_id = r.id
LEFT JOIN project_enquiries pe ON pe.id = r.enquiry_id
JOIN cost_lines cl             ON cl.source_type = 'App\\Modules\\Finance\\Models\\Payment'
                               AND cl.source_id = p.id
                               AND cl.journal_entry_id IS NOT NULL
LEFT JOIN expense_codes ec     ON ec.id = cl.expense_code_id
LEFT JOIN chart_of_accounts coa ON coa.id = ec.default_debit_account_id
LEFT JOIN journal_entries je   ON je.id = cl.journal_entry_id
ORDER BY r.id;
```

```sql
-- READ ONLY. Overhead case: postFor() called postDirectPayment() directly against the
-- disbursement (no CostLine involved at all for this posting), on top of the advance entry.
SELECT
    r.id                              AS requisition_id,
    r.requisition_number,
    r.enquiry_id,
    p.id                              AS disbursement_id,
    p.amount                          AS disbursement_amount,
    p.payment_no,
    p.expense_code_id,
    ec.code                           AS expense_code,
    ec.default_debit_account_id       AS expense_account_id,
    coa.code                          AS expense_account_code,
    coa.name                          AS expense_account_name,
    je.id                             AS extraneous_journal_entry_id,
    je.entry_no                       AS extraneous_entry_no,
    je.accounting_period_id,
    je.posting_date,
    je.total_debit                    AS extraneous_debit_amount,
    r.status                          AS requisition_status
FROM petty_cash_requisitions r
JOIN payments p                ON p.requisition_id = r.id
JOIN journal_entries je         ON je.source_type = 'App\\Modules\\Finance\\Models\\Payment'
                                AND je.source_id = p.id
                                AND je.entry_no = CONCAT('JE-PAY-', LPAD(p.id, 7, '0'))
LEFT JOIN expense_codes ec      ON ec.id = p.expense_code_id
LEFT JOIN chart_of_accounts coa ON coa.id = ec.default_debit_account_id
ORDER BY r.id;
```

## Query 2 — Requisitions with an extraneous surrender-item posting (Symptom 2)

```sql
-- READ ONLY. Each surrender item's own CostLine should point at the requisition's single
-- JE-PCS-* clearing entry (post-fix behaviour). Any surrender item whose CostLine points at
-- a DIFFERENT entry (or the requisition has no JE-PCS entry at all despite having a posted
-- surrender-item CostLine) is a pre-fix row carrying an extraneous, independent posting.
SELECT
    r.id                              AS requisition_id,
    r.requisition_number,
    r.enquiry_id,
    si.id                             AS surrender_item_id,
    si.amount                         AS surrender_item_amount,
    si.net_amount                     AS surrender_item_net_amount,
    cl.id                             AS surrender_cost_line_id,
    cl.expense_code_id,
    ec.code                           AS expense_code,
    ec.default_debit_account_id       AS wip_expense_account_id,
    coa.code                          AS wip_expense_account_code,
    coa.name                          AS wip_expense_account_name,
    cl.journal_entry_id               AS surrender_item_journal_entry_id,
    je_item.entry_no                  AS surrender_item_entry_no,
    je_item.posting_date              AS surrender_item_posting_date,
    je_item.accounting_period_id      AS surrender_item_accounting_period_id,
    r.surrender_journal_entry_id      AS requisition_clearing_entry_id,
    je_req.entry_no                   AS requisition_clearing_entry_no,
    r.surrender_reconciled_at,
    r.status                          AS requisition_status
FROM petty_cash_requisitions r
JOIN petty_cash_surrender_items si ON si.requisition_id = r.id
JOIN cost_lines cl                 ON cl.id = si.cost_line_id
                                    AND cl.journal_entry_id IS NOT NULL
LEFT JOIN expense_codes ec         ON ec.id = cl.expense_code_id
LEFT JOIN chart_of_accounts coa    ON coa.id = ec.default_debit_account_id
LEFT JOIN journal_entries je_item  ON je_item.id = cl.journal_entry_id
LEFT JOIN journal_entries je_req   ON je_req.id = r.surrender_journal_entry_id
WHERE r.surrender_journal_entry_id IS NULL
   OR cl.journal_entry_id <> r.surrender_journal_entry_id
ORDER BY r.id;
```

## Query 3 — Summary: total suspected excess by expense/WIP account

```sql
-- READ ONLY. Combines both symptoms into one per-account total of the SUSPECTED EXCESS
-- (not the total expense — just the extraneous portion), to quantify exposure without
-- requiring anyone to read every row individually first.
SELECT
    coa.code    AS account_code,
    coa.name    AS account_name,
    COUNT(DISTINCT combined.requisition_id) AS affected_requisitions,
    SUM(combined.excess_amount)             AS total_suspected_excess
FROM (
    -- Symptom 1, job-costed
    SELECT r.id AS requisition_id, ec.default_debit_account_id AS account_id, cl.net_amount AS excess_amount
    FROM petty_cash_requisitions r
    JOIN payments p ON p.requisition_id = r.id
    JOIN cost_lines cl ON cl.source_type = 'App\\Modules\\Finance\\Models\\Payment'
                       AND cl.source_id = p.id AND cl.journal_entry_id IS NOT NULL
    LEFT JOIN expense_codes ec ON ec.id = cl.expense_code_id

    UNION ALL

    -- Symptom 1, overhead
    SELECT r.id AS requisition_id, ec.default_debit_account_id AS account_id, je.total_debit AS excess_amount
    FROM petty_cash_requisitions r
    JOIN payments p ON p.requisition_id = r.id
    JOIN journal_entries je ON je.source_type = 'App\\Modules\\Finance\\Models\\Payment'
                            AND je.source_id = p.id
                            AND je.entry_no = CONCAT('JE-PAY-', LPAD(p.id, 7, '0'))
    LEFT JOIN expense_codes ec ON ec.id = p.expense_code_id

    UNION ALL

    -- Symptom 2
    SELECT r.id AS requisition_id, ec.default_debit_account_id AS account_id, cl.net_amount AS excess_amount
    FROM petty_cash_requisitions r
    JOIN petty_cash_surrender_items si ON si.requisition_id = r.id
    JOIN cost_lines cl ON cl.id = si.cost_line_id AND cl.journal_entry_id IS NOT NULL
    LEFT JOIN expense_codes ec ON ec.id = cl.expense_code_id
    WHERE r.surrender_journal_entry_id IS NULL OR cl.journal_entry_id <> r.surrender_journal_entry_id
) AS combined
LEFT JOIN chart_of_accounts coa ON coa.id = combined.account_id
GROUP BY coa.code, coa.name
ORDER BY total_suspected_excess DESC;
```

---

## Interpretation guide

- **A requisition can appear in Query 1, Query 2, both, or neither.** Both means the full 3×
  overstatement pattern (like the reproduced KES 2,000 example); one alone means a partial
  overstatement (e.g. a requisition disbursed before this fix but never surrendered — Query 1 only
  — or one whose disbursement somehow avoided Symptom 1 but whose surrender still shows Symptom
  2).
- **`total_suspected_excess` in Query 3 is the extraneous amount, not the total recognised cost.**
  The real economic expense for each affected requisition is whatever the requisition's own
  surrender total (or disbursement amount, for an unsurrendered one) actually is — this diagnostic
  does not need to separately compute that, since it is already visible on
  `petty_cash_requisitions.actual_spent_amount` / `total_amount`.
- **A requisition appearing in neither query is unaffected** — either it predates job-costed
  petty cash entirely, was never disbursed through this path (e.g. a genuine Direct Disbursement
  Request, correctly excluded), or was already reconciled after this fix was deployed.
- **`accounting_period_id` on the extraneous entries tells Finance which periods, if any, are
  already closed** — relevant to whatever remediation approach is chosen, since correcting a
  closed period requires its own controlled process regardless of this defect.
- **This diagnostic does not compute a "correct" replacement figure or propose journal entries.**
  That is deliberately left to Finance/an accountant, per the task's boundary — see Part 11 of the
  STAB-7 directive ("Do NOT auto-correct history").

## What Finance/Engineering should do with this output

1. Run Query 3 first for a fast, low-detail read on scale (how many requisitions, how much money,
   which accounts).
2. If the scale warrants it, run Queries 1 and 2 to get the row-level detail needed to plan a
   remediation approach (e.g., one compensating journal entry per affected requisition, aggregated
   by period, or another method Finance/an accountant chooses).
3. Bring the Query 3 output to Finance/accountant review alongside
   `14_STAB_7_PETTY_CASH_TRIPLE_POSTING_ANALYSIS.md` before deciding on remediation — this task
   does not make that decision.
