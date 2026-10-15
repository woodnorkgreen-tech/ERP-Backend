# Report 78: Resolve Material Implementation Audit

**Scope:** Stores → Project Material Desk → *Resolve material*, traced from BOM entry through to issue, demand, procurement and cost.
**Date:** 2026-10-14 · **Stage:** A (audit only). No application code, migrations or data were changed. Nothing was committed or pushed.
**Evidence:** `78-evidence/Report78ResolveMaterialProbeTest.php` (characterization probes, run against an isolated scratch database) and read-only queries against the dev database `db`.

---

## A. Executive assessment

**Verdict: partially correct. The core mapping is sound, but three defects can corrupt inventory, demand and project cost.**

What works:

- **Mapping onto the BOM line.** Resolution writes `library_material_id` onto the existing `element_materials` row. It leaves the description, quantity and unit untouched, so no second requirement is created and demand is not double-counted.
- **Concurrency and retries.** The endpoint locks the line, re-checks it inside the transaction, refuses a line that is already linked, and only accepts Active library items. Concurrent and repeated requests are safe: a retry returns 422 and changes nothing (probe-confirmed).
- **No side effects in match mode.** A plain *Match existing* creates no stock movement, journal, cost line, requisition or library row (probe-confirmed).

What does not work:

1. **Resolutions get erased.** The BOM writer overwrites `library_material_id` with whatever the browser sends, including `null`. Resolution does not bump the list's version stamp, and a restored browser draft bypasses that stamp anyway. A stale save therefore silently undoes the resolution.
2. **A line can be re-resolved to a different material after stock was issued against it.** Issued stock of material A then counts as fulfilment of a requirement for material B.
3. **Units are never checked.** Nothing compares the BOM unit with the library unit. The issue cap then compares a base-unit quantity against the BOM-unit number. The dev database already shows the result: the only desk resolution on record linked *"Screws 40mm, 1 Packet"* to *"Drywall Screws 3.5x55mm" (box)*. That is the wrong size and the wrong unit, and one box was issued against it.

The action also does more than resolve identity. One optional checkbox posts a stock-adjustment receipt **and a GL journal** in the same call. *Create new* registers an **Active** library item with no duplicate check.

**Recommendation: REFINE** (see §H). The architecture is right; the fixes are targeted.

---

## B. Actual implementation map

### B.1 BOM entry (Projects → Materials task)

| Step | Location | Behaviour |
|---|---|---|
| Line entry UI | `ERP-Frontend/src/modules/projects/components/MaterialsModal.vue` (inline search ~L1199–1290, picker ~L90–100) | Typing searches `GET /api/materials-library/materials`. **Dropdown pick** sets `libraryMaterialId`, **overwrites the description with the library name**, copies the unit cost, and copies the unit only if it appears in a fixed string list. **Typed entry** ("Use '…' as a non-inventory item") sets `libraryMaterialId = null`. **Any keystroke in the description clears `libraryMaterialId`** (`onInlineMaterialInput`). The badge shows *Stores* or *Custom*. |
| Payload | `src/modules/projects/services/materialsService.ts` L248–270 | Always sends `libraryMaterialId: material.libraryMaterialId \|\| null` and `sourceMetadata \|\| null`. |
| Local draft | `MaterialsTask.vue` L1312–1372 | Continuous localStorage autosave. `restoreDraft()` replaces the elements with the stored copy, but `sourceUpdatedAt` stays the freshly loaded one. |
| Save | `app/Http/Controllers/MaterialsController.php::saveMaterialsData` L470 | Optimistic lock on `task_materials_data.updated_at` (`sourceUpdatedAt`). Approval resets only if `haveMaterialsChanged()` says so. |
| Change detection | `MaterialsController::generateChangeSummary` ~L1670–1735 | Keys lines on **description** and compares **quantity** only. A change of unit or library identity on an approved list is **not** a change: approval is preserved. |
| Writer | `app/Services/ProjectElementRegistry.php::saveLines` L164–195 | Matches lines by `persistent_id`. `library_material_id = array_key_exists('libraryMaterialId') ? input : existing`, so **an explicit `null` overwrites**. `source_metadata = input ?? existing`. |
| Storage | `element_materials` (`library_material_id` FK → `library_materials` nullOnDelete; `description`; `unit_of_measurement` **free-text string**; `quantity` decimal(2); `source_metadata` JSON; soft-delete `archived_at`) | A typed line is simply `library_material_id IS NULL`. There is no explicit "unresolved", "non-stock" or "resolution pending" state. |

### B.2 Resolve Material

| Layer | Location | Behaviour |
|---|---|---|
| Desk row | `ERP-Frontend/src/modules/procurement-stores/views/stores/ProjectMaterialsDesk.vue` L846–915 | Rows come from `GET /api/projects/enquiries/{id}/materials`. `inventory` is the library match. `available` is in the **library** unit and `required` is in the **BOM** unit. The *Resolve material* button (L189) shows when `!row.inventory && !approvalPending`. |
| Modal | `src/modules/procurement-stores/components/ResolveMaterialModal.vue` | Header shows description · element · required + BOM unit. **Match existing:** substring search over name + code across all Active items (`include_unstocked=1&include_hidden=1`). Each result shows code, category, available and the **library** unit. **Create new:** `QuickCreateMaterialFields`. Optional checkbox **"Add stock we already have"** adds reason, explanation, quantity, value, lot/expiry and roll breakdown. |
| API | `POST /api/procurement-stores/project-materials/{elementMaterial}/resolve-catalogue` (`app/Modules/ProcurementStores/Routes/api.php` L82) | |
| Controller | `ProcurementStoresController::resolveProjectMaterialCatalogue` L1155–1265 | ① `stores.manage` permission, otherwise 403. ② Refuses if already linked. ③ `assertMaterialsApproved` (both sign-offs). ④ Transaction: `lockForUpdate`, then re-check linked. ⑤a With `receive`: `StockMovementPoster::post('receive')` posts an exceptional receipt + GL journal, and can register the material inline. ⑤b With `new_material`: `MaterialRegistrationService::create`. ⑤c Otherwise loads an existing material and requires Active. ⑥ Sets `library_material_id` and `source_metadata.catalogue_resolution = {resolved_by, resolved_at, library_material_id}`. |
| Not done | | No unit check. No project-status or `is_included` check. No event dispatch. No version snapshot or activity log. No reason (removed 2026-09-17). `task_materials_data.updated_at` is not touched. |

### B.3 Downstream

| Consumer | How it sees the resolved line |
|---|---|
| Issue (`StockMovementPoster::assertProjectLineCanTake` L459–500) | Requires `planned.library_material_id === material.id`. Caps the issue at `planned.quantity − netIssued`, with **issue quantity converted to the stock base unit but the requirement left in the BOM unit**. |
| Demand (`ProjectMaterialDemand::specifiedLines` L183) | `whereNotNull('library_material_id')`. Unresolved lines are invisible to demand and replenishment; once resolved they count once. The quantity is summed per material with no unit conversion. |
| Budget / procurement task / planned cost lines (`BudgetService` L405–420 → `BudgetLinesChanged`) | Copies `library_material_id` only when `MaterialsListChanged` fires. **Resolution does not fire it**, so these copies stay `null` until the next BOM save. |
| Requisitions (`RequisitionItem`) | Linked by `budget_item_persistent_id` / `material_id` / `custom_description`; there is no `project_material_id`. Existing free-text requisition items are not re-linked on resolution. The desk's "awaiting purchase" check falls back to description equality (`ProjectMaterialsDesk.vue` L905). |
| Reservations | There is no generic reservation for project lines. Board requests require a `material_id`, so they cannot exist on an unresolved line. |
| Cost lines | Not touched by resolution. Issue cost inherits element/material from the planned line. |

### B.4 Code-path trace (BOM creation → resolution → issue)

```
MaterialsModal.vue (typed line, libraryMaterialId=null)
  → materialsService.ts save → PUT materials/{task}
  → MaterialsController::saveMaterialsData → ProjectElementRegistry::saveLines
  → element_materials{library_material_id NULL, description, unit (string), quantity}
  → (PO + Production approve) MaterialsController::approveMaterials → all_approved=true
ProjectMaterialsDesk.vue row (inventory=null) → [Resolve material]
  → ResolveMaterialModal.vue → POST project-materials/{id}/resolve-catalogue
  → ProcurementStoresController::resolveProjectMaterialCatalogue
      [optional] StockMovementPoster::post('receive') → InventoryService::adjustStock
                 → StockMovementPostingService::postExceptionalReceipt (journal)
      [optional] MaterialRegistrationService::create (library_materials row)
  → element_materials.library_material_id = X ; source_metadata.catalogue_resolution
ProjectMaterialsDesk.vue row (inventory=X, available in X's unit) → Issue
  → POST /movements → StockMovementPoster::postIssue → assertProjectLineCanTake (unit-blind cap)
  → InventoryService::adjustStock → StoresFinanceOutbox → StoresCostProducer (project cost)
```

---

## C. Current versus expected behaviour

| Expected (brief §5–§10) | Current | Status |
|---|---|---|
| Preserve original description, quantity and unit | Untouched by resolution | ✅ |
| Link a valid Material ID | Active items only (match). *Create new* links whatever the registration returns. | ✅ / ⚠ |
| Record an approved unit conversion | Not captured; no unit check at all | ❌ F-03 |
| Resolver identity and time | `source_metadata.catalogue_resolution` | ✅ (overwritten on re-resolve, F-08) |
| Mark resolved, consistently downstream | Desk yes; budget, procurement task and planned cost lines stay stale | ⚠ F-07 |
| Recalculate availability from current stock | Desk reloads. Comparison mixes units. | ⚠ F-03 |
| No GRN, issue, PO or project cost | No GRN, issue, PO or cost. **Optional stock-adjustment receipt + journal in the same action.** | ⚠ F-04 |
| No uncontrolled duplicate material | *Create new* registers an Active item with no duplicate check | ❌ F-05 |
| Approved BOM: controlled update, not a silent rewrite | Metadata-style update on the live approved row; no version record | ⚠ F-08 |
| BOM revision must not overwrite or erase a resolution | A stale save or restored draft erases it | ❌ F-01 |
| Identity change after issue only via controlled correction | Re-resolve to another material succeeds | ❌ F-02 |
| Old-version resolution must not overwrite a newer requirement | Single live row, so no cross-version overwrite. A description retype clears identity. | ✅ / ⚠ |
| Idempotent; concurrent users cannot conflict | Row lock + in-transaction re-check; retry 422 with no side effects | ✅ |
| Server-side authorization | `stores.manage` (403 otherwise). No per-project scope. | ✅ (P3) |
| Closed project / excluded line refused | Neither is checked | ⚠ F-09 |
| Search suggestion ≠ approved match; spec-aware matching | Name/code substring; no specs, attributes or unit-compatibility cue | ❌ F-06 |

---

## D. Findings

### P0: data integrity, inventory or project-cost corruption

**F-01: A BOM save silently erases a resolution.** *(probe-confirmed)*
`ProjectElementRegistry::saveLines` accepts `libraryMaterialId: null` as an instruction to unlink. The frontend always sends that key, and sends `null` for any line it believes is custom. Resolution does not bump `task_materials_data.updated_at`, so a Materials screen opened before the resolution passes the `sourceUpdatedAt` check. `restoreDraft()` keeps the freshly loaded stamp, so a recovered localStorage draft also passes even when the stamp *is* current. Change detection ignores identity, so approval stays green and nobody is told. The line reappears on the desk as unresolved.

**F-02: A line with issue history can be re-resolved to a different material.** *(probe-confirmed)*
The endpoint only checks `library_material_id IS NULL`. Combine it with F-01, or with an ordinary BOM description edit (which clears the id client-side). A line whose 18mm MDF sheets were already issued can then be re-linked to 9mm MDF. The 18mm movements stay on `project_material_id` and count as fulfilment of the 9mm requirement in both `assertProjectLineCanTake` and the desk. The first `catalogue_resolution` record is overwritten, so the history is lost. Dev has 0 such movements today (`issue_material_differs_from_line = 0`), so this is latent.

**F-03: No unit-compatibility gate. The issue cap and demand are unit-blind.** *(probe-confirmed and seen in real data)*
- Resolution accepts *12 sqm* → a per-sheet item (`pcs`) without warning (probe).
- `assertProjectLineCanTake` compares `quantityInBase` (stock unit) against `planned.quantity` (BOM unit). A 12 sqm requirement therefore permits 12 sheets, and the project is charged for 12 sheets. The method's own docblock says "in the unit it was approved in"; the code does not do that.
- `ProjectMaterialDemand` sums BOM-unit numbers into a base-unit total, so the replenishment planner buys the wrong quantity.
- The desk's shortage figure (`row.remaining − row.available`) and the modal's "N short" subtract numbers in different units.
- **Dev evidence:** 233 of 667 linked lines (35%) have a BOM unit whose text differs from the library unit. Many are spelling only (`mtrs`/`m`, `kgs`/`kg`), but many are dimensionally incompatible: `pcs→m` (31), `packets→kg` (24), `sqm→m` (17), `kgs→m` (9), `ltrs→can` (9), `sqm→pack` (7). Two issues have already posted against mismatched lines:
  - movement **78**: 1 *box* of "Drywall Screws 3.5x55mm" against the resolved line "Screws 40mm, 1 *Packet*". This was created via Resolve Material, and both the size and the unit are wrong.
  - movement **79**: 8 *m* of "Matt white sticker" counted against a 14 *sqm* line. This came through the dropdown path, so the defect is not limited to Resolve.

### P1: incorrect workflow or missing validation

**F-04: Resolve bundles a stock receipt and a journal.** *(probe-confirmed)*
With "Add stock we already have" ticked, one call posts an `inventory_logs` check-in and a `journal_entries` entry (`postExceptionalReceipt`). The receipt is well governed: `stores.review`, a non-purchase reason, a value, a written explanation, atomic. But it is general stock (`project_material_id` null), not tied to the line, and it puts a GL posting inside an action whose stated job is identity. The *Receive Stock* screen already offers the same receipt.

**F-05: "Create new" registers an uncontrolled, possibly duplicate library item.** *(probe-confirmed)*
`MaterialRegistrationService::create` makes the item **Active** whenever the governance set is complete; the probe got `item_status=Active` with only a name and category. There is no duplicate or near-match check: an exact duplicate name was accepted. There is no request/review step. When the result is *Under Review*, the non-receipt branch still links the line to it, while the match branch refuses non-Active items. That is inconsistent, and it leaves an unissuable link.

**F-06: Matching is name-substring only.**
The modal does not prefill the search from the BOM description. It does not show specifications or attributes, does not flag unit compatibility, and does not distinguish *same item / different spec / different unit*. The dev resolution (40mm → 3.5x55mm) is exactly the "similar name, different specification" case in brief §4.

**F-07: Downstream copies are not refreshed.** *(probe-confirmed)*
`MaterialsListChanged` is not dispatched, so the budget line, procurement task and planned cost lines keep `library_material_id = null` until someone next saves the BOM. Existing free-text requisition/PO items are not re-linked either. When that delivery arrives, Receive can *Quick-create* a second library item for it, which is the duplicate F-05 warns about, arriving by another door.

**F-11: BOM change detection ignores unit and identity.**
`generateChangeSummary` compares description and quantity only. Changing a line's unit from *Sheets* to *sqm*, or swapping its library item, on an approved list keeps both approvals. This is a BOM-side defect, but it is what lets F-01 and F-03 happen silently.

### P2: usability or reporting weakness

- **F-08: Thin audit trail.** Resolution leaves one JSON stamp, which a later resolution overwrites. There is no previous value, no reason, no activity log entry, and no material-version snapshot. A stale save that sends non-null `sourceMetadata` would also drop the stamp.
- **F-09: No lifecycle checks.** Excluded lines (`is_included=false`) can be resolved (probe), and the project's status (completed/closed) is never consulted. Resolution is bound to the live row rather than a BOM version. Because rows persist by `persistent_id` this is mostly fine, but a description retype on a later revision clears the identity without anyone deciding to.
- **F-12: No "non-stock" outcome.** The BOM picker offers "Use '…' as a **non-inventory item**", yet the desk treats every unlinked line as blocked until it is registered in the library. The only escape for a one-off project purchase is to create a library item, which feeds F-05. Dev has **4,966 unlinked lines on approved lists**, so this is the dominant state rather than an edge case.
- **F-13: Modal context gaps.** No project or job reference. The available figure is shown in the library unit beside a requirement in the BOM unit, with no label saying they differ. The receipt text says "becomes issuable immediately" but does not say a journal will post.

### P3: optional

- **F-10:** Authorization is a single module permission with no per-project scope. That is acceptable for a cross-project Stores team; note it if project-scoped Stores roles ever appear.

---

## E. Accounting and inventory impact

| Can Resolve Material… | Answer | Evidence |
|---|---|---|
| Create a stock movement? | **Yes, only when "Add stock we already have" is ticked:** one exceptional check-in, gated by `stores.review`. Never otherwise. | probes `…with_receipt_posts_stock_and_journal`, `…match_without_receipt_creates_no…` |
| Post a journal? | **Yes, in the same receipt case** (`postExceptionalReceipt`). Never otherwise. | same |
| Create or change a project cost line? | **No, not directly.** Indirectly yes: F-03 lets a later issue charge the wrong quantity, and F-02 lets issued cost sit against the wrong material. | probe counts `cost_lines` unchanged; `assertProjectLineCanTake` |
| Create purchase demand / requisition / PO / GRN? | **No.** Resolution moves the line from "invisible to demand" to "counted once". It does not double it, but the count is unit-blind (F-03). | probe counts `requisition_items` unchanged; `ProjectMaterialDemand::specifiedLines` |
| Duplicate demand (the 10 → 20 case)? | **No.** The same row is updated in place, and unresolved lines were never in material demand. | B.3 |
| Create a library item? | **Yes**, via *Create new*, Active, with no duplicate check (F-05). | probe `…create_new_allows_exact_duplicate_name` |
| Reassign historical issue identity? | It never rewrites movements. But it lets the **line** change material underneath existing movements (F-02). | probe `…re_resolved_to_another_material` |

---

## F. Proposed corrections

| # | Root cause | Files / functions | Change | Risk | Depends on | Test required |
|---|---|---|---|---|---|---|
| F-01 | Writer treats an explicit `null` as "unlink"; resolution does not stamp the list | `ProjectElementRegistry::saveLines`; `ProcurementStoresController::resolveProjectMaterialCatalogue`; `MaterialsTask.vue::restoreDraft` | Server: never downgrade an established `library_material_id` to null or a different id through a plain save. Keep the existing id unless the payload carries an explicit, separate "unlink/relink" intent, and refuse relinking if movements exist (see F-02). Resolution touches `task_materials_data.updated_at`. Restored drafts merge server-side ids instead of replacing them. | Low/medium: BOM users lose the ability to unlink by retyping, which is intended | none | Stale save keeps the id; restored draft keeps the id; explicit unlink without movements works |
| F-02 | Relink guard only looks at the column | `resolveProjectMaterialCatalogue` + new guard in the BOM writer | Refuse when any `inventory_logs` / `board_requests` row references the line with a different `material_id`. Route the change to the existing controlled correction (`linkProjectMaterial` + Finance reversal). Append history instead of overwriting `catalogue_resolution`. | Low | F-01 | Re-resolve after issue → 422; history retains both entries |
| F-03 | BOM unit is a free-text string with no mapping to `units_of_measure`; cap and demand compare raw numbers | `resolveProjectMaterialCatalogue`; `StockMovementPoster::assertProjectLineCanTake`; `ProjectMaterialDemand`; `ProjectMaterialsDesk.vue` row build; `ResolveMaterialModal.vue` | (1) Normalise the BOM unit string to a `units_of_measure` id (`mtrs→m`, `kgs→kg`, `boxes→box`, …). (2) At resolution, require that unit to equal the material's base unit **or** have a `uomConversions` factor. Otherwise refuse with a reason. A dimensional change (sqm→sheet, roll→m) needs an explicit, recorded conversion and approval. (3) Store the factor with the resolution. (4) Convert `planned.quantity` to the base unit in the issue cap, demand and desk. Never rewrite the BOM quantity. | **Medium/high.** Touches the issue cap every project issue uses, and the 233 existing mismatched lines need a decision (migration and backfill in the implementation stage). | Unit-alias table (WNG decision on aliases). Decision on movements 78/79. | 10 pcs→pcs; 5 m→m; 2 rolls→m only with a factor; 12 sqm→sheet refused without a factor; 1 board ≠ 1 sqm; cap converts |
| F-04 | Receipt bolted onto resolution for convenience | `ResolveMaterialModal.vue`; `resolveProjectMaterialCatalogue` (`receive` branch) | Remove `receive` from this endpoint. After resolving, offer a link to *Receive Stock* prefilled with the material. Keep the `stores.review` governance there. | Low. Three existing tests use `receive` and must move to the receipt suite. | none | Resolve with `receive` → 422 or ignored; zero movement/journal |
| F-05 | Quick-create reused as-is | `resolveProjectMaterialCatalogue` (`new_material`); `MaterialRegistrationService` | Prefer **Request new material**: register as *Under Review* (Draft) with the BOM description, project, requester and candidate matches. Link the line only once the item is Active. At minimum, run a duplicate/near-name check and require confirmation. Make both branches refuse non-Active. | Medium: adds a review step for Stores | Who reviews new items (WNG decision) | Exact duplicate warned/refused; Under Review item not linked |
| F-06 | Search is a client-side substring filter | `ResolveMaterialModal.vue` | Prefill the search from the description. Show attributes/specs, category and **unit compatibility** per result (✓ same unit / ↔ convertible / ✕ incompatible). Disable incompatible picks. | Low | F-03 unit mapping | Component test: incompatible result disabled |
| F-07 | No event after resolution | `resolveProjectMaterialCatalogue` | `DB::afterCommit(fn () => MaterialsListChanged::dispatch($taskId))`. Optionally back-fill `material_id` on open requisition items keyed by `budget_item_persistent_id`. | Low. The listener already merges idempotently. | none | Event dispatched; budget line carries the id |
| F-08 | Single JSON stamp | same | Append `catalogue_resolutions[]` with previous/new id, unit, factor, user, time and optional note | Low | F-02 | History retained |
| F-09 | No state checks | same | Refuse `is_included=false`, archived lines, and completed/closed projects | Low | Definition of "closed" | Each refused |
| F-11 | Change summary keyed on description + quantity | `MaterialsController::generateChangeSummary` | Key on `persistent_id`. Treat unit and library-identity changes as material changes (approval resets). | Medium: more re-approvals | F-01 | Unit change on approved list resets approval |
| F-12 | No non-stock state | BOM line + desk | Add an explicit *non-stock / direct purchase* disposition so such lines leave the "unlinked" queue without a library item. Procure them via a direct requisition. | Medium (product decision) | WNG decision | Non-stock line not shown as blocked |
| F-13 | UI copy | `ResolveMaterialModal.vue` | Add the project/job reference, unit labels on both sides, and a "what will happen" summary | Low | F-03 | Snapshot |

**Suggested order:** F-01 + F-02 (stop identity loss; small, no migration) → F-07, F-09, F-04 (small) → F-03 (needs the unit-alias decision and a backfill plan) → F-05, F-06, F-11, F-12.

---

## G. Tests

**Environment:** isolated scratch DB `db_r78_resolve_test`, created via the documented recipe (DDEV MariaDB, schema built from empty by `RefreshDatabase`). Shared `db_test` and dev `db` were never written. Dev `db` was only queried read-only.

| Command | Result |
|---|---|
| `ddev exec php artisan test -c phpunit.r78resolve.xml tests/Feature/Stores/ResolveProjectMaterialCatalogueTest.php` | **13 passed** (53 assertions), 169s |
| `ddev exec php artisan test -c phpunit.r78resolve.xml tests/Feature/Stores/Report78ResolveMaterialProbeTest.php` | **10 passed** (31 assertions), 161s. Each probe asserts *current* behaviour, so a pass proves the finding. |

The probe file lives in `78-evidence/` and was taken out of `tests/` after the run so it does not join the regular suite. To re-run it, copy it back temporarily. (One probe first failed because of a fault in the probe itself: an in-memory model never refreshed. It was fixed and re-run. The system behaviour was not misread.)

**Brief §12 coverage:**

| # | Scenario | Covered by | Result |
|---|---|---|---|
| 1 | Manual BOM entry appears unresolved | code trace (`library_material_id IS NULL` → desk *Resolve*) | ✅ |
| 2 | Dropdown entry already resolved | code trace (`selectMaterialSuggestion`) | ✅ (unit copy unreliable, F-03) |
| 3 | Exact match | existing `…resolves_existing_material_without_stock_receipt` | ✅ |
| 4 | Similar name, different spec | real data (40mm → 55mm) | ❌ accepted, F-06 |
| 5 | Incorrect unit conversion | probe `…incompatible_unit_is_accepted` | ❌ accepted, F-03 |
| 6 | Valid unit conversion | — | **gap**: no conversion concept at resolution |
| 7 | No library match | existing create-new tests | ⚠ creates Active, F-05 |
| 8 | Duplicate candidates | probe `…exact_duplicate_name` | ❌ accepted |
| 9 | Approved BOM | existing `…unapproved_material_list_cannot_be_resolved` | ✅ approval required |
| 10 | Revised BOM after resolution | probe `…stale_bom_save_erases_resolution…` | ❌ erased, F-01 |
| 11 | Concurrent attempts | code (`lockForUpdate` + re-check) | ✅ not run as a forked race test (gap) |
| 12 | Repeated request | probe `…repeat_request_is_refused…` | ✅ |
| 13 | Existing procurement request | code trace | ⚠ not re-linked, F-07; **gap**: no test |
| 14 | Existing reservation | n/a: no reservations on unresolved lines | ✅ |
| 15 | Partially issued material | probe `…re_resolved_to_another_material` | ❌ F-02 |
| 16 | Closed project | code (no check) | ⚠ F-09; **gap**: no test |
| 17 | Unauthorized user | existing + probe `…without_stores_manage_is_refused` | ✅ |
| 18 | Stale/deleted library material | code (Active-only on match; FK nullOnDelete) | ✅ match / ⚠ create-new |
| 19 | Stock availability recalculation | code (desk reload) | ⚠ unit-mixed, F-03 |
| 20 | No duplicate demand/movement/journal/cost | probe `…match_without_receipt_creates_no…` + `…with_receipt_posts_stock_and_journal` | ✅ match / ⚠ receipt, F-04 |

**Remaining gaps:** a forked concurrency test (pattern exists from W7), a closed-project test, a requisition re-link test, and frontend component tests for `ResolveMaterialModal.vue` (none exist).

**Read-only dev-data queries run:** count of unlinked lines on approved lists (4,966), desk resolutions (1), linked lines with differing unit text (233/667, grouped), issues on mismatched lines (2: movements 78 and 79), and issues whose material differs from the line's (0).

---

## H. Final recommendation: **REFINE**

The core is the right shape. Resolution is a mapping written onto the existing requirement row, with no second requirement and no demand duplication. It is approval-gated, locked, re-checked in the transaction, and refuses retries cleanly. Plain matching has no inventory, procurement or accounting side effects. None of that needs redesign.

The defects sit around that core:

- the BOM writer does not respect an identity once it is set (F-01, F-02);
- units were never part of identity (F-03);
- the action carries two jobs that belong elsewhere: receiving stock (F-04) and creating library items without review (F-05).

Each has a contained fix. Only F-03 needs a schema decision and a backfill plan, and it is also the most urgent because it is already in the data. It is not specific to Resolve: movement 79 came through the dropdown path, so F-03 should be scheduled as a cross-cutting BOM/Stores unit fix rather than a Resolve-only patch.

**Decisions needed from WNG before Stage B:**

1. A unit-alias table for BOM unit strings (`mtrs`=`m`, `kgs`=`kg`, `boxes`=`box`, …), and who approves a dimensional conversion (sqm→sheet, roll→m).
2. What to do with movements 78 and 79 and the 233 mismatched lines: review only, or correct via the existing link-correction plus Finance reversal path.
3. Whether *Create new* becomes a reviewed *Request new material* (recommended), and who reviews.
4. Whether to introduce a *non-stock / direct purchase* line disposition, given 4,966 unlinked approved lines.
5. Whether to remove the stock receipt from Resolve (recommended) or keep it.
