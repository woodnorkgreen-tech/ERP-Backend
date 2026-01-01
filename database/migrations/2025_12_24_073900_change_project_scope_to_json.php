<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, convert existing text data to JSON array format
        DB::statement("
            UPDATE project_enquiries 
            SET project_scope = JSON_ARRAY(project_scope) 
            WHERE project_scope IS NOT NULL 
            AND project_scope != ''
            AND JSON_VALID(project_scope) = 0
        ");

        // Now change the column type to JSON
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->json('project_scope')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert JSON arrays back to text
        $enquiries = DB::table('project_enquiries')
            ->whereNotNull('project_scope')
            ->get();

        foreach ($enquiries as $enquiry) {
            if ($enquiry->project_scope) {
                $decoded = json_decode($enquiry->project_scope, true);
                if (is_array($decoded)) {
                    $textValue = implode(', ', $decoded);
                    DB::table('project_enquiries')
                        ->where('id', $enquiry->id)
                        ->update(['project_scope' => $textValue]);
                }
            }
        }

        // Change back to text
        Schema::table('project_enquiries', function (Blueprint $table) {
            $table->text('project_scope')->nullable()->change();
        });
    }
};
