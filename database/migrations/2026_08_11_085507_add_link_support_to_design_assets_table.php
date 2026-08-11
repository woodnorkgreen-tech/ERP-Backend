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
        Schema::table('design_assets', function (Blueprint $table) {
            $table->enum('source', ['file', 'link'])->default('file')->after('enquiry_task_id');
            $table->string('external_url')->nullable()->after('file_path');
            $table->string('file_path')->nullable()->change();
            $table->bigInteger('file_size')->nullable()->change();
            $table->string('mime_type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('design_assets', function (Blueprint $table) {
            $table->dropColumn(['source', 'external_url']);
            $table->string('file_path')->nullable(false)->change();
            $table->bigInteger('file_size')->nullable(false)->change();
            $table->string('mime_type')->nullable(false)->change();
        });
    }
};
