<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('origin', 30)->default('design')->after('design_job_id')->index();
            $table->string('request_source', 60)->nullable()->after('origin');
            $table->string('requested_by_name')->nullable()->after('request_source');
            $table->text('bypass_reason')->nullable()->after('requested_by_name');
            $table->string('priority', 20)->default('normal')->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex(['origin']);
            $table->dropIndex(['priority']);
            $table->dropColumn(['origin', 'request_source', 'requested_by_name', 'bypass_reason', 'priority']);
        });
    }
};
