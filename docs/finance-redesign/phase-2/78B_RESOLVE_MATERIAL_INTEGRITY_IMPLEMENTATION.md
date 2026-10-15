# Report 78B: Resolve Material Integrity Implementation

**Baseline:** `78_RESOLVE_MATERIAL_IMPLEMENTATION_AUDIT.md` (verdict REFINE).
**Date:** 2026-10-14 · **Environment:** development only.
**Not done:** no commit, push or deploy; the dev database was not migrated; no historical record was repaired, reversed, deleted or revalued; no authorization was granted.
**Evidence:** `78b-evidence/`, which holds the 78B-only patch, the read-only exception register (script, 6 CSVs and `summary.json`). Test logs are summarised in §8.

## Verdict

**78B COMPLETE — RESOLVE MATERIAL INTEGRITY VERIFIED IN CODE**

The three conditions the brief sets for COMPLETE are closed in code and pinned by tests that fail when the guard is removed (§8.3):

| Condition | Closed by | Proven by |
|---|---|---|
| Identity loss | `MaterialLineIdentity::effectiveId` (stale screens and restored drafts cannot null an identity); removal guard on lines and elements; force-delete guard; resolution bumps the list's version stamp | stale-save, restored-draft, version-conflict, removal and force-delete tests |
| Issued-material relinking | `MaterialLineIdentity::assertCanBecome` in both the BOM writer and Resolve | substitution-refused and lost-link tests |
| Incorrect unit-based issue quantities | `RequirementUnits`, used by every requirement-vs-stock comparison (issue cap, board requests, link correction, demand, desk) | conversion-cap, standard-metric and legacy-incompatible tests |

"Verified in code" does not mean "ready to switch on". §10 lists operational go-live blockers that are data and governance work, not code: 117 approved lines that will now refuse issue until their units are corrected, movements 78/79, and the dev-database migration.

---

## 1. Before / after workflow

| Step | Before (Report 78) | After (78B) |
|---|---|---|
| BOM save of a resolved line from a stale screen or restored draft | `library_material_id` overwritten with `null`; approval stayed green | Stored identity kept. A screen opened before the resolution gets a 409 version conflict. |
| BOM change of identity or unit | Not detected; both approvals kept | Detected by persistent id; approvals reset; the superseded approvals are filed in `approval_history` |
| Change identity, or remove a line, with stock issued against it | Allowed | Refused (422) until the stock is returned or reversed |
| Resolve: unit check | None | Server verdict: same unit / alias / standard / approved material conversion / **incompatible (refused, with reason)** |
| Resolve: stock receipt | Optional receipt + GL journal in the same call | `receive` is prohibited (422). Receipts go through Receive Stock, where `stores.review` applies. |
| Resolve: "Create new" | Active library item, no duplicate check, linked at once | **Request new material**: exact duplicate refused; near duplicates must be acknowledged; item born **Under Review**; line parked as *awaiting registration*; linked only after the custodian activates it |
| Genuine one-off purchase | Had to become a library item | **Non-stock / direct purchase** disposition: no inventory item, no stock demand, bought by requisition |
| Downstream | Budget, procurement task and planned cost lines stale until the next BOM save | `MaterialsListChanged` dispatched after commit; open, unordered requisition lines re-linked when the unit reads 1:1; otherwise reported for the buyer |
| Issue cap, board-request cap, link correction, demand | BOM number compared with stock-unit number | Requirement read in stock units (`quantity × approved factor`); unreadable requirement refused |
| Re-resolution history | Single JSON stamp, overwritten | `catalogue_resolutions[]` appended (original description, quantity and unit, unit basis, resolver, time, note) |
| Closed job / excluded line | Not checked | Refused |

Resolution now has exactly one effect: it writes identity, its unit basis and its audit trail onto the requirement. It creates no inventory movement, GRN, journal, supplier bill, purchase order or project cost (test `a_resolution_posts_nothing_and_announces_the_list_change` counts all of them).

## 2. Files and functions modified

A 78B-only patch is in `78b-evidence/78b.patch`. Other sessions' uncommitted work in the same files is excluded; the patch was diffed against a pre-78B snapshot.

**Backend: new**
- `app/Services/MaterialLineIdentity.php`
  - `effectiveId`: the one rule for what identity a save leaves.
  - `changes`
  - `openFulfilment`: net issued stock per material, plus open board requests.
  - `assertCanBecome`
  - `assertRemovable`
- `app/Modules/ProcurementStores/Services/RequirementUnits.php`: the UoM policy.
  - `normalise`
  - `assess`
  - `requiredInBase` / `requiredInBaseOrFail`
  - `snapshot`
- `app/Modules/ProcurementStores/Services/ProjectMaterialResolution.php`
  - `resolve`
  - `requestNewMaterial`
  - `classify`
  - `compatibility`
  - `assertOpenForStores`
  - `reconcileRequisitions`
- `app/Modules/ProcurementStores/Controllers/ProjectMaterialResolutionController.php`: the four endpoints below.
- `app/Modules/MaterialsLibrary/Services/MaterialDuplicateFinder.php`: exact and near duplicates, with a `spec_differs` flag.
- `database/migrations/2026_10_14_000001_report_78b_requirement_identity_and_units.php`

**Backend: changed**
- `app/Services/ProjectElementRegistry.php`
  - `saveLines`: identity via `MaterialLineIdentity`; unit snapshot cleared on unit or identity change; removal guard; server-owned metadata keys survive a client save.
  - `saveMaterials` and `syncScope`: element-removal guard.
- `app/Http/Controllers/MaterialsController.php`
  - `generateChangeSummary`: rewritten to match by persistent id and compare description, quantity, unit, inclusion and effective identity.
  - `materialsChangedSinceVersion`: also compares unit and identity.
  - `saveMaterialsData`, version restore and quote import: preserve `approval_history`.
  - `formatMaterialsData`: serves `libraryMaterialIdBaseline`, `requirementDisposition`, `requestedMaterial`, `requirementUnit` and stock-unit fulfilment.
- `app/Modules/ProcurementStores/Services/StockMovementPoster.php`
  - `assertProjectLineCanTake` and `legacyIssuedAgainst`: stock units.
- `app/Modules/ProcurementStores/Services/ProjectMaterialDemand.php`
  - `fulfilmentForProject` and `pendingLines`: stock units; unreadable lines are not counted.
- `app/Modules/ProcurementStores/Controllers/BoardRequestController.php`: board cap in stock units.
- `app/Modules/ProcurementStores/Controllers/ProcurementStoresController.php`
  - `linkProjectMaterial`: cap in stock units.
  - `resolveProjectMaterialCatalogue`: removed (moved to the new controller).
- `app/Modules/MaterialsLibrary/Controllers/MaterialController.php`
  - `forceDelete`: refused while BOM lines reference the item.
- `app/Models/ElementMaterial.php`: new fillables and casts, `effectiveDisposition()`, `requestedMaterial()`.
- `app/Modules/ProcurementStores/Routes/api.php`

**Endpoints** (`/api/procurement-stores/project-materials/{id}/…`, all require `stores.manage`)

| Method | Path | Purpose |
|---|---|---|
| POST | `resolve-catalogue` | Match (`material_id`) or request (`new_material` + `request_reason` + `acknowledged_candidates`). `receive` is prohibited. |
| GET | `compatibility?material_ids[]=` | The server's unit verdict per candidate |
| GET | `registration-candidates?name=&category_id=` | Exact and near duplicates |
| POST | `disposition` | `non_stock` or `awaiting_resolution`, with a reason |

**Frontend**
- `procurement-stores/components/ResolveMaterialModal.vue`: rewritten (see §7).
- `procurement-stores/utils/rankCatalogue.ts`: word and size ranking for the prefilled search.
- `procurement-stores/views/stores/ProjectMaterialsDesk.vue`
  - Rows in stock units, with the BOM figure shown alongside.
  - States: unit-blocked, non-stock and awaiting registration.
  - Non-stock rows get a draft requisition and a *return to resolution* action.
  - The modal is opened with the project reference.
- `projects/services/materialsService.ts`, `projects/components/MaterialsModal.vue`, `projects/components/tasks/MaterialsTask.vue`: round-trip `libraryMaterialIdBaseline` untouched.

## 3. Database changes

There is one additive migration and no data is rewritten. New columns on `element_materials`, all nullable:

| Column | Meaning |
|---|---|
| `requirement_disposition` varchar(30) | Explicit Stores classification of an unlinked line: `non_stock` or `awaiting_registration`. NULL means derived: resolved when linked, otherwise awaiting resolution. |
| `requested_material_id` FK → `library_materials` (nullOnDelete) | The Under Review item a request created. It is not the line's identity. |
| `requirement_uom_id` FK → `units_of_measure` | The normalised BOM unit, at resolution |
| `requirement_base_uom_id` FK → `units_of_measure` | The material's stock unit, at resolution |
| `requirement_base_factor` decimal(18,6) | BOM unit → stock unit |
| `requirement_unit_basis` JSON | `basis`, `material_id`, `conversion_id`, from/to, factor, `approved_by`, `approved_at` |

- FK names are within MariaDB's 64-character limit.
- Approval history lives in `task_materials_data.project_info.approval_history` (JSON, server-owned).
- Resolution and disposition history live in `element_materials.source_metadata` under `catalogue_resolutions[]`, `material_request` and `disposition_history`. A materials-list save never removes these keys.
- **The dev database `db` is not migrated** (§10). The scratch test database was migrated by the test harness.

## 4. UoM conversion policy

Implemented once, in `RequirementUnits`, and used by every place a requirement meets stock.

1. **Normalise** the BOM unit text to a `units_of_measure` row. This matches by code or name, plus spelling-only aliases: `Mtrs/metre/meter → m`, `Kgs → kg`, `Ltrs/litre → l`, `sqm/sq m → m2`, `Boxes → box`, `Sheets → sheet`, `Packets/pkt → pkts`, `Cans → can`, and so on.
   - Two different units are never aliased. `Packets` (pkts) is not `pack`.
   - Unrecognised text (`Fits`, `Bouquet`, `cm`, `Ft` — anything absent from the registry) cannot be read.
2. **Same unit or alias:** factor 1, quantity unchanged.
3. **Standard metric within one dimension:** `mm↔m`, `g↔kg`, `ml↔l`, using the fixed factor.
4. **Anything dimensional or package-based** (area→sheet, roll→metre, packet→kg, litre→can) is accepted **only** if the material carries an approved conversion in the Material Catalogue (`material_uom_conversions`, maintained by `materials_library.manage`). The factor and its basis are snapshotted on the line, so a later catalogue edit cannot change what was approved.
5. **Otherwise incompatible.** Resolution is refused with the reason and the remedy: add a conversion, correct the BOM unit, or choose a material stocked in the BOM unit. Issue, board request and link correction are refused the same way.
6. **What is never changed:** the BOM quantity and unit are never rewritten. Stock, issue, valuation and cost stay in the material's base unit (the issue quantity already was; now the requirement is read in it too).

Example: 12 m² of MDF linked to an 8×4 sheet with an approved conversion of 0.335938 sheet/m² means **4.03 sheets**. Issuing 5 is refused; issuing 4 posts. This is covered by test.

**WNG decision (open):** whether Stores, in addition to the custodian, may supply a one-off dimensional factor at resolution time. Today it must be on the material, which keeps one source for purchasing and issue.

## 5. BOM approval and identity protection

- **Identity:** each line is served with `libraryMaterialIdBaseline`. A save may change an established identity only if its baseline equals the stored identity.
  - A screen opened before a resolution sends a different baseline.
  - A draft restored from before it does the same.
  - An older client sends none.
  - In all three cases, `null` keeps the stored identity.
- **Version stamp:** resolution also touches `task_materials_data.updated_at`, so an open Materials screen gets the existing 409 *reload* conflict. This is defence in depth. The baseline rule alone protects restored drafts, which carry a fresh stamp.
- **Fulfilment guard:** an identity change (substitution or unlink) is refused while the line has net issued stock or an open board request of any other material. So is removing the line or its element.
  - The controlled path is the existing return / Finance-reversal flow first.
  - After that, the issue history stays with the material actually issued, and the line can be relinked.
  - A line whose link was lost before 78B can only be re-resolved to the material that was issued.
- **Approval:**
  - Change detection now matches lines by persistent id. It treats description, quantity, unit, inclusion and identity changes as material changes, so both approvals reset.
  - The approvals being replaced are filed in `approval_history` with the change list. Save, version restore and quote import all do this.
  - The re-approval reason gate also compares unit and identity against the base version.
  - Stores resolving an unlinked line (null → X) is a permitted metadata update and does not reset approval. It is recorded in `catalogue_resolutions`, and a later re-approval will ask the Project Officer for a reason, because the line differs from the base version.
- **Force-delete:** a library item referenced by any BOM line cannot be force-deleted, because the FK would null the lines.

## 6. Non-stock treatment

- Stores classifies an unlinked line as **Non-stock / direct purchase**, with a reason.
  - No library item is created.
  - The line leaves the *unlinked* queue.
  - It is shown as *Direct purchase*, with **Draft requisition** using the existing requisition flow with `custom_description`. Cost reaches the job through the existing requisition / direct-bill path.
- It cannot appear in Stores stock or replenishment: demand counts only linked lines, and nothing is created in the catalogue (test).
- It can be returned to *awaiting resolution*. Both moves are recorded in `disposition_history`.
- A linked line cannot be made non-stock.
- The four dispositions are derived by `ElementMaterial::effectiveDisposition()`:
  - **Inventory — Resolved:** linked.
  - **Inventory — Awaiting Resolution:** unlinked, no classification.
  - **New Material — Awaiting Registration:** request parked on an Under Review item.
  - **Non-Stock / Direct Purchase**

## 7. Downstream integration results

| Consumer | Result |
|---|---|
| Budget / procurement task / planned cost lines | `MaterialsListChanged` dispatched after commit. The existing listener chain copies `library_material_id` (test asserts the dispatch). |
| Material demand / replenishment | Stock units. The line counts once after resolution (it never counted before), so there is no duplicate demand. Unreadable lines are excluded, not guessed (test). |
| Open requisitions | Only lines in `draft` / `pending_approval` / `approved` with **no PO line**, matched by `budget_item_persistent_id` and job, get `material_id`, and only when the unit reads 1:1. Otherwise they are reported as `needs_review`. No requisition is created; ordered, received and billed lines are untouched (tests). |
| Issued quantities and cost history | Untouched. Movements are never rewritten. |
| Desk | Stock-unit requirement and shortage; unit-blocked rows show the reason and cannot be issued. |
| **Resolve modal** | Project and job reference; BOM line number, description, quantity and unit; search prefilled from the description and ranked by shared words and sizes; per-result server verdict (✓ same / ↔ converts ×f / ✕ incompatible, disabled with its reason); code, category, specifications, library stock unit and factor; required and on-hand in comparable units; "when you confirm" list of what it will and will not do; Request new and Non-stock tabs. |

## 8. Tests and failures

All backend runs used an isolated scratch database (`db_r78_resolve_test`). The shared `db_test` and the dev `db` were never written; I checked afterwards that neither has the 78B columns.

### 8.1 New and changed suites

| Suite | Result |
|---|---|
| `tests/Feature/Stores/ResolveMaterialIntegrityTest.php` (new, 30 tests) | 30 passed |
| `tests/Feature/Stores/ResolveProjectMaterialCatalogueTest.php` (13 tests; 6 rewritten to pin the new contract) | 13 passed |
| `tests/Feature/Stores/ResolveMaterialConcurrencyTest.php` (new; forked processes, real commits) | 1 passed: 4 simultaneous resolvers, 1 winner, 1 history entry, 3 refused "already linked" |
| Frontend `resolveMaterialModal.spec.ts` (new, 3) and `utils/rankCatalogue.spec.ts` (new, 4) | 7 passed |

Integrity suite coverage, against brief Phase 9:
- stale saves (no baseline, stale-baseline draft, 409 conflict)
- concurrent edits (version conflict, forked race)
- approval resets and history (unlink, unit change, unchanged save keeps approval)
- issued-material relinking (substitution refused, lost link restorable only to the issued material)
- line removal with and without issued stock
- valid and invalid UoM (alias, standard, material conversion with issue cap, incompatible refusal, legacy incompatible issue refusal, compatibility endpoint)
- demand in stock units
- duplicate registration (exact refused, near must be acknowledged, Under Review until the custodian activates through the real `activate` endpoint)
- non-stock classification and reversal; linked line cannot be non-stock
- downstream synchronisation (event, requisition re-link, ordered and rejected requisitions untouched, other-unit requisition left for review)
- closed job, excluded line, repeated request, unauthorised access on all four endpoints
- accounting non-interference: counts of `inventory_logs`, `journal_entries`, `cost_lines`, `stores_finance_postings`, requisitions, POs, GRNs and bills are unchanged after a resolution
- force-delete guard

### 8.2 Regressions

| Command (with `-c phpunit.r78b.xml`) | Result |
|---|---|
| `ddev exec php artisan test tests/Feature/Stores tests/Feature/Procurement tests/Feature/Projects tests/Feature/MaterialsLibrary tests/Feature/CostCollector tests/Feature/Finance` | **1,560 passed, 6 failed** (11,162 assertions, 1,343 s). All 6 failures are pre-existing forked race tests (`Report76A3BillingConcurrencyTest` ×1, `W7LabourConcurrencyTest` ×5) refusing to run because the scratch database is not named `db_test`/`db_scratch_test`; none ran any code. Re-run on a database named `db_scratch_test`, together with the 78B suites: **37 passed** (205 assertions). **Net: zero regressions.** |
| `npx vitest run src/modules/procurement-stores src/modules/projects src/modules/materials-library` | 16 files, 108 tests passed (before the modal spec); 19 files, 115 with the new specs |
| `npx vue-tsc --noEmit -p tsconfig.app.json` | 267 errors after vs 268 before, on an identical snapshot comparison. **Zero new errors**; every difference is a path string, plus one fixture absent from the snapshot. |

### 8.3 Mutation check

To show the tests guard the defects rather than pass by construction, I reverted two guards temporarily and confirmed the tests failed:

| Guard reverted | Tests that failed |
|---|---|
| `effectiveId` returns whatever the client sends | stale-save, restored-draft |
| Issue cap uses the raw BOM quantity | material-conversion cap, standard-metric cap |

Both guards were restored and verified (no `MUTATION` marker remains).

### 8.4 Failures met along the way (all fixed, none in shipped behaviour)

- The race-test fixture lacked `classification`.
- The custodian-activation test was first written to tolerate a fallback; it was tightened to require the real `activate` endpoint and passes.
- One regression run was stopped and restarted after the removal guard was added mid-run, so its figures were not mixed.

## 9. Historical exceptions (read-only register)

Generated by `78b-evidence/exception_register.php` against dev `db`. It runs inside a rolled-back transaction and uses the same `RequirementUnits` policy the code enforces. Nothing was repaired, reversed, deleted or revalued.

| Register | Count | File |
|---|---|---|
| Linked lines whose unit text differs from the library | **233** of 667 | `01_…csv` |
| — harmless alias (`mtrs→m` 28, `kgs→kg` 24, `boxes→box` 22, `ltrs→l` 7, …) | 84 | |
| — approved material conversion | 1 | |
| — **real incompatibility** (`pcs→m` 31, `packets→kg` 24, `sqm→m` 17, `ltrs→can` 9, `kgs→m` 9, …) | **148** (117 on approved lists; 2 with issues) | |
| Movements 78 and 79 | 2 | `02_…csv` |
| Approved, unresolved lines | **4,965** across 368 jobs (3,198 on completed/cancelled jobs; 68 with an unrecognised unit) | `03_…csv` |
| Desk resolutions with issue history | 1 (line 41770) | `04_…csv` |
| Issues whose material differs from their line | 0 | `05_…csv` |
| Duplicate library groups (exact name, ignoring case and order) | 8 groups / 22 items | `06_…csv` |

### Proposed corrections, with financial impact

**Movement 78** (line 41770, resolved via the desk).
- *What happened:* 1 **box** of *Drywall Screws 3.5x55mm* was issued against *"Screws 40mm, 1 Packet"*. The finance posting is **posted**: cost line 6, **KES 500.00**, verified, journal entry 5.
- *Proposed correction:* Stores confirms what physically left the store.
  - **Right item** (the screws used were these): no financial change. Record a material conversion (packet → box) or correct the BOM unit, so the line reads correctly.
  - **Wrong item:** return it through the existing return flow (this posts the credit and restores stock), then relink and reissue the correct material. The KES 500 reverses with the return.

**Movement 79** (line 41769, dropdown path).
- *What happened:* 8 **m** of *Matt white sticker* was counted against a 14 **m²** line. The finance posting is **failed** (no cost line or journal), which was already in the finance exception queue.
- *Proposed correction:* add the roll-width conversion m² → m to the material. For example, a 1.27 m roll gives 0.7874 m per m², so 14 m² = 11.02 m and the 8 m issued sits within it. Then clear the finance exception through the existing valuation/retry path. Financial impact: 8 m at the material's unit cost, not yet booked.

**148 real incompatibilities.**
- *Proposed correction:* per line, one of:
  1. add an approved conversion on the material;
  2. correct the BOM unit (approval resets);
  3. relink to a material stocked in the BOM unit, if nothing has been issued.
- *Financial impact:* none until issued. The 2 issued are movements 78/79 above.

**84 aliases:** no action. They read 1:1.

**4,965 unresolved approved lines:**
- 3,198 are on completed/cancelled jobs. **Proposed:** leave them. Resolution now refuses closed jobs.
- The remaining ~1,767 on open jobs go to Stores to resolve, request or classify as non-stock.
- 68 carry an unrecognised unit (e.g. `Fits`, `Bouquet`, `cm`). Projects correct those units first.

**8 duplicate groups** (e.g. *Starbits* `pcs` with 155 linked lines vs `pkts` with 0; *Masking Tape 24mm* `pcs` vs `roll`; *Paint Brush 4 inch* ×4; *Squegee/Squegees* ×7). **Proposed:** the library custodian merges or deactivates the unused copies. Where units differ, decide the true stock unit first. No financial impact; the unused copies hold no stock.

## 10. Remaining risks and go-live blockers

**Go-live blockers (operational, not code):**
1. **Run the migration** `2026_10_14_000001_report_78b_requirement_identity_and_units` before this code serves traffic. Without the columns, BOM saves and resolutions fail.
   - On dev, use only this file: `ddev exec php artisan migrate --path=database/migrations/2026_10_14_000001_report_78b_requirement_identity_and_units.php`. A plain `migrate` would also apply other sessions' pending migrations.
   - A push to `master` deploys and migrates unattended.
2. **117 approved, linked lines will refuse issue** until their unit is corrected or a conversion is added. This is the intended behaviour of the brief, but Stores will meet it on day one. Work the `01_…csv` register before cut-over.
3. **Movements 78 and 79** need the Stores and Finance decision above.

**Governance decisions for WNG:**
- **Segregation on new materials.** In dev, the `Stores` role also holds `materials_library.manage`, so one Stores user can request and activate the same item. 78B grants nothing new. Whether requester ≠ activator should be enforced, or the grant narrowed, is WNG's call (like the approver ≠ payer decision in 75R-C).
- **Who may supply a one-off dimensional factor.** Today only the Material Catalogue holds them (§4).
- **The 3,198 unresolved lines on closed jobs:** leave as history (recommended) or archive.

**Residual technical risks:**
- **Unlinking is still possible on purpose.** A client that saw an identity may deliberately unlink an *unissued* line (approval resets and it is recorded). That is a requirement change, not silent loss.
- **Quote re-import (`force`) is now refused** when a line it would drop has stock out. This is intended, but it changes a previously permissive flow.
- **The alias table is in code** (`RequirementUnits::ALIASES`). Adding a unit spelling is a code change until WNG wants it data-managed.
- **Desk row figures** follow the server's unit verdict. Movements posted before 78B on incompatible lines (movement 79) stay in the issued column in stock units, while the requirement shows in the BOM unit until the line is corrected.
- **Not built:** fully automated relinking of requisition lines whose unit differs (left for the buyer); a requester ≠ activator check (governance decision); Material Library UI changes to show where a request came from (the request record is on the line, in `material_request`).
