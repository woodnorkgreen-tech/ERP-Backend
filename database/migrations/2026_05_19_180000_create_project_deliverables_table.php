<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained('project_enquiries')->onDelete('cascade');
            $table->string('uuid', 36)->unique();
            $table->string('name');
            $table->string('classification');
            $table->string('status')->default('original');
            $table->timestamps();

            $table->index('uuid');
            $table->index('enquiry_id');
        });

        // Run data migration for existing historical project scope items
        try {
            $enquiries = DB::table('project_enquiries')->get();
            foreach ($enquiries as $enquiry) {
                // Determine scope source (project_scope JSON field or fallback project_deliverables text field)
                $rawScope = $enquiry->project_scope ?? $enquiry->project_deliverables;
                if (empty($rawScope)) {
                    continue;
                }

                // Check if it is a JSON array
                $items = [];
                if (str_starts_with($rawScope, '[')) {
                    $decoded = json_decode($rawScope, true);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                } else {
                    // Split pipe-separated string using standard delimiter lookahead
                    $items = preg_split('/\s*\|\s*(?=\[[^\]]+\])/', $rawScope);
                }

                if (empty($items)) {
                    // Simple text split as final fallback
                    $items = array_filter(array_map('trim', explode(',', $rawScope)));
                }

                foreach ($items as $item) {
                    $item = trim($item);
                    if (empty($item)) continue;

                    // Defaults
                    $classification = 'PRE-DEFINED';
                    $name = $item;
                    $status = 'original';
                    $uuid = Str::uuid()->toString();

                    // Parse parts: [CLASSIFICATION] Name | status:X | id:Y
                    $parts = array_map('trim', explode('|', $item));
                    $mainPart = $parts[0];

                    // Extract type prefix like "[CLASSIFICATION] Name"
                    if (preg_match('/^\[(.*?)\]\s*(.*)$/', $mainPart, $matches)) {
                        $classification = trim($matches[1]);
                        $name = trim($matches[2]);
                    }

                    foreach ($parts as $part) {
                        if (str_starts_with($part, 'status:')) {
                            $status = trim(str_replace('status:', '', $part));
                        } elseif (str_starts_with($part, 'id:')) {
                            $uuid = trim(str_replace('id:', '', $part));
                        }
                    }

                    // Insert database row
                    DB::table('project_deliverables')->insert([
                        'enquiry_id' => $enquiry->id,
                        'uuid' => $uuid,
                        'name' => $name,
                        'classification' => strtoupper($classification),
                        'status' => $status,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('Failed to migrate legacy project deliverables: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_deliverables');
    }
};
