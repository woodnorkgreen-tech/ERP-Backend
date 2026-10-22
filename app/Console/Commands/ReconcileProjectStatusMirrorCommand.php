<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report 78C — report (and, only when asked, repair) `projects.status`.
 *
 * `projects.status` is a mirror of `project_enquiries.status`, synced only by
 * the lifecycle actions. Imported jobs never went through them, so on the dev
 * copy 356 projects say `planning` while their enquiry says `completed`. No
 * Stores or Procurement rule reads the mirror any more (the enquiry is the
 * authority), but other screens still do — ProjectsDashboardService counts
 * completed projects from it and currently reports none.
 *
 * Dry run by default: it lists the mismatches and writes nothing. `--apply`
 * copies the enquiry's lifecycle status onto the mirror for the mismatched
 * rows and nothing else, and refuses to run in production — a production
 * correction is a separate, approved change.
 */
class ReconcileProjectStatusMirrorCommand extends Command
{
    protected $signature = 'projects:reconcile-status-mirror
        {--apply : Write the corrections (refused in production)}
        {--limit=25 : How many mismatched rows to list}';

    protected $description = 'Report projects.status rows that disagree with their enquiry (dry run unless --apply)';

    /** The mirror's enum. Enquiry statuses before planning have no mirror value. */
    private const MIRROR_VALUES = ['planning', 'in_progress', 'completed', 'closed', 'cancelled'];

    public function handle(): int
    {
        $rows = DB::table('projects as p')
            ->join('project_enquiries as e', 'e.id', '=', 'p.enquiry_id')
            ->whereColumn('p.status', '!=', 'e.status')
            ->orderBy('p.id')
            ->get(['p.id', 'p.project_id', 'p.status as mirror', 'e.id as enquiry_id', 'e.status as authority']);

        $this->info('Database: '.config('database.connections.'.config('database.default').'.database'));

        if ($rows->isEmpty()) {
            $this->info('projects.status agrees with project_enquiries.status on every row.');

            return self::SUCCESS;
        }

        $fixable = $rows->filter(fn ($row) => in_array($row->authority, self::MIRROR_VALUES, true));
        $unfixable = $rows->reject(fn ($row) => in_array($row->authority, self::MIRROR_VALUES, true));

        $this->table(['mirror → authority', 'rows'], $rows->groupBy(fn ($row) => "{$row->mirror} → {$row->authority}")
            ->map(fn ($group, $key) => [$key, $group->count()])->values()->all());

        $this->table(['project', 'code', 'mirror', 'enquiry', 'authority'], $rows->take((int) $this->option('limit'))
            ->map(fn ($row) => [$row->id, $row->project_id, $row->mirror, $row->enquiry_id, $row->authority])->all());

        $this->line("{$rows->count()} mismatched; {$fixable->count()} can be copied from the enquiry; {$unfixable->count()} have a pre-project enquiry status the mirror cannot hold (left for review).");

        if (! $this->option('apply')) {
            $this->comment('Dry run — nothing was written. Re-run with --apply to copy the enquiry status onto the mirror.');

            return self::SUCCESS;
        }

        if (app()->environment('production')) {
            $this->error('Refused: --apply does not run in production. A production correction needs its own approval.');

            return self::FAILURE;
        }

        $updated = 0;
        DB::transaction(function () use ($fixable, &$updated) {
            foreach ($fixable as $row) {
                $updated += DB::table('projects')->where('id', $row->id)
                    ->where('status', $row->mirror)
                    ->update(['status' => $row->authority, 'updated_at' => now()]);
            }
        });

        $this->info("Updated {$updated} projects.status rows. Enquiries were not touched.");

        return self::SUCCESS;
    }
}
