<?php

namespace Tests\Unit\Hr;

use App\Modules\HR\Services\SalaryAdvanceRegister;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryAdvanceExportSelectionTest extends TestCase
{
    public function test_selected_ids_are_applied_alongside_the_existing_filters(): void
    {
        $register = app(SalaryAdvanceRegister::class);
        $filters = $register->filters(Request::create('/export', 'GET', ['ids' => ['3', '8'], 'status' => 'approved']));
        $this->assertSame([3, 8], $filters['ids']);
        $query = $register->query($filters);
        $this->assertStringContainsString('`salary_advance_requests`.`id` in (?, ?)', $query->toSql());
        $this->assertSame([3, 8, 'approved'], $query->getBindings());
    }

    public function test_an_explicit_empty_selection_cannot_export_the_entire_register(): void
    {
        $this->expectException(ValidationException::class);
        app(SalaryAdvanceRegister::class)->filters(Request::create('/export', 'GET', ['ids' => []]));
    }

    public function test_invalid_selected_ids_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SalaryAdvanceRegister::class)->filters(Request::create('/export', 'GET', ['ids' => ['invalid']]));
    }

    public function test_unselected_exports_keep_the_filtered_register_behavior(): void
    {
        $register = app(SalaryAdvanceRegister::class);
        $filters = $register->filters(Request::create('/export', 'GET', ['status' => 'pending']));
        $this->assertArrayNotHasKey('ids', $filters);
        $this->assertSame(['pending'], $register->query($filters)->getBindings());
    }
}
