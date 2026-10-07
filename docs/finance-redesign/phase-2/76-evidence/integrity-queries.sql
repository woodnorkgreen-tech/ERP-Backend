-- Report 76 integrity counts. READ ONLY: SELECT statements only.
SELECT '01 payments total / active / voided', COUNT(*), SUM(status='active'), SUM(status<>'active') FROM payments;
SELECT '02 active payments with no source link (no requisition, voucher, bill payment, source doc)', COUNT(*), ROUND(SUM(amount),2) FROM payments p
 WHERE p.status='active' AND p.requisition_id IS NULL AND p.spend_voucher_id IS NULL AND p.voucher_id IS NULL AND p.source_document_id IS NULL
   AND NOT EXISTS (SELECT 1 FROM bill_payments bp WHERE bp.disbursement_id=p.id);
SELECT '03 active payments with no journal sourced to them and no cost line', COUNT(*), ROUND(SUM(amount),2) FROM payments p
 WHERE p.status='active'
   AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type LIKE '%Payment' AND je.source_id=p.id)
   AND NOT EXISTS (SELECT 1 FROM cost_lines cl WHERE cl.source_type LIKE '%Models\\\\Payment' AND cl.source_id=p.id)
   AND NOT EXISTS (SELECT 1 FROM bill_payments bp JOIN journal_entries je2 ON je2.source_type LIKE '%BillPayment' AND je2.source_id=bp.id WHERE bp.disbursement_id=p.id);
SELECT '04 payments flagged cost/GL posting failed', COUNT(*) FROM payments WHERE cost_gl_posting_failed_at IS NOT NULL;
SELECT '05 payments flagged advance GL posting failed', COUNT(*) FROM payments WHERE advance_gl_posting_failed_at IS NOT NULL;
SELECT '06 active payments without a paying account', COUNT(*) FROM payments WHERE status='active' AND payment_source_id IS NULL;
SELECT '07 journals total / posted / reversed', COUNT(*), SUM(status='posted'), SUM(status='reversed') FROM journal_entries;
SELECT '08 journals without a source', COUNT(*) FROM journal_entries WHERE source_type IS NULL OR source_id IS NULL;
SELECT '09 unbalanced journals (lines)', COUNT(*) FROM (SELECT je.id FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id
   HAVING ROUND(SUM(CASE WHEN jl.entry_type='debit' THEN jl.amount ELSE -jl.amount END),2)<>0) x;
SELECT '10 journals with no lines', COUNT(*) FROM journal_entries je WHERE NOT EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.journal_entry_id=je.id);
SELECT '11 cost lines (non-planned) without a source', COUNT(*), ROUND(SUM(net_amount),2) FROM cost_lines WHERE nature<>'planned' AND (source_type IS NULL OR source_id IS NULL);
SELECT '12 verified actual/accrued cost lines never posted (no journal)', nature, COALESCE(source_ref,''), COUNT(*), ROUND(SUM(net_amount),2) FROM cost_lines
 WHERE status='verified' AND nature IN ('actual','accrued') AND journal_entry_id IS NULL GROUP BY 2,3;
SELECT '13 reversed cost lines whose journal is still posted', COUNT(*) FROM cost_lines cl JOIN journal_entries je ON je.id=cl.journal_entry_id WHERE cl.status='reversed' AND je.status='posted';
SELECT '14 verified accruals by settled flag', SUM(settled_by_bill_id IS NULL), SUM(settled_by_bill_id IS NOT NULL), ROUND(SUM(net_amount),2) FROM cost_lines WHERE nature='accrued' AND status='verified';
SELECT '15 verified cost lines with no project and no cost centre', COUNT(*) FROM cost_lines WHERE status='verified' AND nature<>'planned' AND project_enquiry_id IS NULL AND cost_centre_id IS NULL;
SELECT '16 GRNs without a purchase order', COUNT(*) FROM goods_receipt_notes WHERE purchase_order_id IS NULL;
SELECT '17 accepted GRN lines with no library material (non-stock/service lines)', COUNT(*) FROM goods_receipt_note_items WHERE accepted=1 AND material_id IS NULL;
SELECT '18 PO lines with no library material', COUNT(*), ROUND(SUM(quantity*unit_price),2) FROM purchase_order_items WHERE material_id IS NULL;
SELECT '19 accepted GRN lines by stock_status', stock_status, COUNT(*) FROM goods_receipt_note_items WHERE accepted=1 GROUP BY 2;
SELECT '20 accepted GRN lines with no accrual cost line', COUNT(*) FROM goods_receipt_note_items gi WHERE gi.accepted=1 AND gi.received_quantity>0
   AND NOT EXISTS (SELECT 1 FROM cost_lines cl WHERE cl.source_type LIKE '%GoodsReceiptNoteItem' AND cl.source_id=gi.id AND cl.source_ref='accrual');
SELECT '21 approved POs with no commitment cost line ever', COUNT(*) FROM purchase_orders po WHERE po.status='approved'
   AND NOT EXISTS (SELECT 1 FROM purchase_order_items i JOIN cost_lines cl ON cl.source_type LIKE '%PurchaseOrderItem' AND cl.source_id=i.id WHERE i.purchase_order_id=po.id);
SELECT '22 GRN line stock qty vs received qty mismatch (posted lines)', COUNT(*) FROM goods_receipt_note_items WHERE stock_status='posted' AND entered_uom_id IS NULL AND ABS(COALESCE(stock_quantity,0)-received_quantity)>0.0001;
SELECT '23 GRN line receipt cost differs from PO unit price', COUNT(*) FROM goods_receipt_note_items gi JOIN purchase_order_items pi ON pi.id=gi.purchase_order_item_id WHERE gi.stock_status='posted' AND ABS(COALESCE(gi.receipt_unit_cost,0)-pi.unit_price)>0.005;
SELECT '24 bills total / without PO (direct) / without verification', COUNT(*), SUM(purchase_order_id IS NULL), SUM(verified_at IS NULL) FROM bills;
SELECT '25 bills paid status with balance > 0, or balance<>amount-paid', SUM(status='paid' AND balance>0.005), SUM(ABS(amount-paid_amount-balance)>0.005) FROM bills;
SELECT '26 stock receipts (check_in) by GRN linkage', SUM(g.id IS NOT NULL), SUM(g.id IS NULL), ROUND(SUM(CASE WHEN g.id IS NULL THEN l.quantity*COALESCE(l.receipt_unit_cost,0) ELSE 0 END),2)
   FROM inventory_logs l LEFT JOIN goods_receipt_note_items g ON g.inventory_log_id=l.id WHERE l.type='check_in';
SELECT '27 stock movements by type', type, COUNT(*), ROUND(SUM(quantity),3) FROM inventory_logs GROUP BY 2;
SELECT '28 issues with no project and no reference', COUNT(*) FROM inventory_logs WHERE type IN ('check_out','issue','consumption') AND project_id IS NULL AND (reference_no IS NULL OR reference_no='');
SELECT '29 issues with project but no project_id (reference only)', COUNT(*) FROM inventory_logs WHERE type IN ('check_out','issue','consumption') AND project_id IS NULL AND reference_no<>'';
SELECT '30 issues frozen at zero unit cost', COUNT(*), ROUND(SUM(ABS(quantity)),3) FROM inventory_logs WHERE type IN ('check_out','issue','consumption') AND COALESCE(receipt_unit_cost,0)<=0 AND movement_value IS NULL;
SELECT '31 project issues with no verified cost line', COUNT(*) FROM inventory_logs l WHERE l.type IN ('check_out','issue','consumption') AND (l.project_id IS NOT NULL OR l.reference_no<>'')
   AND NOT EXISTS (SELECT 1 FROM cost_lines cl WHERE cl.source_type LIKE '%InventoryLog' AND cl.source_id=l.id AND cl.source_ref='stock-issue');
SELECT '32 project issues with no outbox row at all', COUNT(*) FROM inventory_logs l WHERE l.type IN ('check_out','issue','consumption') AND (l.project_id IS NOT NULL OR l.reference_no<>'')
   AND NOT EXISTS (SELECT 1 FROM stores_finance_postings s WHERE s.inventory_log_id=l.id);
SELECT '33 stores finance outbox by status', posting_type, status, COUNT(*) FROM stores_finance_postings GROUP BY 2,3;
SELECT '34 damage/defective movements (no finance posting path)', COUNT(*), ROUND(SUM(ABS(quantity)*COALESCE(receipt_unit_cost,0)),2) FROM inventory_logs WHERE type='defective';
SELECT '35 negative stock', COUNT(*) FROM stocks WHERE quantity_on_hand < -0.0001 AND deleted_at IS NULL;
SELECT '36 stock on hand with zero valuation (no unit cost, no default)', COUNT(*), ROUND(SUM(s.quantity_on_hand),3) FROM stocks s JOIN library_materials m ON m.id=s.material_id
   WHERE s.deleted_at IS NULL AND s.quantity_on_hand>0.0001 AND COALESCE(m.unit_cost,0)<=0 AND COALESCE(m.default_unit_cost,0)<=0;
SELECT '37 stock on hand valued only by default price (no receipt cost)', COUNT(*) FROM stocks s JOIN library_materials m ON m.id=s.material_id
   WHERE s.deleted_at IS NULL AND s.quantity_on_hand>0.0001 AND COALESCE(m.unit_cost,0)<=0 AND COALESCE(m.default_unit_cost,0)>0;
SELECT '38 valuation without quantity (unit cost set, zero on hand)', COUNT(*) FROM stocks s JOIN library_materials m ON m.id=s.material_id WHERE s.deleted_at IS NULL AND ABS(s.quantity_on_hand)<=0.0001 AND COALESCE(m.unit_cost,0)>0;
SELECT '39 stock balance vs last movement balance_after mismatch', COUNT(*) FROM stocks s JOIN (SELECT material_id, MAX(id) mid FROM inventory_logs GROUP BY material_id) x ON x.material_id=s.material_id
   JOIN inventory_logs l ON l.id=x.mid WHERE s.deleted_at IS NULL AND ABS(s.quantity_on_hand-l.balance_after)>0.0001;
SELECT '40 Stores valuation (qty x unit cost)', ROUND(SUM(s.quantity_on_hand*COALESCE(NULLIF(m.unit_cost,0),m.default_unit_cost,0)),2) FROM stocks s JOIN library_materials m ON m.id=s.material_id WHERE s.deleted_at IS NULL;
SELECT '41 GL balance of inventory-type accounts', coa.code, coa.name, ROUND(SUM(CASE WHEN jl.entry_type='debit' THEN jl.amount ELSE -jl.amount END),2)
   FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id JOIN chart_of_accounts coa ON coa.id=jl.account_id
   WHERE (coa.name LIKE '%nventory%' OR coa.name LIKE '%Accrued%' OR coa.name LIKE '%Payable%' OR coa.name LIKE '%Advance%') GROUP BY coa.id;
SELECT '42 requisitions status', status, COUNT(*) FROM petty_cash_requisitions GROUP BY 2;
SELECT '43 closed requisitions', COUNT(*) FROM petty_cash_requisitions WHERE closed_at IS NOT NULL;
SELECT '44 projects financially closed', COUNT(*) FROM project_enquiries WHERE financial_closure_status='closed';
SELECT '45 cost lines posted to financially closed projects after closure', COUNT(*) FROM cost_lines cl JOIN project_enquiries pe ON pe.id=cl.project_enquiry_id WHERE pe.financial_closure_status='closed' AND cl.nature<>'planned' AND cl.created_at > pe.updated_at;
SELECT '46 client receipts: verified without journal / pending', SUM(status='verified' AND journal_entry_id IS NULL), SUM(status='pending'), COUNT(*) FROM enquiry_payments;
SELECT '47 issued invoices without journal', COUNT(*) FROM project_invoices WHERE status NOT IN ('draft','void') AND journal_entry_id IS NULL;
SELECT '48 projects where verified actual > 0 and planned = 0', COUNT(*) FROM (SELECT project_enquiry_id, SUM(CASE WHEN nature='planned' THEN net_amount ELSE 0 END) p, SUM(CASE WHEN nature='actual' THEN net_amount ELSE 0 END) a
   FROM cost_lines WHERE status='verified' AND project_enquiry_id IS NOT NULL GROUP BY 1 HAVING a>0 AND p=0) x;
SELECT '49 projects with negative net actual', COUNT(*) FROM (SELECT project_enquiry_id FROM cost_lines WHERE status='verified' AND nature='actual' AND project_enquiry_id IS NOT NULL GROUP BY 1 HAVING SUM(net_amount)<0) x;
SELECT '50 queued jobs waiting / failed', (SELECT COUNT(*) FROM jobs), (SELECT COUNT(*) FROM failed_jobs);
