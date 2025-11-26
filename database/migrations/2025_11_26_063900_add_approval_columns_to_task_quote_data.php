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
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->string('approval_status')->nullable()->after('status');
            $table->string('approved_by')->nullable()->after('approval_status');
            $table->date('approval_date')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approval_date');
            $table->text('approval_comments')->nullable()->after('rejection_reason');
            $table->decimal('quote_amount', 15, 2)->nullable()->after('approval_comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropColumn([
                'approval_status',
                'approved_by',
                'approval_date',
                'rejection_reason',
                'approval_comments',
                'quote_amount'
            ]);
        });
    }
};
