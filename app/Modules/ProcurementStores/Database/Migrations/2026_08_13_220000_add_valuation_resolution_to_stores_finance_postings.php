<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stores_finance_postings', function (Blueprint $table) {
            $table->decimal('resolved_unit_cost', 12, 4)->nullable()->after('cost_line_id');
            $table->text('resolution_notes')->nullable()->after('resolved_unit_cost');
            $table->foreignId('resolved_by')->nullable()->after('resolution_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }
    public function down(): void
    {
        Schema::table('stores_finance_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_unit_cost', 'resolution_notes', 'resolved_at']);
        });
    }
};
