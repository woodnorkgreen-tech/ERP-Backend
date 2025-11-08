<?php

use Illuminate\Support\Facades\DB;
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
        DB::table('site_surveys')->where('id', 22)->update(['enquiry_task_id' => 197]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('site_surveys')->where('id', 22)->update(['enquiry_task_id' => null]);
    }
};
