<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->string('outsourced_partner_name')->nullable()->after('operator_id');
            $table->string('outsourced_reason', 40)->nullable()->after('outsourced_partner_name')->index();
            $table->text('outsourced_reason_note')->nullable()->after('outsourced_reason');
            $table->foreignId('outsourced_by')->nullable()->after('outsourced_reason_note')->constrained('users')->nullOnDelete();
            $table->timestamp('outsourced_at')->nullable()->after('outsourced_by');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropForeign(['outsourced_by']);
            $table->dropIndex(['outsourced_reason']);
            $table->dropColumn([
                'outsourced_partner_name',
                'outsourced_reason',
                'outsourced_reason_note',
                'outsourced_by',
                'outsourced_at',
            ]);
        });
    }
};
