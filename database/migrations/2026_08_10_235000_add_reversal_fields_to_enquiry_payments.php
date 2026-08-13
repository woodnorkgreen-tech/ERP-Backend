<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('notes');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by');
            $table->index(['project_enquiry_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_payments', function (Blueprint $table) {
            $table->dropIndex(['project_enquiry_id', 'reversed_at']);
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
