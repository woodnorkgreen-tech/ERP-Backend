<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\HR\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AssetImportService
{
    /**
     * Header aliases -> our column names. Matches the exact WNG Asset
     * Register spreadsheet headers, plus a few common variants, so the
     * sheet can be uploaded close to as-is.
     */
    private const STANDARD_COLUMNS = [
        'asset_tag_no' => 'asset_code',
        'asset_tag' => 'asset_code',
        'tag_no' => 'asset_code',
        'tag' => 'asset_code',

        'sub_category' => 'subcategory',
        'subcategory' => 'subcategory',

        'asset_name' => 'name',
        'name' => 'name',
        'item' => 'name',
        'item_name' => 'name',

        'category' => 'category',

        'department' => 'department',

        'location' => 'location',

        'manufacturer' => 'manufacturer',
        'make' => 'manufacturer',

        'model' => 'model',

        'serial_number' => 'serial_number',
        'serial_no' => 'serial_number',
        'serial' => 'serial_number',

        'process_type_and_speed' => 'specifications',
        'process_type' => 'specifications',
        'specifications' => 'specifications',
        'specs' => 'specifications',

        'purchase_date' => 'purchase_date',
        'date_purchased' => 'purchase_date',

        'supplier' => 'supplier',
        'vendor' => 'supplier',

        'qty' => 'qty',
        'quantity' => 'qty',

        'purchase_cost_usd' => 'purchase_cost_usd',
        'purchase_cost' => 'purchase_cost_usd', // ambiguous alone — assume USD unless "kes" appears
        'cost_usd' => 'purchase_cost_usd',

        'purchase_cost_kes' => 'purchase_cost_kes',
        'cost_kes' => 'purchase_cost_kes',

        'current_value_kes' => 'current_value',
        'current_value' => 'current_value',

        'condition' => 'condition',

        'assigned_to' => 'assigned_to',
        'assignee' => 'assigned_to',
        'in_charge' => 'assigned_to',

        'notes' => 'notes',
        'remarks' => 'notes',
    ];

    private const VALID_CONDITIONS = ['New', 'Good', 'Fair', 'Poor'];

    /** Cached lookups so repeated rows don't keep re-querying the same names. */
    private array $departmentCache = [];
    private array $userCache = [];
    private array $categoryCache = [];

    /**
     * Process the import of a CSV/XLSX file.
     */
    public function import($file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();

        $headers = [];
        foreach ($worksheet->getRowIterator(1, 1) as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            foreach ($cellIterator as $cell) {
                $headers[] = $cell->getValue();
            }
        }

        $headerMap = [];
        foreach ($headers as $index => $header) {
            if (!empty($header)) {
                $headerMap[$index] = trim((string) $header);
            }
        }

        $results = [
            'total' => 0,
            'success' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();
        try {
            $rowIndex = 2;
            foreach ($worksheet->getRowIterator(2) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $columnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                    if (isset($headerMap[$columnIndex])) {
                        $rowData[$headerMap[$columnIndex]] = $cell->getValue();
                    }
                }

                if ($this->isRowEmpty($rowData)) {
                    $rowIndex++;
                    continue;
                }

                $results['total']++;

                try {
                    $this->processRow($rowData, $results);
                } catch (\Exception $e) {
                    $results['errors'][] = "Row {$rowIndex}: " . $e->getMessage();
                }

                $rowIndex++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    private function isRowEmpty($row): bool
    {
        foreach ($row as $value) {
            if (!empty($value)) return false;
        }
        return true;
    }

    private function processRow(array $row, array &$results): void
    {
        $data = [
            'ownership_type' => 'Company',
            'is_available' => true,
            'status' => 'Active',
            'qty' => 1,
        ];

        $rawCategory = null;
        $rawSubcategory = null;
        $rawDepartment = null;
        $rawAssignedTo = null;
        $rawCondition = null;

        foreach ($row as $header => $value) {
            if ($value === null || $value === '') continue;

            $normalizedHeader = trim(Str::snake(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($header)))), '_');

            if (!isset(self::STANDARD_COLUMNS[$normalizedHeader])) {
                continue; // unrecognised column — ignored rather than failing the row
            }

            $field = self::STANDARD_COLUMNS[$normalizedHeader];

            switch ($field) {
                case 'category':
                    $rawCategory = trim((string) $value);
                    break;
                case 'subcategory':
                    $rawSubcategory = trim((string) $value);
                    break;
                case 'department':
                    $rawDepartment = trim((string) $value);
                    break;
                case 'assigned_to':
                    $rawAssignedTo = trim((string) $value);
                    break;
                case 'condition':
                    $rawCondition = trim((string) $value);
                    break;
                case 'purchase_date':
                    $data['purchase_date'] = $this->parseFlexibleDate($value);
                    break;
                case 'qty':
                    $data['qty'] = is_numeric($value) ? max(1, (int) $value) : 1;
                    break;
                case 'purchase_cost_usd':
                case 'purchase_cost_kes':
                case 'current_value':
                    if (is_numeric($value)) {
                        $data[$field] = (float) $value;
                    }
                    break;
                default:
                    $data[$field] = is_string($value) ? trim($value) : $value;
            }
        }

        if (empty($data['name'])) {
            throw new \Exception('Missing Asset Name');
        }

        // Resolve (or create) the category / sub-category.
        if ($rawCategory) {
            $categoryId = $this->resolveCategory($rawCategory, $rawSubcategory);
            if ($categoryId) {
                $data['category_id'] = $categoryId;
            }
            $data['category'] = $rawCategory;
            if ($rawSubcategory) {
                $data['subcategory'] = $rawSubcategory;
            }
        }

        // Resolve department by name — left blank if no match, never blocks the row.
        if ($rawDepartment) {
            $data['department_id'] = $this->resolveDepartment($rawDepartment);
        }

        // Resolve "Assigned To" by name. Sheets sometimes list several people
        // comma-separated for shared responsibility — we take the first.
        if ($rawAssignedTo) {
            $firstName = trim(explode(',', $rawAssignedTo)[0]);
            $data['assigned_to'] = $this->resolveUser($firstName);
        }

        // Condition: only keep it if it's one of our four values; anything
        // else (e.g. "needs repair", "unusable") becomes a status/availability
        // signal instead, and the original text is preserved in notes.
        if ($rawCondition) {
            $this->applyConditionSignal($rawCondition, $data);
        }

        $explicitTag = $data['asset_code'] ?? null;

        // Rows with no Asset Tag of their own need a different way to
        // recognise "this is the same row as last time" on re-import —
        // otherwise every untagged row (the majority of the real sheet)
        // would duplicate on every re-upload. A content fingerprint over
        // everything that identifies the row (including who it's assigned
        // to, which is what actually distinguishes 36 otherwise-identical
        // office chairs from each other) does that without needing a tag.
        $importHash = $explicitTag ? null : $this->buildImportHash($data, $rawAssignedTo);

        $asset = null;
        if ($explicitTag) {
            $asset = Asset::withTrashed()->where('asset_code', $explicitTag)->first();
        } elseif ($importHash) {
            $asset = Asset::withTrashed()->where('import_hash', $importHash)->first();
        }

        // Tag: use the sheet's own tag as-is if given, else auto-generate
        // (only for genuinely new rows — an existing match keeps its tag).
        if (!$asset) {
            $data['asset_code'] = $explicitTag ?? Asset::generateAssetCode($data['category_id'] ?? null);
            $data['import_hash'] = $importHash;
        }

        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        if ($asset) {
            unset($data['created_by'], $data['asset_code']); // never touch the tag or import_hash of an existing match
            $asset->update($data);
            $results['updated']++;
        } else {
            Asset::create($data);
            $results['created']++;
        }

        $results['success']++;
    }

    /**
     * Builds a stable fingerprint for rows without their own Asset Tag, so
     * re-uploading the same file updates the same record instead of
     * creating a duplicate. Includes the raw "assigned to" text (before
     * it's resolved to a user id) since that's often the only thing that
     * actually tells two otherwise-identical rows apart.
     */
    private function buildImportHash(array $data, ?string $rawAssignedTo): string
    {
        $parts = [
            strtolower(trim($data['name'] ?? '')),
            $data['category_id'] ?? '',
            strtolower(trim($data['subcategory'] ?? '')),
            strtolower(trim($data['manufacturer'] ?? '')),
            strtolower(trim($data['model'] ?? '')),
            strtolower(trim($data['serial_number'] ?? '')),
            strtolower(trim($data['location'] ?? '')),
            $data['department_id'] ?? '',
            strtolower(trim($rawAssignedTo ?? '')),
            $data['qty'] ?? 1,
            $data['purchase_date'] ?? '',
            $data['supplier'] ?? '',
        ];

        return md5(implode('|', $parts));
    }

    /**
     * Find or create the category (and sub-category, if given), auto-coding
     * any newly created one so asset tags can use it immediately.
     */
    private function resolveCategory(string $categoryName, ?string $subcategoryName): ?int
    {
        $cacheKey = $categoryName . '|' . ($subcategoryName ?? '');
        if (isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        $root = AssetCategory::firstOrCreate(
            ['name' => $categoryName, 'parent_id' => null],
            ['code' => AssetCategory::suggestCode($categoryName)]
        );

        $target = $root;

        if ($subcategoryName) {
            $target = AssetCategory::firstOrCreate(
                ['name' => $subcategoryName, 'parent_id' => $root->id],
                ['code' => AssetCategory::suggestCode($subcategoryName)]
            );
        }

        return $this->categoryCache[$cacheKey] = $target->id;
    }

    private function resolveDepartment(string $name): ?int
    {
        if (isset($this->departmentCache[$name])) {
            return $this->departmentCache[$name];
        }

        $department = Department::where('name', 'like', "%{$name}%")->first();

        return $this->departmentCache[$name] = $department?->id;
    }

    private function resolveUser(string $name): ?int
    {
        if (isset($this->userCache[$name])) {
            return $this->userCache[$name];
        }

        $user = User::where('name', 'like', "%{$name}%")->first();

        return $this->userCache[$name] = $user?->id;
    }

    /**
     * Parse whatever date format the sheet happens to use for this cell.
     * Returns null (never throws) if it can't be understood — a row
     * shouldn't fail to import just because of an unparsable date.
     */
    private function parseFlexibleDate($value): ?string
    {
        if (empty($value)) return null;

        // Excel serial date number
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Exception $e) {
                // fall through to string parsing
            }
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Map free-text condition notes onto our condition/status/availability
     * fields without losing the original wording.
     */
    private function applyConditionSignal(string $raw, array &$data): void
    {
        foreach (self::VALID_CONDITIONS as $valid) {
            if (strcasecmp($raw, $valid) === 0) {
                $data['condition'] = $valid;
                return;
            }
        }

        $lower = strtolower($raw);
        if (str_contains($lower, 'retired')) {
            $data['status'] = 'Retired';
            $data['is_available'] = false;
        } elseif (str_contains($lower, 'unusable') || str_contains($lower, 'faulty')
            || str_contains($lower, 'dead') || str_contains($lower, 'broken')
            || str_contains($lower, 'repair')) {
            $data['status'] = 'In Repair';
            $data['is_available'] = false;
        }

        $data['notes'] = trim(($data['notes'] ?? '') . "\nImported condition note: {$raw}");
    }
}
