<?php

namespace Tests\Feature\CostCollector;

use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Database\Seeders\FinanceReferenceSeeder;
use App\Modules\Finance\Models\ChartOfAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the catalogue itself.
 *
 * The catalogue is data Finance edits, so nothing in the application constrains
 * it — these assertions are the constraint. A malformed row here would not throw
 * anywhere; it would silently render a broken form or default a posting to an
 * account no journal can use.
 */
class ExpenseCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceReferenceSeeder::class);
    }

    public function test_the_catalogue_seeds(): void
    {
        $this->assertGreaterThanOrEqual(65, ExpenseCode::count());
        $this->assertSame(ExpenseCode::count(), ExpenseCode::active()->count());
    }

    public function test_no_code_defaults_to_an_account_a_journal_cannot_post_to(): void
    {
        $headers = ChartOfAccount::where('is_postable', false)->pluck('id');

        $offenders = ExpenseCode::whereIn('default_debit_account_id', $headers)
            ->pluck('code')->all();

        $this->assertSame([], $offenders,
            'These codes default to header accounts: ' . implode(', ', $offenders));
    }

    public function test_every_code_names_its_account_even_when_the_fk_is_unresolved(): void
    {
        // The catalogue phrases some accounts indirectly ("Relevant 1400 PPE
        // account"). Those legitimately have no single default — but the text
        // must survive, or the mapping is simply lost.
        $silent = ExpenseCode::whereNull('default_debit_account_id')
            ->whereNull('default_debit_gl')
            ->pluck('code')->all();

        $this->assertSame([], $silent,
            'These codes have neither an account nor GL text: ' . implode(', ', $silent));
    }

    public function test_every_form_field_is_renderable(): void
    {
        $valid = ['text', 'textarea', 'number', 'date', 'select', 'lookup', 'boolean'];

        foreach (ExpenseCode::all() as $code) {
            foreach ($code->extra_operational_data ?? [] as $field) {
                $this->assertIsArray($field, "{$code->code} has a malformed field entry.");
                $this->assertNotEmpty($field['key'] ?? null, "{$code->code} has a field with no key.");
                $this->assertNotEmpty($field['label'] ?? null, "{$code->code}.{$field['key']} has no label.");
                $this->assertContains($field['type'] ?? 'text', $valid,
                    "{$code->code}.{$field['key']} uses an unrenderable type.");

                if (($field['type'] ?? null) === 'select') {
                    $this->assertNotEmpty($field['options'] ?? [],
                        "{$code->code}.{$field['key']} is a select with no options.");
                }
            }
        }
    }

    public function test_every_evidence_requirement_is_labelled(): void
    {
        foreach (ExpenseCode::all() as $code) {
            foreach ($code->minimum_evidence ?? [] as $item) {
                $this->assertNotEmpty($item['key'] ?? null, "{$code->code} has evidence with no key.");
                $this->assertNotEmpty($item['label'] ?? null, "{$code->code} has evidence with no label.");
            }
        }
    }

    public function test_field_and_evidence_keys_are_unique_within_a_code(): void
    {
        foreach (ExpenseCode::all() as $code) {
            $fieldKeys = collect($code->extra_operational_data ?? [])->pluck('key');
            $this->assertSame($fieldKeys->unique()->count(), $fieldKeys->count(),
                "{$code->code} repeats a field key.");

            $evidenceKeys = collect($code->minimum_evidence ?? [])->pluck('key');
            $this->assertSame($evidenceKeys->unique()->count(), $evidenceKeys->count(),
                "{$code->code} repeats an evidence key.");
        }
    }

    public function test_tax_defaults_resolve_to_seeded_treatments(): void
    {
        $vatCodes = \DB::table('vat_treatments')->pluck('code')->all();
        $whtCodes = \DB::table('wht_categories')->pluck('code')->all();

        foreach (ExpenseCode::whereNotNull('default_vat_treatment_code')->get() as $code) {
            $this->assertContains($code->default_vat_treatment_code, $vatCodes,
                "{$code->code} names a VAT treatment that does not exist.");
        }

        foreach (ExpenseCode::whereNotNull('default_wht_category_code')->get() as $code) {
            $this->assertContains($code->default_wht_category_code, $whtCodes,
                "{$code->code} names a WHT category that does not exist.");
        }
    }

    public function test_a_code_that_forbids_a_job_id_is_never_a_direct_project_cost(): void
    {
        // A contradiction the catalogue could express but which would make the
        // capture form unusable: "charge this to the job" and "no job allowed".
        $contradictory = ExpenseCode::where('job_id_rule', ExpenseCode::JOB_NOT_ALLOWED)
            ->where('accounting_class', 'Direct project cost')
            ->pluck('code')->all();

        $this->assertSame([], $contradictory,
            'Direct project costs that forbid a Job ID: ' . implode(', ', $contradictory));
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $before = ExpenseCode::count();

        $this->seed(FinanceReferenceSeeder::class);

        $this->assertSame($before, ExpenseCode::count());
    }
}
