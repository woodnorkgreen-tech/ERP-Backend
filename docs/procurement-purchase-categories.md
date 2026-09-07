# Requisition purchase categories

The requisition's Purchase category describes the goods or supplier service being
requested. Purpose explains why it is needed. Existing expense-code IDs and
purpose codes are preserved.

- Requesters may save without a category; Accounts completes and reviews every
  category before approval. The detail screen shows categories and allows
  approvers to edit a pending requisition.
- Procurement uses active, procurable codes compatible with the project context.
  Staff allowance/overtime and per diem remain in the Finance catalogue and fund
  requisitions, but are excluded from procurement. The September 7 migration
  applies this to existing catalogues; the seeder applies it to new catalogues.
- Material suggestions require a material or category mapping. An unmapped
  material is not suggested as MDF merely because MDF is the stock-posting
  fallback. Changing material refreshes the suggestion; requesters can clear it
  for Finance review.
- Existing unclassified receipts still accrue. The approval requirement is a
  review control, not a prerequisite for recording a historical delivery.

Remaining workflow gaps: office stationery needs a dedicated chart account and
expense-code mapping. Supplier services need service completion acceptance in
the purchase-to-pay workflow, and asset purchases need asset review. This change
does not introduce those workflows or assign new ledger accounts.
