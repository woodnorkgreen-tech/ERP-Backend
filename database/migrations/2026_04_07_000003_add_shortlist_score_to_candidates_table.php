<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->unsignedInteger('shortlist_score')->nullable()->after('status');
            $table->json('shortlist_breakdown')->nullable()->after('shortlist_score');
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['shortlist_score', 'shortlist_breakdown']);
        });
    }
};
