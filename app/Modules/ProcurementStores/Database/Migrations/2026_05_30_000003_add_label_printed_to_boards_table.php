<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->boolean('label_printed')->default(false)->after('is_offcut');
            $table->string('label_printed_by')->nullable()->after('label_printed');
            $table->timestamp('label_printed_at')->nullable()->after('label_printed_by');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropColumn(['label_printed', 'label_printed_by', 'label_printed_at']);
        });
    }
};
