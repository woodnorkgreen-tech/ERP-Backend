<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('task_issues', 'issue_type')) {
            return;
        }

        DB::table('task_issues')->where('issue_type', 'technical')->update(['issue_type' => 'bug']);
        DB::table('task_issues')->where('issue_type', 'resource')->update(['issue_type' => 'support']);
        DB::table('task_issues')->where('issue_type', 'dependency')->update(['issue_type' => 'change_request']);
        DB::table('task_issues')->where('issue_type', 'general')->update(['issue_type' => 'other']);

        DB::statement(
            "ALTER TABLE task_issues MODIFY issue_type ENUM('bug','feature_request','improvement','question','security','performance','documentation','enhancement','support','incident','change_request','maintenance','training','compliance','blocker','other') NOT NULL DEFAULT 'bug'"
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('task_issues', 'issue_type')) {
            return;
        }

        DB::table('task_issues')->where('issue_type', 'bug')->update(['issue_type' => 'technical']);
        DB::table('task_issues')->where('issue_type', 'support')->update(['issue_type' => 'resource']);
        DB::table('task_issues')->where('issue_type', 'change_request')->update(['issue_type' => 'dependency']);
        DB::table('task_issues')
            ->whereIn('issue_type', ['feature_request', 'improvement', 'question', 'security', 'performance', 'documentation', 'enhancement', 'incident', 'maintenance', 'training', 'compliance', 'other'])
            ->update(['issue_type' => 'general']);

        DB::statement(
            "ALTER TABLE task_issues MODIFY issue_type ENUM('blocker','technical','resource','dependency','general') NOT NULL DEFAULT 'general'"
        );
    }
};
