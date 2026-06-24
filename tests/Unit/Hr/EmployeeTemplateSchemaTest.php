<?php

namespace Tests\Unit\Hr;

use App\Modules\HR\Support\EmployeeTemplateSchema;
use Tests\TestCase;

/**
 * The bulk-employee Excel feature lives or dies on the export and the import agreeing on
 * columns. The export writes EmployeeTemplateSchema::headers(); the import reads them back
 * by the slug Maatwebsite produces (Str::slug(label, '_')). If a header slug doesn't map
 * back to a field, that column is silently ignored on re-upload — edits vanish with no error.
 * These tests pin that round-trip so the schema can't drift.
 */
class EmployeeTemplateSchemaTest extends TestCase
{
    public function test_every_header_slug_maps_back_to_its_field(): void
    {
        $map = EmployeeTemplateSchema::headerKeyToField();

        foreach (EmployeeTemplateSchema::FIELDS as $field => $label) {
            $slug = \Illuminate\Support\Str::slug($label, '_');
            $this->assertArrayHasKey($slug, $map, "Header '{$label}' slugged to '{$slug}' but the importer has no such key.");
            $this->assertSame($field, $map[$slug], "Header '{$label}' must map back to field '{$field}'.");
        }
    }

    public function test_header_slugs_are_unique(): void
    {
        // Two headers slugging to the same key would let one column silently overwrite another.
        $slugs = array_map(
            fn ($label) => \Illuminate\Support\Str::slug($label, '_'),
            array_values(EmployeeTemplateSchema::FIELDS)
        );

        $this->assertSame(count($slugs), count(array_unique($slugs)), 'Two template headers collide to the same slug.');
    }

    public function test_header_count_matches_field_count_and_order(): void
    {
        $headers = EmployeeTemplateSchema::headers();

        $this->assertSame(array_values(EmployeeTemplateSchema::FIELDS), $headers);
        $this->assertCount(count(EmployeeTemplateSchema::FIELDS), $headers);
    }

    public function test_sensitive_and_readonly_fields_are_real_columns(): void
    {
        $fields = array_keys(EmployeeTemplateSchema::FIELDS);

        foreach (EmployeeTemplateSchema::SENSITIVE_FIELDS as $f) {
            $this->assertContains($f, $fields, "Sensitive field '{$f}' is not a defined column.");
        }
        foreach (EmployeeTemplateSchema::READ_ONLY_FIELDS as $f) {
            $this->assertContains($f, $fields, "Read-only field '{$f}' is not a defined column.");
        }
    }

    public function test_system_id_is_the_first_locked_anchor_column(): void
    {
        // db_id (System ID) is the primary re-upload anchor and is rendered as column A.
        $this->assertSame('db_id', array_key_first(EmployeeTemplateSchema::FIELDS));
        $this->assertSame('A', EmployeeTemplateSchema::columnLetter('db_id'));
        $this->assertContains('db_id', EmployeeTemplateSchema::READ_ONLY_FIELDS);
    }

    public function test_static_dropdown_options_are_lowercased_enums(): void
    {
        // The export offers these as dropdowns and the import validates Rule::in against the
        // same constants — so a value the sheet offers can never be one the importer rejects.
        foreach (EmployeeTemplateSchema::STATUS_OPTIONS as $opt) {
            $this->assertSame(mb_strtolower($opt), $opt, "Status option '{$opt}' must be lowercase to match import normalisation.");
        }
        foreach (EmployeeTemplateSchema::EMPLOYMENT_TYPE_OPTIONS as $opt) {
            $this->assertSame(mb_strtolower($opt), $opt);
        }
    }
}
