<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_dependencies')
            ->where('dependency_type', 'blocked_by')
            ->update(['dependency_type' => 'blocks']);

        DB::statement("ALTER TABLE task_dependencies MODIFY COLUMN dependency_type ENUM('blocks', 'relates_to', 'duplicates') DEFAULT 'blocks'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE task_dependencies MODIFY COLUMN dependency_type ENUM('blocks', 'blocked_by', 'relates_to', 'duplicates') DEFAULT 'blocks'");
    }
};
