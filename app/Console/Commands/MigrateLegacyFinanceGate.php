<?php

namespace App\Console\Commands;

use App\Models\ProjectEnquiry;
use App\Models\GovernanceAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyFinanceGate extends Command
{
    protected $signature   = 'projects:migrate-legacy-gate {--dry-run : Preview changes without writing to DB}';
    protected $description = 'Classifies legacy projects (job number set, status=quote_approved) into awaiting_deposit so they appear correctly on the Finance Gate / Billing & Deposits page.';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        // Find all legacy projects:
        // - Has a job number (was converted before the gate was introduced)
        // - Status is still quote_approved (never moved to awaiting_deposit)
        // - Not internal/sponsorship (those skip the gate)
        $legacy = ProjectEnquiry::whereNotNull('job_number')
            ->where('status', 'quote_approved')
            ->where(function ($q) {
                $q->whereNotIn('workflow_preset_type', ['internal_job', 'sponsorship'])
                  ->orWhereNull('workflow_preset_type');
            })
            ->get();

        if ($legacy->isEmpty()) {
            $this->info('✅ No legacy projects found — nothing to migrate.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Job Number', 'Title', 'Status', 'workflow_preset_type'],
            $legacy->map(fn($e) => [
                $e->id,
                $e->job_number,
                str($e->title)->limit(50),
                $e->status,
                $e->workflow_preset_type ?? 'NULL',
            ])
        );

        $this->line('');
        $this->warn("Found {$legacy->count()} legacy project(s) stuck at quote_approved.");

        if ($isDryRun) {
            $this->info('DRY RUN — no changes written.');
            return self::SUCCESS;
        }

        if (!$this->confirm("Migrate {$legacy->count()} project(s) to awaiting_deposit?")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $migrated = 0;

        DB::transaction(function () use ($legacy, &$migrated) {
            foreach ($legacy as $enquiry) {
                $enquiry->update(['status' => 'awaiting_deposit']);

                // Leave an audit trail so Finance team can see these were legacy migrations
                GovernanceAuditLog::create([
                    'project_enquiry_id' => $enquiry->id,
                    'user_id'            => null, // system action
                    'gate_type'          => 'financial',
                    'action_status'      => 'pending',
                    'message'            => 'Legacy Migration: Project moved to awaiting_deposit. Created before Finance Gate was introduced.',
                    'context'            => [
                        'job_number'           => $enquiry->job_number,
                        'previous_status'      => 'quote_approved',
                        'migration_timestamp'  => now()->toIso8601String(),
                    ],
                    'ip_address'         => '127.0.0.1',
                ]);

                $this->line("  ✔ [{$enquiry->job_number}] {$enquiry->title}");
                $migrated++;
            }
        });

        $this->info("\n✅ Migrated {$migrated} project(s) to awaiting_deposit.");
        $this->line('They will now appear in the Finance → Project Billing & Deposits → Awaiting Release tab.');

        return self::SUCCESS;
    }
}
