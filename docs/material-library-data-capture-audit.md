# Material Library Data-Capture and Classification Audit

## Executive conclusion

The current implementation has a useful foundation, but it is not yet a dependable master-data system. The main form, import process, database validation, category taxonomy, and downstream stores behaviour do not enforce the same rules.

The most important design correction is to stop treating these as one classification:

1. **What the item is** — category and subcategory (MDF board, solvent ink, drill bit).
2. **How stock is controlled** — bulk quantity, batch/lot, individually serialized, or dimension/offcut tracked.
3. **What happens when issued** — consumed, returnable, or recoverable remainder.
4. **Whether it is a fixed/controlled asset** — asset-register policy, ownership, custodian and lifecycle.

`consumable` versus `reusable` is currently doing too much. It describes expected return, indirectly selects inventory behaviour, and—when combined with a category name—activates individual board tracking. These should be explicit, governed fields.

## Scope and evidence reviewed

This audit covers:

- Materials Library database model, API validation, CRUD, normalized/legacy categories, category seed data, SKU generation, workstation schemas, form behaviour, imports and exports.
- Procurement & Stores use of `material_type`, `usage_type`, `stock_handling`, individual boards, offcuts, check-out and returns.
- The separate Assets module: asset categories, registration fields, imports, assignment/hire, condition and lifecycle.

The repository's configured live database is a MariaDB service named `db`, which was not accessible from this environment, and the local SQLite file has no material tables. Therefore, “current materials” below means the implemented taxonomy and supported material families, not a statistical profile of live production rows. A production data-quality query pack is included later so the live catalogue can be profiled safely.

## Current implementation

### Material identity

`library_materials` holds:

- workstation, globally unique material code, name;
- legacy category and subcategory strings;
- normalized `material_category_id`;
- `material_type` (`consumable` or `reusable`);
- UOM, unit cost, flexible JSON attributes, active flag and notes.

Stock quantities live separately in `stocks`. The API derives `stock_handling` rather than storing it:

- eligible category + reusable = `individual_board`;
- other reusable = `reusable_item`;
- otherwise = `quantity`.

This derivation is sensible as a compatibility layer, but too implicit for master-data governance.

### Capture routes

There are two materially different registration routes.

**Single-entry form**

- requires workstation, SKU and UOM in the UI;
- presents a two-level normalized category picker;
- auto-generates a workstation/category-based SKU;
- silently forces all items under Boards, Sheet Materials or Veneer into the UI-only “Board Sheet” type;
- maps Board Sheet to backend `material_type = reusable`;
- requires board thickness in the UI and defaults board length/width to 2440 × 1220 mm;
- loads extra fields by workstation rather than by material category.

**Excel import**

- requires only SKU and material name; missing UOM becomes `-`;
- writes category/subcategory strings only, not `material_category_id`;
- does not capture or infer `material_type`;
- does not validate categories against the taxonomy;
- does not enforce board dimensions/thickness;
- sends every unknown column into JSON attributes;
- updates any material having the same global SKU, even when uploaded against another workstation;
- commits valid rows while reporting invalid rows, which is practical, but provides no preview or quality warnings.

The export “template” is CSV while the import UI emphasizes XLS/XLSX. Its columns also do not include normalized category identifiers, material type or handling mode. Several stock-control columns in templates become generic JSON attributes and do not update the `stocks` table.

### Categories presently represented

The seeded two-level material taxonomy includes:

- Boards: MDF, plywood, PVC foam board, chipboard, blockboard.
- Sheet Materials: acrylic, ACP, aluminium composite, Forex PVC, polycarbonate, Correx.
- Veneer: wood veneer, melamine veneer, HPL laminate.
- Printing Media: vinyl, banner/mesh, canvas/fabric, backlit film, one-way vision.
- Inks & Coatings: solvent/UV/latex inks, primers/sealers, spray paints, clear coats.
- Adhesives & Laminates: mounting/contact adhesives, double-sided tapes, laminating film.
- Metals & Profiles: steel sections, aluminium profiles, welding consumables, cutting discs.
- Electrical & LED: modules, strips, power supplies, controllers, wiring/cables.
- Hardware & Fasteners: screws/bolts, hinges/brackets, standoffs/fixings, rivets/clips.
- Packaging & Dispatch: stretch film, foam padding, boxes, packing tape.
- Cutting Tools: router bits, laser lenses, drill bits, blades/knives.
- Timber & Wood: solid/treated timber, dowels/mouldings.

Workstation is also used as a grouping dimension: CNC, laser, large-format print, UV, metal fabrication, carpentry, paint, LED and general hardware. A material can naturally be used by several workstations, but the current model permits only one workstation owner.

### Assets are a separate register

Assets have their own category tree and fields for tag, ownership, custodian, department, location, manufacturer, model, serial, specifications, quantity, status, condition, purchase values, supplier, warranty and service dates. Assignment and short-term hire/return history are also implemented.

This separation is correct. A drill bit, controller, clamp or jig can be returnable inventory without qualifying as a fixed asset. Conversely a laptop, printer, machine or vehicle is an asset even though it is reusable. Asset classification should be based on control/value/useful-life policy, not on the material `reusable` flag.

## Material-family assessment

| Family | Normal issue behaviour | Recommended stock handling | Essential distinguishing fields | Current concern |
|---|---|---|---|---|
| MDF/plywood/chipboard/blockboard | Cut and partly recoverable | Individual sheet + dimensions/offcuts | grade, thickness, length, width, finish/face, density, supplier product | Entire parent is forced “board”; valid, but “reusable” is misleading because used area is consumed |
| Acrylic/ACP/PVC/polycarbonate/Correx | Cut and sometimes recoverable | Individual sheet only when offcuts are controlled; otherwise quantity sheets | material/substrate, thickness, dimensions, colour, finish, grade, UV/fire properties | Every sheet category is forced into QR board fleet whether economically justified or not |
| Veneer/HPL | Usually applied/consumed; offcut recovery depends on size/value | Area/roll/sheet quantity, optionally remainder tracking | substrate/type, thickness, sheet/roll dimensions, finish, colour/pattern, adhesive backing | Wood and melamine veneer default reusable solely because of parent; HPL is marked consumable but the form will still force it to board |
| Roll media | Consumed by length/area; remainder may remain on roll | Lot/roll with remaining length | width, roll length, media technology, GSM/micron, finish, colour, adhesive/liner, compatibility, batch | UOM “Mtrs” alone loses roll identity, width and remaining roll; cost-per-roll and cost-per-sqm can overwrite one `unit_cost` during import |
| Inks, coatings, adhesives, paint | Consumed, batch and shelf-life sensitive | Lot/batch quantity | chemistry/base, colour, pack size, batch, manufacture/expiry, hazardous storage, coverage, compatible machine | No batch/expiry/hazard model; litres and container cost are easily confused |
| Steel/aluminium profiles/timber | Cut/consumed; valuable remnants sometimes recoverable | Length/piece plus optional offcut tracking | profile/section, grade/alloy/species, dimensions/gauge, stock length, finish | Taxonomy does not distinguish profiles, sheets, tubes, bars; reusable offcuts unsupported outside boards |
| Fasteners/electrical components | Installed/consumed | Quantity, optionally pack conversion | size, standard/rating, material, finish, brand; for electrical: V/W/IP/colour temp | Generic category is too broad for duplicate prevention and purchasing accuracy |
| Cutting tools and lenses | Returnable but wear-limited | Serialized for high value, quantity for low value; usage/service tracking | diameter/size, interface, compatible machine, material, flute/tooth count, condition, life/usage | All are default reusable but only aggregate quantity is tracked; no unique tool identity or wear state |
| Controllers/power supplies | Depends on whether installed in job or held as test/loan equipment | Policy-dependent: consumed installation part or serialized returnable | model, voltage, current/power, channels, protocol, IP, serial where controlled | Category default assumes all controllers reusable and all PSUs consumable; business use determines the answer |
| Packaging | Consumed | Quantity/roll | dimensions, grade, capacity, pack size | Mostly adequate, but pack/base-unit conversions are absent |

## Key defects and risks

### Critical

1. **Form/import parity is broken.** Imports can create uncategorized, default-consumable boards with no board specifications. Such records will not use the board lifecycle.
2. **Category determines behaviour by mutable English names.** Board tracking depends on exact root names (`Boards`, `Sheet Materials`, `Veneer`). Renaming a category can change operational behaviour for existing records.
3. **Parent-wide board forcing is incorrect.** HPL is declared consumable in frontend intelligence but is forced to Board Sheet because its parent is Veneer. The same contradiction affects materials where recovery is uneconomic.
4. **Backend validation is weaker than UI validation.** Category and material name are nullable; normalized leaf category is optional; board dimensions and logical type/category combinations are not enforced server-side.
5. **No canonical duplicate identity.** A unique SKU prevents duplicate codes, not duplicate materials. “MDF 18mm 2440x1220”, spelling/case variants and the same material under different workstations can coexist.

### High

6. **One workstation per material duplicates shared items.** MDF can serve CNC, UV and carpentry; acrylic can serve laser, UV and CNC. Workstation should be a many-to-many usage/compatibility relationship, not catalogue identity.
7. **UOM is uncontrolled text.** The UI list is not enforced by API/import and mixes plural abbreviations, area, packages and base units. No conversion model exists (box → pieces, roll → metres, can → litres).
8. **JSON field definitions drift.** Examples, filters, workstation schemas and import headers use different keys (`thickness`, `thickness_size`, `thickness_mm`; `sheet_size`, `sheet_size_dimensions`, `standard_length_mm`). This makes search and reporting unreliable.
9. **Costs lack a basis.** A single unit cost cannot safely represent cost per roll, sheet, metre, square metre or container without an explicit purchase UOM and conversion factor.
10. **Type can be overridden at transaction time.** Check-out forms expose `usage_type`; this can disagree with the material master and corrupt outstanding-return calculations.
11. **SKU suggestion is race-prone and sequence logic is misleading.** It counts current prefix records and then searches forward. Concurrent requests can suggest the same code; soft-deleted codes remain protected by DB uniqueness but are excluded from count.
12. **Changing category/type after transactions can reinterpret an item.** There is no governed transition or block when stocks, logs or boards exist.

### Asset-register concerns

13. **Asset categories mix several dimensions.** Examples overlap: `ICT` vs `IT & Digital Devices`, `Printing` vs `Printing Dept.` vs `Office Printing`, and `Workshop Machinery` vs `Production`. Departments and categories are mixed.
14. **Manual asset creation requires an assignee.** Newly received, unassigned, spare or warehouse assets cannot be captured truthfully unless a placeholder person is chosen.
15. **Asset `qty` conflicts with unique asset identity.** A serialized/tagged asset should normally be one physical unit. Bulk identical non-serialized equipment needs a separate quantity-controlled equipment model or one record per tag.
16. **Asset import auto-creates categories from spelling variants.** This is convenient but will multiply near-duplicate categories without a review/alias queue.
17. **No explicit capitalization/control policy.** The system cannot explain when a reusable material/tool must become an asset.

## Recommended classification model

Use independent fields with controlled values:

### 1. Product category

Store a stable category FK. Give each category an immutable code and configurable metadata. Category answers “what is it?” only.

Recommended hierarchy (maximum three levels):

- Substrates
  - Wood-based board: MDF, plywood, chipboard, blockboard
  - Plastic sheet: acrylic/PMMA, PVC foam, polycarbonate, Correx/PP
  - Composite sheet: ACP
  - Decorative surfacing: HPL, wood veneer, melamine veneer
- Print media
  - Self-adhesive vinyl, banner/mesh, backlit, one-way vision, canvas/textile
- Chemicals
  - Inks, paints/coatings, primers/sealers, adhesives, cleaners/solvents
- Metals
  - Sheet/plate, tube, hollow section, angle/channel, bar/rod, aluminium extrusion
- Timber
  - Sawn timber, treated timber, dowels/mouldings
- Electrical and lighting
  - LED modules/strips, drivers/power supplies, controllers, cable, connectors
- Hardware
  - Fasteners, hinges/brackets, standoffs/fixings
- Tooling and spares
  - Cutting tools, machine consumables, machine spares, measuring/holding tools
- Packaging
  - Film, tape, boxes, padding/protection

Do not embed department/workstation, return behaviour or accounting class in this tree.

### 2. Issue disposition

- `consumed` — expected to be used/incorporated, no return obligation.
- `returnable` — issued unit must be returned and reconciled.
- `recoverable_remainder` — issued stock is consumed by size/length but viable remnants are returned.

This better describes boards than calling the whole sheet reusable.

### 3. Tracking mode

- `bulk_quantity` — homogeneous quantity.
- `lot_batch` — batch/expiry/roll control.
- `serialized_item` — each tool/equipment unit has an identity.
- `dimension_piece` — each sheet/offcut/length has dimensions and lineage.

The category may suggest a default, but an authorized user should confirm it and the backend must validate compatible combinations.

### 4. Asset-control class

- `inventory_only`
- `controlled_low_value_equipment`
- `fixed_asset`
- `client_owned_asset`

Define the threshold/policy outside code (useful life, value, risk, serial/control requirement). A fixed/client asset belongs in the asset register; controlled tools may stay in stores but use serialized tracking. Avoid duplicating one physical item in both systems; link records when procurement catalogue identity and asset instance are both needed.

## Robust capture design

### Common required fields

- normalized leaf category;
- clear item name generated from approved identifying attributes;
- SKU (system-generated and immutable after transactions);
- base stock UOM;
- purchase UOM and conversion to base UOM where different;
- issue disposition and tracking mode;
- active/inactive status;
- owning store, plus zero or more compatible workstations;
- preferred/default specification values required by category.

Supplier and cost should ideally be supplier-item records with effective dates, rather than permanent identity fields on the material.

### Category-driven specification templates

Move field definitions from workstation switches into database/configured **category templates**. Each definition should specify key, label, data type, requiredness, allowed values, unit, validation range and whether it contributes to duplicate identity/name.

Examples:

- Board/sheet: thickness mm, standard length mm, standard width mm, grade, colour, finish.
- Roll media: width mm, roll length m, GSM/micron, media technology, adhesive, liner, finish.
- Chemical: pack volume/weight, chemistry/base, colour, batch controlled, expiry controlled, hazard class.
- Profile/timber: cross-section dimensions, gauge, grade/species, stock length, finish.
- Electrical: voltage, current/power, IP rating, colour temperature, protocol.
- Cutting tool: tool type, diameter, flute/tooth count, shank/interface, compatible machines.

Store frequently filtered fields in typed columns or a typed attribute-value design; if JSON remains, enforce a single flat shape and canonical keys.

### Registration workflow

1. Select category (searchable tree with synonyms).
2. System loads category-required specifications and suggested defaults.
3. User selects intended operational use; system proposes disposition/tracking, with explanation.
4. Select base/purchase/issue UOM and conversions.
5. Enter identity attributes; system shows possible duplicates before save.
6. System generates canonical name and reserves SKU atomically.
7. Add supplier/cost, storage, reorder and compliance details in a second “stock setup” step.
8. Review summary showing how the item will behave at receipt, issue and return.

Use progressive disclosure: registration should show 6–10 essentials, then category-specific fields; advanced procurement/store settings should not overwhelm initial entry.

## Alignment to the proposed central ERP SKU master

The proposed `erp_sku_master` makes the intended destination much clearer: Materials Library should become an enterprise item master rather than a production-only catalogue. That direction is correct. It should be the shared definition used by requisitions, procurement, goods receipt, stores, production issues/recoveries and finance mappings.

The draft is a strong conceptual inventory of required data, but it should not be implemented as one wide table exactly as written. Several fields describe a SKU globally, while others describe a supplier offer, a warehouse location, a stock balance, a cost calculation, or a physical instance. Combining them will reintroduce inconsistency when one SKU has several suppliers, locations, batches, dimensions or costs.

### Correct system boundaries

| Concern | Recommended owner | Reason |
|---|---|---|
| SKU identity, classification and standard technical specification | `items` / SKU master | Stable definition of what is bought, stocked and issued |
| Category defaults and required attributes | category/attribute-definition tables | Consistent capture rules without hundreds of nullable SKU columns |
| Supplier, supplier part number, purchase UOM, lead time, price | supplier-item table | A SKU can have multiple approved suppliers and prices |
| Warehouse, zone, bin and min/max/reorder settings | item-location table | The same SKU can exist in several stores/bins with different levels |
| Quantity on hand, reserved and available | stock balance by item/location/lot | Transaction-derived operational state, not master data |
| Average and landed cost | inventory valuation/cost layers | Calculated values change per receipt and valuation method |
| Standard cost | item cost version/effective-date table | Requires history and approval rather than overwriting |
| Batch, expiry, serial number and condition | lot/serial/item-instance tables | Flags belong on the SKU; actual values belong to received stock |
| Actual offcut dimensions and lineage | recoverable-piece table | Every recovered piece needs its own identity and status |
| Ledger and tax mapping | posting-profile/tax relationships | Usually varies by company, transaction type and jurisdiction |
| Asset tag, custodian, depreciation and service | asset instance/register | Applies to a physical capital/controlled asset, not every SKU |

### Recommended central model

The logical structure should be:

```text
item_types ──< items >── inventory_subcategories ──> inventory_categories
                  │
                  ├──< item_attributes >── attribute_definitions
                  ├──< item_uom_conversions >── units_of_measure
                  ├──< supplier_items >── suppliers
                  ├──< item_locations >── warehouse_locations
                  ├──< inventory_lots / serial_instances
                  ├──< inventory_transactions
                  ├──< recoverable_pieces
                  └──< asset_instances (only when capitalization/control policy applies)
```

The current `library_materials` can be migrated into `items`, and existing stock/log/board records can retain an item FK. There is no need to create a second competing master and leave `library_materials` active.

### Meaning of item type

`item_types` must not duplicate category. It should classify the item's ERP behaviour. Recommended controlled types are:

- `STOCK` — normal inventory material/component;
- `TOOL` — controlled or returnable tooling;
- `EQUIPMENT` — reusable operational equipment below/subject to asset policy;
- `ASSET_MODEL` — purchasable model that creates a fixed/controlled asset instance at receipt;
- `SERVICE` — non-stock service used in procurement/project costing;
- `NON_STOCK` — chargeable item bought for direct project use without stock;
- `PACKAGING` — only if it needs distinct accounting/operational defaults; otherwise keep it as a category under `STOCK`.

Codes such as `CAB` appear category-like and should not be mixed with behavioural types such as “inventory item”. A clear definition prevents `item_type`, category and the current `material_type` from becoming three versions of the same thing.

### Fields missing from the draft

The proposed master still needs explicit operational controls:

- `issue_disposition`: `consumed`, `returnable`, `recoverable_remainder`;
- `tracking_mode`: `bulk_quantity`, `lot_batch`, `serialized_item`, `dimension_piece`;
- `offcut_recovery_enabled` plus numeric minimum reusable length/width/area, not one free-text field;
- shelf-life days and default expiry calculation for expiry-controlled items;
- stock item/non-stock item flag and negative-stock policy;
- inspection/quality-control requirement on receipt;
- ownership/control class for company, client, consignment or supplier-owned inventory;
- make/buy indicator and bill-of-material eligibility if manufactured subassemblies will be stocked;
- country of origin, commodity/HS code and tax code where landed-cost/import use requires them;
- revision/version control for specification-sensitive materials;
- effective dates and approval status for master-data changes.

### Fields that need correction

- Rename the proposed technical `material_type` to `material_composition` or a category-specific attribute. Otherwise it conflicts with the current consumable/reusable meaning and with `item_type_code`.
- `barcode_qr_code` should not normally be a single field on the SKU. A SKU can have supplier barcodes, pack-level GTINs and internally generated identifiers. Use an `item_identifiers` table with identifier type and UOM/package level.
- `unit_conversion_factor` is insufficient because an item can have several conversions: box-to-piece, roll-to-metre and pallet-to-box. Use one row per from-UOM/to-UOM conversion.
- `minimum_reusable_offcut_size VARCHAR` should become typed policy fields such as `min_length_mm`, `min_width_mm`, `min_area_m2`, and perhaps `min_value_kes`.
- `warehouse`, `storage_zone` and `rack_shelf_bin_location` must use location FKs and support multiple rows per SKU.
- `minimum_stock_level`, `reorder_level` and `maximum_stock_level` belong per item-location, not globally.
- `average_cost` and `landed_cost` should be calculated from receipts/cost layers. They must not be editable SKU fields.
- `valuation_method` is commonly an inventory-category/legal-entity policy. Allow item override only when finance explicitly requires it; changing it after transactions requires controlled migration.
- Ledger mapping should use account/posting-profile FKs, not free text. Inventory, consumption, COGS, variance, WIP and asset-clearing accounts may all be required.
- Tax should reference controlled tax codes; a copied `tax_rate` becomes stale when regulation changes.
- `created_by`, `approved_by` and `last_updated_by` should be user FKs, with a separate approval/change-history table.
- Subcategory must be constrained to the selected category. Separate FKs alone do not prevent mismatched pairs. Prefer storing only the leaf category FK and derive its ancestors.
- Avoid database ENUMs for business values that administrators may extend. Lookup tables or constrained strings are easier to evolve in Laravel/MariaDB and preserve metadata such as active status and sort order.

### Technical specifications: columns versus attributes

The proposed normalized columns are helpful for common dimensions, but one wide set does not fit all material families. For example, `diameter` means something different for cable, drill bits and pipe; capacity may be expressed in litres, kilograms or amp-hours.

Use a hybrid model:

- typed common columns only for universally meaningful/search-heavy data;
- category-owned attribute definitions for specialized fields;
- numeric attribute values accompanied by a UOM;
- controlled option values for grade, finish, chemistry, colour and compatibility;
- uniqueness templates per leaf category.

An MDF uniqueness template could be category + grade + thickness + length + width + finish. An LED module template could be category + manufacturer part number, or voltage + wattage + colour temperature + IP rating + dimensions. This makes duplicate checking meaningful rather than relying on item name.

### Transaction and recovery model corrections

`erp_inventory_transactions` should be a proper immutable inventory ledger, not only a recovery log. At minimum it needs:

- transaction number and line number;
- transaction type FK/code and status;
- item ID, warehouse/location, quantity and UOM;
- signed base quantity after conversion;
- lot/batch ID or serial-instance ID when controlled;
- unit cost and total value/currency where valuation requires it;
- source document type/ID/line (PO receipt, requisition, project issue, return, adjustment, transfer, scrap);
- project/job/work-order references using real FKs where possible;
- from/to locations for transfers;
- reversal link and reason instead of update/delete;
- occurred-at and posted-at timestamps, recorder and approver.

Actual dimensions should not sit on every transaction row. For dimension-controlled inventory, a `recoverable_pieces` or current `boards` table should hold:

- unique piece/QR ID;
- item/SKU and parent-piece lineage;
- original receipt and source issue/project;
- current length, width, thickness, area and optionally weight;
- condition/quality grade;
- current location and lifecycle status;
- recovery, reservation, issue, consumption and scrap timestamps;
- valuation basis and recoverable value.

Transactions then reference the physical piece. This supports multiple offcuts from one board, prevents double-issuing one piece and preserves ancestry.

### Consumable, reusable, recovery and asset examples

| Example | Item type | Issue disposition | Tracking mode | Asset treatment |
|---|---|---|---|---|
| Solvent ink, 5 L | STOCK | consumed | lot_batch | Inventory only |
| MDF 18 × 2440 × 1220 mm | STOCK | recoverable_remainder | dimension_piece | Inventory only |
| Box of 1,000 screws | STOCK | consumed | bulk_quantity | Inventory only; UOM conversion box → piece |
| CNC router bit | TOOL | returnable | serialized_item for high-value tools, otherwise bulk quantity | Controlled equipment only if policy requires |
| LED controller installed in client sign | STOCK | consumed | serial/lot only if warranty traceability requires | Not company asset after issue |
| LED controller used as workshop test unit | EQUIPMENT | returnable | serialized_item | Controlled equipment or asset by policy |
| CNC machine | ASSET_MODEL | returnable/not normally issued as stock | serialized_item | Receipt creates asset instance |
| Client-owned display hardware | EQUIPMENT or ASSET_MODEL | returnable | serialized_item | Client-owned asset instance |

The same product family can have different behaviours based on intended use. If this difference affects stock and accounting, create distinct SKUs or an explicitly governed use/control profile—do not let a user change the disposition casually on the issue screen.

### Approval lifecycle

The draft `item_status` supports an appropriate master-data workflow:

1. `Under Review` — captured/imported but cannot be transacted.
2. `Active` — approved and available to authorized modules.
3. `Blocked` — temporarily prevents new procurement/issues while preserving history.
4. `Discontinued` — no new procurement, existing stock may be consumed/transferred under policy.
5. `Inactive` — hidden from normal use after stock/open-document checks.

Activation should require a completeness score based on item type/category. Approval must validate category, identity attributes, UOM/conversions, control flags/tracking compatibility, finance mapping and duplicate candidates. Status transitions should be audited.

### How the registration UI should follow this model

The capture screen should not display every field in the proposed table. Use five short stages:

1. **Identity:** item type, leaf category, manufacturer/part number, primary/alternate name.
2. **Specification:** category-driven required attributes and dimensions.
3. **Inventory behaviour:** disposition, tracking mode, batch/serial/expiry/hazard flags, UOM conversions and recovery policy.
4. **Supply and storage:** supplier offer(s), warehouse locations and replenishment settings.
5. **Finance and review:** valuation/posting/tax profile, duplicate warnings, completeness, submit for approval.

Defaults should be inherited from category and item type, visibly explained, and overrideable only with the appropriate permission and reason.

### Import workflow

- Use the same domain validation/service as single-entry CRUD.
- Provide one canonical XLSX template with controlled lists or separate templates by category family.
- Include category code, disposition, tracking mode, base UOM, purchase UOM/conversion and category-required fields.
- Stage rows first; show normalized preview, inferred values, duplicates, warnings and errors before commit.
- Resolve category aliases but never silently create production categories. Put unknown values in a steward review queue.
- Offer explicit modes: create only, update only, or upsert; show which fields will be overwritten.
- Make row identity stable using SKU plus supplier code/manufacturer part number; never use name alone.
- Return a downloadable error file with original row, normalized values and precise correction.

## Category governance rules

- Only master-data stewards can create/rename/re-parent categories.
- Users may propose a category or synonym without creating it live.
- Category codes are unique and immutable; operational logic uses flags/codes, never names.
- Leaf categories are selectable; parents are grouping nodes only.
- A category cannot be deleted or re-parented while referenced; deactivate/merge with an alias instead.
- Store default disposition, allowed tracking modes, allowed UOMs and specification template on the category.
- Version material master changes and record reason, approver and before/after values.
- Review uncategorized, duplicate and incomplete records monthly.

For assets, consolidate overlapping roots into functional classes such as Buildings/Facilities, IT & Communications, Production Machinery, Printing Equipment, Tools & Test Equipment, Vehicles/Material Handling, Furniture/Fixtures, and Client-Owned Equipment. Department and location remain separate fields. Add controlled subcategories such as laptops, monitors, printers, CNC routers, drills, ladders and cameras.

## Prioritized implementation roadmap

### Phase 0 — profile and contain (1–2 weeks)

- Export live materials, stock, logs, boards and assets; run the quality queries below.
- Stop automatic category creation in asset import or route it to review.
- Remove transaction-level usage-type override except for authorized exception handling.
- Publish definitions for consumable, returnable, recoverable remainder and asset.

### Phase 1 — enforce current model (2–4 weeks)

- Make material name and normalized leaf category required.
- Make API enforce board/category/type/dimension rules currently present only in UI.
- Add import support for `material_category_id`/category code and material type; reuse CRUD validation.
- Normalize UOM values and reject `-`.
- Fix category synchronization so selecting a new category overwrites stale legacy strings rather than using null-coalescing assignment.
- Add duplicate suggestions and tests covering create, edit and import parity.

### Phase 2 — separate behaviours (4–8 weeks)

- Add `issue_disposition` and explicit `tracking_mode`; backfill from current data.
- Add category metadata/field templates and many-to-many material/workstation compatibility.
- Add purchase-to-base UOM conversions, supplier items, batch/expiry tracking.
- Generalize dimension/offcut tracking beyond the hard-coded three parent names where justified.

### Phase 3 — governance and asset alignment

- Add master-data change approval/versioning, category aliases/merge and quality dashboard.
- Consolidate asset categories and make assignee optional for assets held in store.
- Enforce quantity 1 for serialized assets; provide bulk controlled-equipment handling where needed.
- Add a link from catalogue item/model to physical serialized asset instances without duplicating physical stock.

## Live data-quality query pack

### Automated read-only extraction

The repository now includes a read-only command that generates the alignment workbook inputs without modifying inventory:

```bash
php artisan inventory:audit-master-data
```

By default it writes a timestamped folder under `storage/app/inventory-audits/` containing:

- `materials_alignment.csv` — one row per current material, current stock/activity and proposed disposition/tracking classification;
- `material_categories.csv` — current normalized category hierarchy;
- `quality_issues.csv` — actionable exception rows;
- `suspected_duplicates.csv` — normalized name/category/UOM duplicate candidates;
- `assets.csv` — current asset register when that table exists;
- `summary.json` — counts and extraction metadata.

For a controlled output location and full transaction extract:

```bash
php artisan inventory:audit-master-data \
  --output=storage/app/inventory-alignment-review \
  --include-transactions
```

The proposed classifications are deliberately marked with confidence. They are review inputs, not automatic changes. After business owners approve the mapping, migration must preserve existing material IDs or maintain an explicit legacy-ID-to-new-item-ID crosswalk so stock, logs, boards, requisitions and purchase records remain linked.

Run read-only equivalents against production before migration:

```sql
-- Completeness and current classifications
SELECT
  COUNT(*) AS total,
  SUM(material_name IS NULL OR TRIM(material_name) = '') AS missing_name,
  SUM(material_category_id IS NULL) AS missing_category_fk,
  SUM(unit_of_measure IS NULL OR TRIM(unit_of_measure) IN ('', '-')) AS bad_uom,
  SUM(material_type IS NULL) AS missing_type
FROM library_materials
WHERE deleted_at IS NULL;

-- Category string/FK disagreements
SELECT lm.id, lm.material_code, lm.material_name,
       lm.category AS legacy_parent, lm.subcategory AS legacy_leaf,
       p.name AS fk_parent, c.name AS fk_leaf
FROM library_materials lm
LEFT JOIN material_categories c ON c.id = lm.material_category_id
LEFT JOIN material_categories p ON p.id = c.parent_id
WHERE lm.deleted_at IS NULL
  AND (COALESCE(lm.category, '') <> COALESCE(p.name, c.name, '')
    OR COALESCE(lm.subcategory, '') <> CASE WHEN p.id IS NULL THEN '' ELSE COALESCE(c.name, '') END);

-- Suspected duplicate material definitions
SELECT LOWER(TRIM(material_name)) AS normalized_name,
       COALESCE(material_category_id, 0) AS category_id,
       LOWER(TRIM(unit_of_measure)) AS uom,
       COUNT(*) AS copies,
       GROUP_CONCAT(material_code ORDER BY material_code) AS codes
FROM library_materials
WHERE deleted_at IS NULL
GROUP BY normalized_name, category_id, uom
HAVING COUNT(*) > 1;

-- Reusable records with no returns or board evidence (review classification)
SELECT lm.id, lm.material_code, lm.material_name, lm.category, lm.subcategory
FROM library_materials lm
LEFT JOIN inventory_logs il
  ON il.material_id = lm.id AND il.usage_type = 'reusable'
LEFT JOIN boards b ON b.library_material_id = lm.id
WHERE lm.deleted_at IS NULL AND lm.material_type = 'reusable'
GROUP BY lm.id
HAVING COUNT(il.id) = 0 AND COUNT(b.id) = 0;

-- Attribute-key fragmentation
SELECT JSON_KEYS(COALESCE(JSON_EXTRACT(attributes, '$.attributes'), JSON_OBJECT())) AS attribute_keys,
       COUNT(*) AS materials
FROM library_materials
WHERE deleted_at IS NULL
GROUP BY attribute_keys
ORDER BY materials DESC;

-- Asset category duplication and incomplete control data
SELECT LOWER(TRIM(name)) AS normalized_name, COUNT(*) AS copies
FROM asset_categories
WHERE deleted_at IS NULL
GROUP BY normalized_name HAVING COUNT(*) > 1;

SELECT COUNT(*) AS assets,
       SUM(category_id IS NULL) AS missing_category,
       SUM(asset_code IS NULL OR TRIM(asset_code) = '') AS missing_tag,
       SUM(serial_number IS NULL OR TRIM(serial_number) = '') AS missing_serial,
       SUM(qty > 1) AS multi_quantity_asset_rows
FROM assets
WHERE deleted_at IS NULL;
```

Column names for inventory logs/boards should be confirmed against the deployed migration version before executing the fourth query.

## Acceptance criteria for a robust result

- The same material produces the same normalized record and behaviour whether entered manually, imported or created by an integration.
- No active material lacks a leaf category, valid UOM, disposition or tracking mode.
- Board/offcut, batch, returnable and serialized behaviour is triggered by stable configuration, not category display text.
- Duplicate detection occurs before creation.
- Workstation compatibility does not duplicate catalogue identity.
- Every issued returnable unit can be reconciled; every recoverable remainder has dimensions/lineage when policy requires it.
- Every asset has an appropriate category, status, location/custody state and unique tag; serialized assets represent one physical unit.
- Category and classification changes are auditable and cannot silently reinterpret historical transactions.
