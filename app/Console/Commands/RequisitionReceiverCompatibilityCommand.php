<?php

namespace App\Console\Commands;

use App\Modules\Finance\PettyCash\Services\RequisitionReceiverCompatibility;
use Illuminate\Console\Command;

/**
 * Report 75R-B: a read-only check of open, unpaid requisitions against the
 * receiver rules. It writes nothing and proposes no receiver identity.
 */
class RequisitionReceiverCompatibilityCommand extends Command
{
    protected $signature = 'finance:requisition-receiver-compatibility {--json : Print the report as JSON}';

    protected $description = 'Read-only: which open requisitions can be paid as they stand, and what the others need';

    public function handle(RequisitionReceiverCompatibility $compatibility): int
    {
        $rows = $compatibility->report();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Open, unpaid requisitions: '.count($rows).'. This report changes nothing.');
        foreach (collect($rows)->countBy('classification')->sortKeys() as $class => $count) {
            $this->line(sprintf('  %-34s %d', $class, $count));
        }
        if ($rows !== []) {
            $this->table(['Requisition', 'Status', 'Requester', 'Amount', 'Lines', 'Classification', 'Why'],
                array_map(fn (array $row) => [$row['reference'], $row['status'], $row['requester'], $row['amount'], $row['lines'],
                    $row['classification'], $row['reason'].(empty($row['receivers_named_only']) ? '' : ' Named only: '.implode(', ', $row['receivers_named_only']).'.')], $rows));
        }

        return self::SUCCESS;
    }
}
