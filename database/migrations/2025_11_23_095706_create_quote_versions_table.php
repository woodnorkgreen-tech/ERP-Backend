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
        Schema::create('quote_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_quote_data_id');
            $table->integer('version_number');
            $table->string('label')->nullable();
            $table->json('data'); // Snapshot of the quote data
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('task_quote_data_id')->references('id')->on('task_quote_data')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quote_versions');
    }
};
