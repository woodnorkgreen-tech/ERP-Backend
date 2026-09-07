<?php

namespace App\Modules\ProcurementStores\Console;

use App\Modules\ProcurementStores\Services\OperationsReadinessService;
use Illuminate\Console\Command;

class OperationsReadinessCommand extends Command
{
    protected $signature = 'stores:readiness {--json : Output the report as JSON}';
    protected $description = 'Check current materials, procurement, stock, boards and Stores cost capture without changing records';

    public function handle(OperationsReadinessService $readiness): int
    {
        $report = $readiness->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Records', 'Next action'], array_map(fn ($check) => [
                $check['label'], $check['ready'] ? 'PASS' : 'REVIEW', $check['message'],
                $check['ready'] ? '' : $check['directive'],
            ], $report['checks']));
            $this->line('Review Finance setup separately for periods, account mappings and ledger integrity.');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
