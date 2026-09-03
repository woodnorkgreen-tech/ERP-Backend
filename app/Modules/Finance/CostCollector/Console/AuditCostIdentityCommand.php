<?php

namespace App\Modules\Finance\CostCollector\Console;

use App\Models\Project;
use App\Models\ProjectEnquiry;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Services\PettyCashCostProducer;
use Illuminate\Console\Command;

/**
 * Finds cost lines whose own project identity fields contradict each other.
 *
 * Two distinct contradictions are possible, and only the second one catches the
 * Stores misattribution that prompted this check:
 *
 *  - project → enquiry mismatch: cost_line.project_id's own enquiry_id differs
 *    from cost_line.project_enquiry_id.
 *
 *  - job number mismatch: the pair is internally consistent but names a job the
 *    displayed job_number does not belong to. This is what happens when a
 *    producer passes an id of the wrong type: the resolver derives a coherent
 *    but wrong project, while the caller-supplied job number survives untouched.
 */
class AuditCostIdentityCommand extends Command
{
    protected $signature = 'finance:audit-cost-identity {--source= : Restrict to a source_type substring, e.g. InventoryLog}';

    protected $description = 'Report cost lines whose project, enquiry and job number contradict each other';

    public function handle(): int
    {
        $query = CostLine::query()
            ->where(fn ($q) => $q->whereNotNull('project_id')->orWhereNotNull('project_enquiry_id'));

        if ($source = $this->option('source')) {
            $query->where('source_type', 'like', "%{$source}%");
        }

        $projects = Project::all(['id', 'project_id', 'enquiry_id'])->keyBy('id');
        $enquiries = ProjectEnquiry::all(['id', 'job_number'])->keyBy('id');

        $pairMismatch = [];
        $jobMismatch = [];

        foreach ($query->cursor() as $line) {
            $project = $line->project_id ? $projects->get($line->project_id) : null;
            $enquiry = $line->project_enquiry_id ? $enquiries->get($line->project_enquiry_id) : null;

            if ($project && $enquiry && (int) $project->enquiry_id !== (int) $enquiry->id) {
                $pairMismatch[] = [
                    $line->ref,
                    "project {$project->id} → enquiry " . ($project->enquiry_id ?? 'none'),
                    "line enquiry {$enquiry->id}",
                    (string) $line->net_amount,
                ];
                continue;
            }

            if (blank($line->job_number) || (! $project && ! $enquiry)) {
                continue;
            }

            $accepted = array_values(array_filter([
                $enquiry?->job_number,
                $project ? $enquiries->get($project->enquiry_id)?->job_number : null,
                $project?->project_id,
            ]));

            if (! $accepted) {
                continue;
            }

            $normalise = fn (?string $value) => PettyCashCostProducer::normaliseJobNumber((string) $value);
            $supplied = $normalise($line->job_number);

            foreach ($accepted as $candidate) {
                if ($normalise($candidate) === $supplied) {
                    continue 2;
                }
            }

            $jobMismatch[] = [
                $line->ref,
                $line->job_number,
                implode(' / ', array_unique($accepted)),
                (string) $line->net_amount,
            ];
        }

        if ($pairMismatch) {
            $this->newLine();
            $this->error('Project and enquiry disagree:');
            $this->table(['Cost line', "Project's own enquiry", 'Line enquiry', 'Amount'], $pairMismatch);
        }

        if ($jobMismatch) {
            $this->newLine();
            $this->error('Job number does not belong to the resolved job:');
            $this->table(['Cost line', 'Job number on line', 'Job of resolved project', 'Amount'], $jobMismatch);
        }

        $total = count($pairMismatch) + count($jobMismatch);

        if ($total === 0) {
            $this->info('Every cost line agrees with itself on project, enquiry and job number.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("{$total} cost line(s) carry contradictory project identity.");
        $this->line('Reverse and repost them; they must not be edited in place.');

        return self::FAILURE;
    }
}
