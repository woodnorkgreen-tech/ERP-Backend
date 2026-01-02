# Materials Library Module

## Overview
Self-contained module for managing materials catalog with Excel import/export, workstation-based organization, and flexible JSON attributes.

## Structure
```
app/Modules/MaterialsLibrary/
├── Config/              # Module configuration
├── Controllers/         # API controllers
├── Database/
│   ├── Migrations/      # Database migrations
│   └── Seeders/         # Database seeders
├── Models/              # Eloquent models
├── Services/            # Business logic
├── Requests/            # Form validation
├── Resources/           # API resources
├── Routes/              # API routes
└── Providers/           # Service provider
```

## Installation

### 1. Register Service Provider
Already registered in `bootstrap/providers.php`:
```php
App\Modules\MaterialsLibrary\Providers\MaterialsLibraryServiceProvider::class,
```

### 2. Run Migrations
```bash
php artisan migrate
```

### 3. Seed Workstations
```bash
php artisan db:seed --class="App\Modules\MaterialsLibrary\Database\Seeders\WorkstationSeeder"
```

## Database Tables

### `workstations`
- `id` - Primary key
- `code` - Workstation code (CNC, LASER, etc.)
- `name` - Full workstation name
- `description` - What the workstation handles
- `sort_order` - Display order
- `is_active` - Active/inactive flag

### `library_materials`
- `id` - Primary key
- `workstation_id` - Foreign key to workstations
- `material_code` - SKU code (unique)
- `material_name` - Material/item name
- `category` - Main category
- `subcategory` - Sub-category
- `unit_of_measure` - UOM (sqm, meter, piece, etc.)
- `unit_cost` - Cost per UOM
- `attributes` - **JSON column for dynamic fields**
- `is_active` - Active/inactive flag
- `notes` - General notes
- `created_by` - User who created
- `updated_by` - User who last updated

## JSON Attributes

The `attributes` column stores workstation-specific fields:

### Example for CNC Material:
```json
{
  "size": "2440x1220",
  "thickness_size": "18mm",
  "density_grade": "720 kg/m³, E1",
  "machine_compatibility": "CNC Router, Table Saw"
}
```

### Example for LFP Material:
```json
{
  "vinyl_type": "Cast Vinyl",
  "finish": "Glossy",
  "gsm_micron": "80 micron",
  "roll_dimensions": "1.37m x 50m",
  "durability_years": "5-7 years"
}
```

## Querying JSON Attributes

```php
// Find materials by JSON attribute
LibraryMaterial::whereAttribute('thickness_size', '18mm')->get();

// Search with attributes
$materials = LibraryMaterial::where('workstation_id', 1)
    ->whereAttribute('finish', 'Glossy')
    ->get();
```

## Models

### Workstation
```php
use App\Modules\MaterialsLibrary\Models\Workstation;

// Get all active workstations
$workstations = Workstation::active()->ordered()->get();

// Find by code
$cnc = Workstation::findByCode('CNC');

// Get materials
$materials = $cnc->materials()->active()->get();
```

### LibraryMaterial
```php
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;

// Get all active materials
$materials = LibraryMaterial::active()->get();

// By workstation
$cncMaterials = LibraryMaterial::byWorkstation(1)->get();

// By category
$sheetMaterials = LibraryMaterial::byCategory('Sheet Materials')->get();

// Search
$results = LibraryMaterial::search('MDF')->get();

// Get specific attribute
$material = LibraryMaterial::find(1);
$thickness = $material->getAttributeValue('thickness_size');
```

## Workstations

1. **CNC** - CNC Router Workstation
2. **LASER** - Laser Cutter Workstation
3. **LFP** - Large Format Print Workstation
4. **UV** - UV Flatbed Print Workstation
5. **MET** - Metal Fabrication & Welding
6. **CARP** - Carpentry & Woodwork
7. **PAINT** - Paint & Finishing Booth
8. **LED** - Electrical & LED Signage
9. **GEN** - General Hardware & Packaging

## Next Steps

- [ ] Create controllers (CRUD)
- [ ] Create services (import/export)
- [ ] Create validation requests
- [ ] Create API resources
- [ ] Define API routes
- [ ] Build frontend module

## License
Internal ERP Module
