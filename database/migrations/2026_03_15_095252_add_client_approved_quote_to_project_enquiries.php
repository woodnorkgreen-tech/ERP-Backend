<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->decimal('client_approved_quote', 15, 2)->nullable()->after('quote_approved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropColumn('client_approved_quote');
        });
    }
};
