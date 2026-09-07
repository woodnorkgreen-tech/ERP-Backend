<?php

namespace Tests\Feature\PettyCash;

use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that makes the requisition category list a subset of the
 * expense catalogue.
 *
 * It runs during RefreshDatabase before any code is seeded, so it finds nothing
 * and does nothing — which is the behaviour a fresh install wants but proves
 * none of its intent. These re-run it against a seeded catalogue.
 */
class RequisitionCategoryAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function align(): void
    {
        $this->seed(ExpenseCodeSeeder::class);

        $path = 'app/Modules/Finance/Database/Migrations/'
            . '2026_09_05_000001_align_requisition_types_with_expense_catalogue.php';

        (require base_path($path))->up();
    }

    public function test_every_category_it_creates_names_its_accounting_code(): void
    {
        $this->align();

        $categories = PettyCashRequisitionType::where('is_active', true)->get();

        $this->assertGreaterThan(0, $categories->count());
        // The whole point: a category with no code cannot classify what it is
        // raised for, which is the state all eight of the originals were in.
        $this->assertSame(
            [],
            $categories->whereNull('default_expense_code_id')->pluck('name')->all(),
        );
    }

    public function test_a_category_requires_a_project_exactly_when_its_code_does(): void
    {
        $this->align();

        $rules = DB::table('expense_codes')->pluck('job_id_rule', 'id');

        foreach (PettyCashRequisitionType::where('is_active', true)->get() as $category) {
            // requires_project is derived rather than set beside the code, so
            // the two cannot drift into contradicting each other.
            $this->assertSame(
                $rules[$category->default_expense_code_id] === 'required',
                (bool) $category->requires_project,
                "{$category->name} disagrees with its expense code about needing a job number",
            );
        }
    }

    public function test_the_office_categories_never_charge_a_project(): void
    {
        $this->align();

        $office = PettyCashRequisitionType::where('is_active', true)
            ->whereIn('code', ['oe_com_001', 'oe_trp_001', 'oe_wel_001'])
            ->get();

        $this->assertCount(3, $office);
        $this->assertEmpty($office->where('requires_project', true)->all());
    }

    public function test_the_superseded_categories_are_retired_not_destroyed(): void
    {
        // The eight originals are inserted by the migration that created the
        // table, so they are already here — no fixture needed.
        $legacy = ['transport', 'meals', 'miscellaneous', 'repair_maintenance'];
        $this->assertSame(4, PettyCashRequisitionType::whereIn('code', $legacy)->count());

        $this->align();

        // Requisitions raised under them keep their type, and the questions an
        // administrator wrote survive for the two with no successor code.
        $this->assertSame(4, PettyCashRequisitionType::whereIn('code', $legacy)->count());
        $this->assertSame(
            0,
            PettyCashRequisitionType::whereIn('code', $legacy)->where('is_active', true)->count(),
        );
    }

    public function test_it_carries_forward_the_questions_the_old_categories_asked(): void
    {
        $this->align();

        $fuel = PettyCashRequisitionType::where('code', 'tl_fue_001')->firstOrFail();
        $meals = PettyCashRequisitionType::where('code', 'pf_mea_001')->firstOrFail();

        $this->assertSame(
            ['vehicle_or_asset', 'odometer_or_hours'],
            array_column($fuel->request_fields, 'key'),
        );
        $this->assertSame(['service_date', 'meal_type'], array_column($meals->request_fields, 'key'));
    }

    public function test_running_it_twice_does_not_duplicate_a_category(): void
    {
        $this->align();
        $before = PettyCashRequisitionType::count();

        $this->align();

        $this->assertSame($before, PettyCashRequisitionType::count());
    }
}
