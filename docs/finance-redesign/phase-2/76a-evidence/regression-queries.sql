-- Report 76A Part J. READ ONLY.
SELECT 'J1 unbalanced journals', COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id HAVING ROUND(SUM(CASE WHEN jl.entry_type='debit' THEN jl.amount ELSE -jl.amount END),2)<>0) x;
SELECT 'J2 duplicate journal entry numbers', COUNT(*) FROM (SELECT entry_no FROM journal_entries GROUP BY entry_no HAVING COUNT(*)>1) x;
SELECT 'J3 cost lines with more than one original journal', COUNT(*) FROM (SELECT cost_line_id FROM journal_entries WHERE cost_line_id IS NOT NULL AND reversal_of_id IS NULL GROUP BY cost_line_id HAVING COUNT(*)>1) x;
SELECT 'J4 duplicate cost lines on one source key', COUNT(*) FROM (SELECT source_type,source_id,source_ref FROM cost_lines WHERE source_type IS NOT NULL AND source_id IS NOT NULL GROUP BY 1,2,3 HAVING COUNT(*)>1) x;
SELECT 'J5 stock-issue reversal cost lines', COUNT(*) FROM cost_lines WHERE source_ref='stock-issue-reversal';
SELECT 'J6 stock movement journals touching Accounts Payable', COUNT(DISTINCT cl.id) FROM cost_lines cl JOIN journal_lines jl ON jl.journal_entry_id=cl.journal_entry_id JOIN chart_of_accounts c ON c.id=jl.account_id
  WHERE cl.source_ref IN ('stock-issue','stock-return','stock-issue-reversal','stock-return-reversal') AND (c.code IN ('2100','AP-001') OR c.name LIKE 'Accounts Payable%');
SELECT 'J7 verified company-paid cost lines', COUNT(*), COALESCE(ROUND(SUM(net_amount),2),0) FROM cost_lines WHERE status='verified' AND JSON_UNQUOTE(JSON_EXTRACT(details,'$.funding_mode'))='company_paid';
SELECT 'J8 ...of which have no Payment behind them', COUNT(*), COALESCE(ROUND(SUM(cl.net_amount),2),0) FROM cost_lines cl WHERE cl.status='verified' AND JSON_UNQUOTE(JSON_EXTRACT(cl.details,'$.funding_mode'))='company_paid'
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.source_document_type LIKE '%CostLine' AND p.source_document_id=cl.id);
SELECT 'J9 voided payments whose own cost line still counts', COUNT(*), COALESCE(ROUND(SUM(cl.net_amount),2),0) FROM payments p JOIN cost_lines cl ON cl.source_type LIKE '%Models\\\\Payment' AND cl.source_id=p.id WHERE p.status='voided' AND cl.status='verified';
SELECT 'J10 issued invoices: net / VAT / gross', COALESCE(ROUND(SUM(total_amount-tax_amount),2),0), COALESCE(ROUND(SUM(tax_amount),2),0), COALESCE(ROUND(SUM(total_amount),2),0) FROM project_invoices WHERE status<>'void' AND journal_entry_id IS NOT NULL;
