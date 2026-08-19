<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->boolean('quote_requirement_waived')->default(false)->after('client_approved_quote');
            $table->decimal('quote_waiver_billing_amount', 15, 2)->nullable()->after('quote_requirement_waived');
            $table->text('quote_waiver_reason')->nullable()->after('quote_waiver_billing_amount');
            $table->foreignId('quote_waived_by')->nullable()->after('quote_waiver_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('quote_waived_at')->nullable()->after('quote_waived_by');
        });
    }

    public function down(): void
    {
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->dropForeign(['quote_waived_by']);
            $table->dropColumn([
                'quote_requirement_waived',
                'quote_waiver_billing_amount',
                'quote_waiver_reason',
                'quote_waived_by',
                'quote_waived_at',
            ]);
        });
    }
};
