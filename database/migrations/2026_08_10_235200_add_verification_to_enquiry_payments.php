<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('notes');
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->index(['project_enquiry_id', 'status']);
        });

        // Preserve historical operational totals. The checker workflow applies
        // prospectively; legacy receipts predate it and remain explicitly marked.
        DB::table('enquiry_payments')->whereNull('reversed_at')->update([
            'status' => 'verified',
            'verified_at' => DB::raw('created_at'),
        ]);
        DB::table('enquiry_payments')->whereNotNull('reversed_at')->update(['status' => 'reversed']);
    }

    public function down(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->dropIndex(['project_enquiry_id', 'status']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['status', 'verified_at']);
        });
    }
};
