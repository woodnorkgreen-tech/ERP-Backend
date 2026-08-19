<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // Null = open to everyone. 'male'/'female' = only requestable by an
            // employee whose employees.gender matches. Data-driven on purpose —
            // any future gender-restricted leave type just sets this column,
            // no code change needed.
            $table->string('restricted_gender')->nullable()->after('code');
        });

        DB::table('leave_types')->where('code', 'MATERNITY')->update(['restricted_gender' => 'female']);
        DB::table('leave_types')->where('code', 'PATERNITY')->update(['restricted_gender' => 'male']);
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('restricted_gender');
        });
    }
};
