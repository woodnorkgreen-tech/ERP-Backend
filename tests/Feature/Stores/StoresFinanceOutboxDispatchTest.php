<?php

namespace Tests\Feature\Stores;

use App\Models\User;
use App\Modules\MaterialsLibrary\Models\LibraryMaterial;
use App\Modules\ProcurementStores\Jobs\ProcessStoresFinancePosting;
use App\Modules\ProcurementStores\Models\InventoryLog;
use App\Modules\ProcurementStores\Services\StoresFinanceOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoresFinanceOutboxDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_outbox_record_is_dispatched_synchronously(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $workstationId = DB::table('workstations')->insertGetId([
            'name' => 'Main Store', 'code' => 'STORE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $material = LibraryMaterial::create([
            'workstation_id' => $workstationId, 'material_name' => 'MDF 18mm',
            'material_code' => 'MDF-18', 'item_status' => 'Active',
        ]);
        $log = InventoryLog::create([
            'material_id' => $material->id, 'user_id' => $user->id,
            'type' => 'check_out', 'quantity' => -1, 'balance_after' => 9,
            'logged_at' => now(),
        ]);

        app(StoresFinanceOutbox::class)->queue($log, 'issue_cost');

        Bus::assertDispatchedSync(ProcessStoresFinancePosting::class, fn ($job) =>
            $job->postingId === $log->financePosting()->value('id'));
    }
}
