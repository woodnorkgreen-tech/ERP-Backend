<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('print_jobs_design_handoff_id_index')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->index('design_handoff_id', 'print_jobs_design_handoff_id_index');
            });
        }

        if ($this->indexExists('print_jobs_design_handoff_unique')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->dropUnique('print_jobs_design_handoff_unique');
            });
        }

        if (!Schema::hasColumn('print_jobs', 'original_design_handoff_id')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->unsignedBigInteger('original_design_handoff_id')->nullable()->after('design_handoff_id');
            });
        }

        DB::table('print_jobs')
            ->where('order_type', 'original')
            ->whereNull('reprint_of_job_id')
            ->whereNotNull('design_handoff_id')
            ->update(['original_design_handoff_id' => DB::raw('design_handoff_id')]);

        DB::table('print_jobs')
            ->where(fn ($query) => $query
                ->where('order_type', '!=', 'original')
                ->orWhereNotNull('reprint_of_job_id'))
            ->update(['original_design_handoff_id' => null]);

        if (!$this->indexExists('print_jobs_original_design_handoff_unique')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->unique('original_design_handoff_id', 'print_jobs_original_design_handoff_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('print_jobs_original_design_handoff_unique')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->dropUnique('print_jobs_original_design_handoff_unique');
            });
        }

        if (Schema::hasColumn('print_jobs', 'original_design_handoff_id')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->dropColumn('original_design_handoff_id');
            });
        }

        if (!$this->indexExists('print_jobs_design_handoff_unique')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->unique('design_handoff_id', 'print_jobs_design_handoff_unique');
            });
        }

        if ($this->indexExists('print_jobs_design_handoff_id_index')) {
            Schema::table('print_jobs', function (Blueprint $table) {
                $table->dropIndex('print_jobs_design_handoff_id_index');
            });
        }
    }

    private function indexExists(string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'print_jobs')
            ->where('index_name', $indexName)
            ->exists();
    }
};
