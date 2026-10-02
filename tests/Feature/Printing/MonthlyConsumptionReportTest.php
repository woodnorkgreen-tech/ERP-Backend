<?php

namespace Tests\Feature\Printing;

use App\Modules\Printing\Services\PrintingDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyConsumptionReportTest extends TestCase
{
    use RefreshDatabase;

    private function usage(string $origin, string $project, string $material, float $running, float $area): void
    {
        $jobId = DB::table('print_jobs')->insertGetId([
            'origin' => $origin,
            'project_name' => $project,
            'title' => 'Print '.$material,
            'status' => 'completed',
            'completed_at' => '2026-09-10 10:00:00',
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
        ]);

        DB::table('print_job_consumptions')->insert([
            'print_job_id' => $jobId,
            'material_family' => $material,
            'actual_running_m' => $running,
            'calculated_sqm' => $area,
            'created_at' => '2026-09-12 10:00:00',
            'updated_at' => '2026-09-12 10:00:00',
        ]);
    }

    public function test_monthly_report_groups_normalized_materials_and_projects_under_strict_sql_mode(): void
    {
        $this->usage('historical_import', 'Project A', 'Matt white sticker', 4.5, 3);
        $this->usage('historical_import', 'Project A', 'Matte sticker', 2.5, 2);
        $this->usage('historical_import', 'Project B', 'Glosy Sticker White', 3, 1);
        $this->usage('design', 'Project B', 'Matt white sticker', 1, 1);

        $report = app(PrintingDashboardService::class)->monthlyConsumption([
            'period' => 'month', 'month' => '2026-09', 'source' => 'all',
        ]);

        $this->assertSame('2026-09', $report['selected_month']);
        $this->assertSame(4, (int) $report['totals']->jobs);
        $this->assertEquals(11, (float) $report['totals']->running_m);

        $materials = $report['materials']->keyBy('material');
        $this->assertCount(2, $materials);
        $this->assertSame(3, (int) $materials['Matte Sticker']->jobs);
        $this->assertSame(2, (int) $materials['Matte Sticker']->projects);
        $this->assertEquals(8, (float) $materials['Matte Sticker']->running_m);
        $this->assertEquals(3, (float) $materials['Gloss Sticker']->running_m);

        $projects = $report['projects']->keyBy(fn ($row) => $row->project.':'.$row->material);
        $this->assertCount(3, $projects);
        $this->assertEquals(7, (float) $projects['Project A:Matte Sticker']->running_m);
        $this->assertEquals(3, (float) $projects['Project B:Gloss Sticker']->running_m);
        $this->assertEquals(1, (float) $projects['Project B:Matte Sticker']->running_m);

        $historical = app(PrintingDashboardService::class)->monthlyConsumption([
            'period' => 'month', 'month' => '2026-09', 'source' => 'historical',
        ]);
        $this->assertSame(3, (int) $historical['totals']->jobs);
        $this->assertEquals(7, (float) $historical['materials']->keyBy('material')['Matte Sticker']->running_m);
    }
}
