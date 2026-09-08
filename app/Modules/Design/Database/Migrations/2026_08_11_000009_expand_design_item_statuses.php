<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXPANDED_STATUSES = [
        'pending',
        'in_design',
        'submitted',
        'awaiting_client_approval',
        'client_approved',
        'client_changes_requested',
        'approved',
        'done',
        'not_approved',
        'cancelled',
        'print_ready',
        'production_ready',
        'handed_off',
    ];

    private const ORIGINAL_STATUSES = [
        'pending',
        'in_design',
        'submitted',
        'approved',
        'not_approved',
        'cancelled',
        'print_ready',
        'production_ready',
        'handed_off',
    ];

    public function up(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            $table->enum('status', self::EXPANDED_STATUSES)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('design_items', function (Blueprint $table) {
            $table->enum('status', self::ORIGINAL_STATUSES)->default('pending')->change();
        });
    }
};
