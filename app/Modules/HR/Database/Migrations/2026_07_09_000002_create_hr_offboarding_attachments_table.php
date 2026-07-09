<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_offboarding_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('offboarding_case_id');
            $table->enum('category', ['asset_return', 'task', 'clearance', 'settlement']);
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['offboarding_case_id', 'category', 'related_id'], 'hr_offb_attachments_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offboarding_attachments');
    }
};
