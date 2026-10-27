<?php

namespace Tests\Unit\Procurement;

use App\Modules\ProcurementStores\Controllers\MaterialOptionsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaterialOptionsLookupTest extends TestCase
{
    public function test_lookup_by_id_keeps_governance_and_performs_no_writes(): void
    {
        $queries = DB::connection('mysql')->pretend(function () {
            $request = Request::create('/material-options', 'GET', ['material_id' => 42]);
            app()->call([app(MaterialOptionsController::class), 'index'], ['request' => $request]);
        });
        $this->assertNotEmpty($queries);
        $query = $queries[0];
        $this->assertContains(42, $query['bindings']);
        $this->assertContains('Active', $query['bindings']);
        $this->assertStringContainsString('item_status', $query['query']);
        $this->assertStringContainsString('`library_materials`.`id` = ', $query['query']);
        foreach ($queries as $statement) {
            $this->assertStringStartsWith('select', strtolower($statement['query']));
        }
    }
}
