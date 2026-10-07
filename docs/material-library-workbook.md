# Material library Excel download and upload

The library now provides **Download all materials** beside Add material. The `.xlsx` file contains every non-deleted catalogue record, including drafts, inactive items and items hidden from inventory, across all workshop areas. Current filters and pagination do not limit this download.

Edit the **Materials** sheet, then choose **More → Import from a spreadsheet** and upload the same workbook. Keep Material ID for an existing item. Leave it blank for a new item; supply an unused code or let the existing registration service generate one. An existing code without an ID updates that existing item for compatibility with older uploads. Deleted materials are never automatically restored.

The columns follow the creation modal: names and identity, category and item type, workshop, status, disposition and tracking, stock/purchase/issue units and conversion factors, default cost, manufacturer/part number, flags, reusable dimensions, revision, effective date, notes, and category specifications. Custom specifications remain in Additional attributes (JSON). Separate Attribute columns override the corresponding JSON keys.

Dropdowns use the authoritative category, item-type, workshop and unit registries. They also cover statuses, disposition, tracking and Yes/No flags. Specification dropdowns follow the selected category and its inherited schema. The hidden Lists sheet supplies named ranges, so commas and long option lists work without Excel's inline-list length limit. An Instructions sheet explains the upload contract. Dropdowns extend at least 200 rows past existing materials, with a minimum of 1,000 entry rows.

Columns marked **(reference)** show legacy classification/UOM, current valuation, stock on hand and inventory visibility. Upload ignores these columns. Changing inventory quantities, receipt valuation or shelf visibility remains the responsibility of the existing workflows.

Upload validates the same registration rules and cross-field/category specification checks as the modal. Existing updates use the modal's update path, preserving the stock-unit lock, board-history protections, completeness checks, unit conversion synchronization and stock tracking projection. Each row runs in its own transaction; a failed row changes nothing and reports its actual Excel row number. Formula cells are refused; exported user text is written as literal text. Unknown material IDs cannot silently create replacements. Unknown or duplicate column headings are refused.

The blank reference template is now `.xlsx` rather than the old CSV which the upload endpoint could not accept. New workbooks identify each row's workshop, so no global target workshop is required. Existing legacy `.xlsx`/`.xls` files remain supported with a selected target workshop through the original importer.

Access uses existing permissions: `materials_library.view` for downloads, `materials_library.import` for uploads. The import action is now displayed using the import permission itself.

Verification evidence is stored in `docs/material-library-evidence/` in each repository. No material records were uploaded or modified in the live local library during implementation; imports in automated tests use `db_test`.

## Completed verification

- All 70 material-library backend tests passed (297 assertions), including workbook round trips, custom JSON value types, category dropdowns, ID handling, invalid-row rollback, API upload and stock-unit history locks.
- All 14 material-library frontend tests passed.
- Vite build passed.
- TypeScript reports the existing 273 diagnostics, with none in the material-library changes.
- Both repositories passed `git diff --check`.
- A read-only export of the real local library contains 437 materials, 92 columns and 14 dropdown fields; the workbook is 88,056 bytes. File: `material-library-evidence/material_library.xlsx`.
- Existing category-planning text appended after a PHP seeder class caused a syntax error in the regression suite. The text was retained inside a comment; no seeder was run against the live local library.

## Upload and pagination follow-up

The upload modal validates both browsed and dropped Excel files (case-insensitive `.xlsx`/`.xls`, maximum 5 MB). Selecting a corrected file clears the previous result so it can be submitted again. It displays server field-validation details and precise row errors, distinguishes zero successes from successful imports, prevents dismissal or duplicate submissions during upload, and refreshes the catalogue and Needs Finishing count only when rows were saved. Incomplete items may be in Needs Finishing; existing catalogue filters can hide imported rows. Successful imports invalidate shared material lookup caches.

Workbook detection now uses canonical column headers, allowing a renamed downloaded sheet while preserving legacy files with a sheet named Materials. The API summary status distinguishes failed, partial, and successful imports.

Catalogue pagination is server-side, 50 rows per page, for both all-material and workshop views. The footer reports the matching range even on a single page; navigation appears when there are multiple pages. A material ID tie-breaker keeps equal sort values stable, and the frontend ignores responses from superseded page/filter requests.

Regression verification: 74 backend tests / 324 assertions and 19 frontend tests passed. Logs: `material-library-evidence/upload-followup-tests.txt` in each repository. Backend tests use the isolated test database and roll back fixture changes.

## Category edits in uploaded workbooks

The selected category's owning item type is authoritative on every import. A workbook's stale or conflicting type is replaced with that owner. Categories without an owner retain the explicitly selected type. Stock units remain explicit: the importer does not convert inventory quantities or replace units to satisfy a category restriction.

New exports have category-dependent Item type and Stock unit dropdowns, including inherited category rules. Purchase and Issue units retain their registry choices because their conversion factors govern how they relate to the Stock unit. Validation errors now name the conflicting category/unit and allowed stock-unit codes, or the exact required item-type dropdown value. Existing stock-unit and board-history guards still apply.

The user-supplied error list identifies conflicts but does not include the workbook cells or new category assignments; the specific edited workbook must be attached to correct those rows reliably. A fresh read-only export with the improved dropdowns is in `material-library-evidence/material_library.xlsx`.

Category-change regression verification: 78 backend tests passed (340 assertions). The saved/reloaded Excel tests verify the actual category-specific dropdown lists. `material-library-evidence/category-import-tests.txt` contains results. The read-only export contains 437 materials and changed no records.

## Uncategorized fallback and legacy item types

The built-in UNCAT category accepts every registered stock unit. Its historical `allowed_uoms: [pcs]` configuration is treated as a default for new unit-less items, rather than a restriction on existing sheets, sets, rolls, square metres or litres. Shared category metadata, workbook dropdowns, defaults and validation use the same effective unit policy. Regular category restrictions and stock-history locks remain enforced.

Imports derive the owning type for existing and new materials, including repeated uploads of the same workbook after an earlier import repaired the database. This does not depend on whether the uploaded type matches the current stored value. The read-only local export confirmed worksheet rows 376 and 377 have category Drill Bits but the old Stock Material type.

Verification: 80 backend tests passed, 358 assertions. The fallback regression preserves sheet/set/roll/m2/l unit IDs and still defaults a new unit-less item to pcs. See `material-library-evidence/uncategorized-import-tests.txt`. No live material data was imported to verify these changes.

## Repeated uploads

Category ownership now resolves Item type independently of the current database value. A retry of an older workbook therefore cannot fail merely because an earlier import already corrected its type. The repeated-upload regression imports the exact same category-edited file three times, verifies updates without duplicate creation, and preserves stock-unit identity. Categories without an owner still accept explicit registered types.

Verification: 82 material-library tests passed (379 assertions); see `material-library-evidence/category-retry-tests.txt`.
