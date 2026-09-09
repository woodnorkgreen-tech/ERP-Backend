<?php

namespace Tests\Feature\Stores;

use App\Models\ElementMaterial;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ChartOfAccountSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceDimensionSeeder;
use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Issuing approved project materials through the one movements endpoint.
 *
 * The project material desk used to post to /batch-check-out, which owned rules
 * the single-issue path did not have: it locked the approved line, compared in
 * the stock unit, and allocated pre-linkage issues FIFO across repeated lines
 * for the same material. Those rules moved into StockMovementPoster, so these
 * pin them at the endpoint the desk actually calls now.
 */
class ProjectMaterialIssueTest extends TestCase
{
    use RefreshDatabase;

    private int $enquiryId;
    private int $projectId;
    private int $elementId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Issuing to a project posts a project cost, and the collector refuses
         * to classify one when no active direct-material expense code exists.
         * That is the real rule, not test scaffolding — an issue that cannot be
         * costed should not post — so the finance catalogue is seeded rather
         * than the check stubbed out.
         *
         * All four are needed, in this order: an expense code is only marked
         * active once it resolves a postable debit account, so the chart has to
         * exist before the codes are built, and the dimensions before the chart.
         * Seeding ExpenseCodeSeeder alone leaves every code inactive and the
         * issue is refused exactly as if none were configured.
         */
        $this->seed(FinanceDimensionSeeder::class);
        $this->seed(AccountingPeriodSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(ExpenseCodeSeeder::class);

        Role::findOrCreate('Stores', 'web');
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('Stores');
        // Roles are read off the fresh record, or every request answers 403.
        Sanctum::actingAs($this->user->fresh());

        $clientId = DB::table('clients')->insertGetId([
            'full_name' => 'Client', 'email' => uniqid().'@t.local', 'phone' => '0700000000',
            'address' => 'Nairobi', 'city' => 'Nairobi', 'county' => 'Nairobi',
            'customer_type' => 'company', 'lead_source' => 'test', 'preferred_contact' => 'email',
            'registration_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enquiryId = DB::table('project_enquiries')->insertGetId([
            'date_received' => now()->toDateString(), 'client_id' => $clientId,
            'title' => 'Stand build', 'contact_person' => 'Contact',
            'enquiry_number' => 'ENQ-ISS-001', 'job_number' => 'WNG-ISS-001',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->projectId = DB::table('projects')->insertGetId([
            'enquiry_id' => $this->enquiryId, 'project_id' => 'WNG-ISS-001',
            'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->elementId = $this->element(approved: true);
    }

    private function element(bool $approved): int
    {
        $taskId = DB::table('enquiry_tasks')->insertGetId([
            'project_enquiry_id' => $this->enquiryId, 'type' => 'materials',
            'title' => 'Materials', 'status' => 'in_progress',
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dataId = DB::table('task_materials_data')->insertGetId([
            'enquiry_task_id' => $taskId,
            'project_info' => json_encode([
                'projectId' => 'WNG-ISS-001',
                'approval_status' => ['all_approved' => $approved],
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('project_elements')->insertGetId([
            'persistent_id' => (string) Str::uuid(),
            'task_materials_data_id' => $dataId,
            'element_type' => 'stand', 'name' => 'BOOTH1', 'category' => 'production',
            'is_included' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function material(string $name, float $onHand): LibraryMaterial
    {
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Workshop', 'code' => 'WS-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId,
            'material_name' => $name, 'material_code' => 'MAT-'.uniqid(),
            'category' => 'Materials', 'unit_of_measure' => 'sheet',
            'unit_cost' => 1500, 'item_status' => 'Active', 'is_active' => true,
        ]);

        Stock::create([
            'material_id' => $material->id,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => 0,
        ]);

        return $material;
    }

    private function specify(LibraryMaterial $material, float $quantity, ?int $elementId = null): ElementMaterial
    {
        return ElementMaterial::create([
            'project_element_id' => $elementId ?? $this->elementId,
            'library_material_id' => $material->id,
            'description' => $material->material_name,
            'unit_of_measurement' => 'sheet',
            'quantity' => $quantity,
            'is_included' => true,
        ]);
    }

    /** The shape the project material desk posts. */
    private function issue(array $lines)
    {
        return $this->postJson('/api/procurement-stores/movements', [
            'type' => 'issue',
            'lines' => $lines,
        ]);
    }

    private function line(LibraryMaterial $material, ElementMaterial $planned, float $quantity): array
    {
        return [
            'material_id' => $material->id,
            'project_material_id' => $planned->id,
            'project_id' => $this->projectId,
            'quantity' => $quantity,
            'recipient_name' => 'Otieno',
            'reference_no' => 'WNG-ISS-001',
            'notes' => "Approved project material: {$material->material_name}",
        ];
    }

    private function onHand(LibraryMaterial $material): float
    {
        return (float) DB::table('stocks')->where('material_id', $material->id)->value('quantity_on_hand');
    }

    public function test_an_approved_line_issues_and_is_charged_to_the_project(): void
    {
        $material = $this->material('MDF 18mm', 20);
        $planned = $this->specify($material, 12);

        $this->issue([$this->line($material, $planned, 8)])->assertOk();

        $this->assertSame(12.0, $this->onHand($material));

        $log = InventoryLog::where('material_id', $material->id)->sole();
        $this->assertSame($this->projectId, (int) $log->project_id);
        $this->assertSame($planned->id, (int) $log->project_material_id);
        $this->assertSame('Otieno', $log->recipient_name);
        $this->assertSame('WNG-ISS-001', $log->reference_no);
    }

    public function test_several_lines_of_one_project_post_together(): void
    {
        $first = $this->material('Plywood 12mm', 30);
        $second = $this->material('Aluminium profile', 40);
        $plannedFirst = $this->specify($first, 10);
        $plannedSecond = $this->specify($second, 15);

        $this->issue([
            $this->line($first, $plannedFirst, 10),
            $this->line($second, $plannedSecond, 15),
        ])->assertOk()->assertJsonPath('lines_posted', 2);

        $this->assertSame(20.0, $this->onHand($first));
        $this->assertSame(25.0, $this->onHand($second));

        // One posting is one event, so both lines share a batch number.
        $this->assertCount(1, InventoryLog::pluck('batch_number')->unique());
    }

    public function test_a_material_list_that_is_not_signed_off_cannot_be_issued(): void
    {
        $material = $this->material('Vinyl wrap', 10);
        $planned = $this->specify($material, 5, $this->element(approved: false));

        $this->issue([$this->line($material, $planned, 3)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.project_material_id');

        $this->assertSame(10.0, $this->onHand($material));
    }

    public function test_more_than_the_approved_requirement_is_refused(): void
    {
        $material = $this->material('Acrylic sheet', 50);
        $planned = $this->specify($material, 6);

        $this->issue([$this->line($material, $planned, 9)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.quantity');

        $this->assertSame(50.0, $this->onHand($material));
    }

    /**
     * The rule that only batch issuing used to have.
     *
     * An issue posted before lines carried project_material_id names only the
     * project and the material. It has to be charged against the requirement
     * once — not to every repeated line, and not to none of them.
     */
    public function test_an_issue_made_before_line_linkage_still_counts_against_the_requirement(): void
    {
        $material = $this->material('Timber batten', 100);
        $planned = $this->specify($material, 10);

        // Six already went out on this job, before lines were linked.
        InventoryLog::create([
            'material_id' => $material->id,
            'project_id' => $this->projectId,
            'project_material_id' => null,
            'type' => 'check_out',
            'quantity' => -6,
            'balance_after' => 94,
            'logged_at' => now(),
            'user_id' => $this->user->id,
        ]);

        // Only four of the ten remain, so five must be refused.
        $this->issue([$this->line($material, $planned, 5)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.quantity');

        $this->issue([$this->line($material, $planned, 4)])->assertOk();
        $this->assertSame(96.0, $this->onHand($material));
    }

    public function test_a_planned_line_for_a_different_material_is_refused(): void
    {
        $material = $this->material('Correct material', 20);
        $other = $this->material('Different material', 20);
        $planned = $this->specify($other, 10);

        $this->issue([$this->line($material, $planned, 3)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.project_material_id');

        $this->assertSame(20.0, $this->onHand($material));
    }

    /** Stock that leaves the shelf with nobody named on it is unaccountable. */
    public function test_an_issue_must_name_who_is_receiving_it(): void
    {
        $material = $this->material('Paint tin', 10);
        $planned = $this->specify($material, 5);

        $line = $this->line($material, $planned, 2);
        unset($line['recipient_name']);

        $this->issue([$line])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.recipient_name');

        $this->assertSame(10.0, $this->onHand($material));
    }

    /** The whole desk selection posts, or none of it does. */
    public function test_one_refused_line_rolls_the_whole_selection_back(): void
    {
        $good = $this->material('Fine line', 20);
        $bad = $this->material('Over-drawn line', 20);
        $plannedGood = $this->specify($good, 10);
        $plannedBad = $this->specify($bad, 2);

        $this->issue([
            $this->line($good, $plannedGood, 5),
            $this->line($bad, $plannedBad, 7),
        ])->assertStatus(422)->assertJsonValidationErrors('lines.1.quantity');

        $this->assertSame(20.0, $this->onHand($good), 'The valid line must not survive its neighbour failing.');
        $this->assertSame(0, InventoryLog::count());
    }
}
