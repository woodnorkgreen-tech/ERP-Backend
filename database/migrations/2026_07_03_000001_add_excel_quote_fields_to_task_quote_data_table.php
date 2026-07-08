<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            // 'built_in' = quote built inside the system (existing path)
            // 'excel_upload' = team uploaded an Excel file and entered the total
            $table->enum('quote_mode', ['built_in', 'excel_upload'])->default('built_in')->after('quote_amount');
            $table->string('excel_quote_file')->nullable()->after('quote_mode');
            $table->string('excel_quote_filename')->nullable()->after('excel_quote_file');
            $table->decimal('excel_quote_amount', 15, 2)->nullable()->after('excel_quote_filename');
            $table->unsignedBigInteger('excel_quote_uploaded_by')->nullable()->after('excel_quote_amount');
            $table->timestamp('excel_quote_uploaded_at')->nullable()->after('excel_quote_uploaded_by');

            $table->foreign('excel_quote_uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_quote_data', function (Blueprint $table) {
            $table->dropForeign(['excel_quote_uploaded_by']);
            $table->dropColumn([
                'quote_mode',
                'excel_quote_file',
                'excel_quote_filename',
                'excel_quote_amount',
                'excel_quote_uploaded_by',
                'excel_quote_uploaded_at',
            ]);
        });
    }
};
