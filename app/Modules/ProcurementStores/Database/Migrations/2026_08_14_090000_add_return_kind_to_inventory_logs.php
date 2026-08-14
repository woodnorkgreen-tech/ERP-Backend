<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->string('return_kind', 32)->nullable()->after('original_issue_log_id')->index();
        });

        DB::table('inventory_logs')->where('type', 'return')->update(['return_kind' => 'whole_item']);
        DB::table('inventory_logs')->where('type', 'return')->where('notes', 'like', 'Offcut %')
            ->update(['return_kind' => 'recovered_offcut']);
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropIndex(['return_kind']);
            $table->dropColumn('return_kind');
        });
    }
};
