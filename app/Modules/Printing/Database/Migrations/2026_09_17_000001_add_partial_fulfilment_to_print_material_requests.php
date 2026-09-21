<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE print_material_requests MODIFY status ENUM('draft','requested','approved','issued','received','awaiting_stores','awaiting_purchase','partially_fulfilled','fulfilled','rejected','cancelled') NOT NULL DEFAULT 'awaiting_stores'");
        DB::table('print_material_requests')->where('status', 'requested')->update(['status' => 'awaiting_stores']);
        DB::table('print_material_requests')->where('status', 'received')->update(['status' => 'fulfilled']);

        // MySQL may leave the table behind when a constraint fails during CREATE TABLE.
        Schema::dropIfExists('print_material_request_fulfilments');
        Schema::create('print_material_request_fulfilments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_material_request_id');
            $table->foreign('print_material_request_id', 'pmrf_request_fk')
                ->references('id')->on('print_material_requests')->cascadeOnDelete();
            $table->foreignId('inventory_log_id')->unique();
            $table->foreign('inventory_log_id', 'pmrf_inventory_log_fk')
                ->references('id')->on('inventory_logs')->restrictOnDelete();
            $table->decimal('issued_quantity_m', 12, 3);
            $table->foreignId('issued_by')->nullable();
            $table->foreign('issued_by', 'pmrf_issued_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['print_material_request_id', 'issued_at'], 'print_request_fulfilment_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_material_request_fulfilments');
        DB::table('print_material_requests')->whereIn('status', ['awaiting_stores', 'awaiting_purchase', 'partially_fulfilled'])->update(['status' => 'requested']);
        DB::table('print_material_requests')->where('status', 'fulfilled')->update(['status' => 'received']);
        DB::statement("ALTER TABLE print_material_requests MODIFY status ENUM('draft','requested','approved','issued','received','rejected','cancelled') NOT NULL DEFAULT 'requested'");
    }
};
