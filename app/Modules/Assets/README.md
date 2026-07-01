# Assets Module (Asset Register)

## Overview
Self-contained module for tracking company-owned assets — IT equipment, furniture,
vehicles, tools, etc. — including custodian assignment, location, purchase/value
info, and lifecycle status.

## Structure
```
app/Modules/Assets/
├── Controllers/         # AssetController (CRUD + soft-delete/restore)
├── Database/
│   └── Migrations/      # assets table
├── Models/               # Asset
├── Requests/             # StoreAssetRequest, UpdateAssetRequest
├── Resources/            # AssetResource
├── Routes/               # api.php (prefixed /api/assets)
└── Providers/            # AssetsServiceProvider
```

## Installation
Already registered in `bootstrap/providers.php`:
```php
App\Modules\Assets\Providers\AssetsServiceProvider::class,
```

Run migrations:
```bash
php artisan migrate
```

Seed the starting categories (pulled from the spreadsheet everyone was using before this module existed — add more any time from the Categories screen):
```bash
php artisan db:seed --class="App\Modules\Assets\Database\Seeders\AssetCategorySeeder"
```

## Categories
`asset_categories` supports unlimited sub-categories via `parent_id` (same pattern as
MaterialsLibrary's category tree). The `assets.category_id` FK is the source of truth;
the legacy `assets.category` string column is kept in sync alongside it so older
display code keeps working.

| Method | Endpoint                      | Description |
|--------|--------------------------------|-------------|
| GET    | `/categories`                  | Flat, active-only list (what the asset form's dropdown uses) |
| GET    | `/categories?tree=1`            | Nested list (root → children) for the management screen |
| POST   | `/categories`                  | Create a category — `{ name, parent_id? }`. Used by both the Categories screen and the "+ Add new category" quick-add in the asset form |
| PUT    | `/categories/{id}`             | Rename / re-parent / activate / deactivate |
| DELETE | `/categories/{id}`             | Blocked if assets or sub-categories still reference it |

## API Endpoints
All under `/api/assets`, auth:sanctum protected.

| Method | Endpoint                | Description              |
|--------|--------------------------|---------------------------|
| GET    | `/`                      | List assets (paginated, filterable, with stats) |
| POST   | `/`                      | Create asset (auto-generates `asset_code` if omitted) |
| GET    | `/{id}`                  | Show asset details |
| PUT    | `/{id}`                  | Update asset |
| DELETE | `/{id}`                  | Soft-delete asset |
| GET    | `/trashed`               | List soft-deleted assets |
| POST   | `/{id}/restore`          | Restore a soft-deleted asset |

### Filters supported on `GET /`
`search`, `category`, `status`, `department_id`, `assigned_to`, `with_trashed`,
`sort_by`, `sort_order`, `per_page`.

## `assets` Table
- `asset_code` — unique tag, e.g. `AST-000123`
- `name`, `category`, `status` (Active / In Repair / Retired / Disposed / Lost), `condition`
- `assigned_to` — FK to `users` (custodian)
- `department_id` — FK to `departments`
- `location`, `purchase_date`, `purchase_cost`, `current_value`, `supplier`, `warranty_expiry`, `notes`
- `is_active`, `created_by`, `updated_by`, timestamps, soft deletes

## Frontend
`src/modules/assets/` — Index (list + stats), Create, Show, Edit screens at
`/assets`, `/assets/create`, `/assets/:id`, `/assets/:id/edit`.

Sidebar visibility gated by `canAccessAssets()` in `useRouteGuard.ts`
(currently: Super Admin, Admin).

## Next Steps
- [ ] Wire "Assigned To" / "Department" to real dropdowns once UI is finalised
- [ ] Decide on permission strings if granular backend authorization is needed
- [ ] Depreciation tracking, if required
- [ ] Attachments (receipts/photos) for assets, if required

## License
Internal ERP Module

## Full Field Set (spreadsheet parity)
`assets` now mirrors every column from the WNG Asset Register spreadsheet:

| Sheet column | Field |
|---|---|
| Asset Tag No. | `asset_code` (auto-generates `AST-000123`, or paste a legacy tag like `WNG219`) |
| Sub-Category | `subcategory` (synced automatically from `category_id` when it has a parent) |
| Asset Name | `name` |
| Category | `category` / `category_id` |
| Department | `department_id` |
| Location | `location` |
| Manufacturer | `manufacturer` |
| Model | `model` |
| Serial Number | `serial_number` (the manufacturer's serial — distinct from `asset_code`) |
| Process type and speed | `specifications` (generic free-text spec field) |
| Purchase Date | `purchase_date` |
| Supplier | `supplier` |
| Qty | `qty` (default 1) |
| Purchase Cost (USD) | `purchase_cost_usd` |
| Purchase Cost (KES) | `purchase_cost_kes` |
| Current Value (KES) | `current_value` |
| Condition | `condition` |
| Assigned To | `assigned_to` ("In Charge") |
| Notes | `notes` |

Plus, not in the original sheet: `ownership_type`/`client_name` (Company vs Client-owned),
`image_path` (photo), and `is_available` (the quick on/off toggle).

## Asset Tag Scheme
New tags follow `WNG-{CATEGORY_CODE}-{YYMM}-{SEQ}`, e.g. `WNG-CAM-2606-0001`.
- `CATEGORY_CODE` comes from the chosen category's `code` (root or sub-category,
  whichever was selected) — auto-suggested on creation, editable from the
  Categories screen.
- `{YYMM}` is the registration month, so the sequence resets to `0001` each
  month per category — keeps numbers short and tells you roughly when
  something was registered just by reading the tag.
- Falls back to `WNG-GEN-...` if no category was picked.
- Manually-entered tags (e.g. importing legacy `WNG219`-style tags) are kept
  exactly as given — this scheme only applies when the tag is left blank.

## Bulk Import
`POST /import` (multipart `file`, .csv/.xlsx/.xls, max 10MB) and
`GET /import/template` (downloads a blank CSV with the exact WNG Asset
Register columns) — mirrors the MaterialsLibrary import pattern.

- Matches columns by header text (Asset Tag No., Sub-Category, Asset Name,
  Category, Department, Location, Manufacturer, Model, Serial Number,
  Process type and speed, Purchase Date, Supplier, Qty, Purchase Cost
  (USD)/(KES), Current Value (KES), Condition, Assigned To, Notes) —
  unrecognised columns are ignored rather than failing the row.
- Only **Asset Name** is required; everything else is best-effort.
- Category/Sub-Category text auto-creates the category (with an
  auto-suggested code) if it doesn't exist yet — never blocks on missing
  categories.
- Department and Assigned To are resolved by name match; left blank
  (not an error) if no match is found.
- Purchase Date accepts pretty much any format Excel/humans throw at it
  (DD/MM/YYYY, "16th July 2025", Excel serial dates, etc.) — unparseable
  dates are left null rather than failing the row.
- Condition: kept as-is if it's New/Good/Fair/Poor; anything else (e.g.
  "needs repair", "unusable", "Retired") is translated into `status`/
  `is_available` and the original text is preserved in Notes.
- Re-uploading the same file is safe — rows are matched by Asset Tag (if
  given) and updated rather than duplicated.

## Hire Requests & Assignments
Two distinct flows, one workflow engine (`asset_hire_requests` + `asset_assignment_history`):

- **`hire`** — short-term, project-linked, has an expected return date. Anyone can
  request one (typically a Project Officer), optionally on behalf of a project
  teammate instead of themselves. Lands automatically in the **Returns Queue**
  the moment it's approved.
- **`assign`** — long-term company asset (e.g. a laptop). No return date — held
  until reassigned or the person leaves. Creating one of these is restricted to
  department leads, Admin, HR, or Super Admin (`AssetHireRequestService::canCreateAssignType`).

**Approval** — a department lead of the recipient, their direct manager, HR,
Admin, or Super Admin can approve/reject (`AssetHireRequestService::canApprove`).
Approving an asset:
1. Flips `assets.is_available` to false
2. Closes out any open `asset_assignment_history` row for that asset
3. Opens a new history row for the new holder

That history table is the "this period it was with Ann, then Kimani" story —
visible on the asset's own detail page (`GET /api/assets/{id}` →
`assignment_history`), independent of the hire-request records themselves.

**Visibility** — Super Admin and HR see every request. Everyone else only sees
requests they made, requests for them, ones they've approved/rejected, and
pending ones waiting on their approval (their managed department's people).

**Returning** — only the original approver (or Admin/HR/Super Admin) gets the
"Mark as Returned" action — pops a condition + date form, frees the asset back
up, and closes the history entry. Works the same way for both `hire` (Returns
Queue) and `assign` (an "End Assignment" button on the request's own page).

### Endpoints (all under `/api/assets`)
| Method | Endpoint | |
|---|---|---|
| GET | `/hire-requests` | List, scoped to visibility rules. Filters: `status`, `request_type`, `asset_id` |
| POST | `/hire-requests` | Create — `{ asset_id, request_type, project_id?, for_user_id, out_date, expected_return_date?, purpose? }` |
| GET | `/hire-requests/{id}` | Detail, includes `can_approve` for the current user |
| POST | `/hire-requests/{id}/approve` | |
| POST | `/hire-requests/{id}/reject` | `{ reason? }` |
| POST | `/hire-requests/{id}/cancel` | Requester only, pending only |
| POST | `/hire-requests/{id}/mark-returned` | `{ actual_return_date?, return_condition? }` |

Projects come from the existing `GET /api/projects` endpoint (Projects module) —
no new project data, just linked to it.

## Nothing Is Ever Deleted On Return
"Mark as Returned" only changes a request's `status` to `returned` and records
`actual_return_date`/`return_condition`/`returned_by` — the row stays in
`asset_hire_requests` forever. The same applies to rejected and cancelled
requests. Two views surface that full history:

- **Per-asset Hire History** — `GET /api/assets/{id}/hire-history` returns
  every request ever made for that one asset (pending, approved, rejected,
  returned), oldest first. Shown on the asset's own detail page. One row per
  request — the asset itself is never repeated, just whose hands it passed
  through and when.
- **Movement Log** — `GET /api/assets/movement-log` is a cross-asset,
  chronological feed built from the same request lifecycle, reshaped into
  discrete events (requested / approved / rejected / returned) rather than
  duplicating the underlying data. Filterable by `asset_id`.

**Visibility** (`AssetHireRequestService::canViewAssetHistory`) — wider than
who can approve a single request: Admin, HR, Super Admin, Project Managers,
and any department lead see full history. Everyone else only sees
history/movements for assets they've personally been involved with at some
point (requested it, held it, approved/rejected/returned it).
