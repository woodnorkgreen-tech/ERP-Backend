<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            // A trip that already happened and was logged afterwards, so the
            // history is complete. Marked so it can always be told apart.
            $table->boolean('logged_late')->default(false)->after('status');
            $table->timestamp('logged_late_at')->nullable()->after('logged_late');
        });
    }

    public function down(): void
    {
        Schema::table('trip_requests', function (Blueprint $table) {
            $table->dropColumn(['logged_late', 'logged_late_at']);
        });
    }
};
