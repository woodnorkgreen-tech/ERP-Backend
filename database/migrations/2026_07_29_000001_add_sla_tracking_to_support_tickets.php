<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->timestamp('first_response_at')->nullable()->after('last_activity_at');
            $table->timestamp('response_due_at')->nullable()->after('first_response_at');
            $table->timestamp('resolution_due_at')->nullable()->after('response_due_at');
            $table->index(['status', 'resolution_due_at'], 'support_tickets_resolution_due_index');
        });

        $targets = ['urgent' => [1, 4], 'high' => [4, 12], 'normal' => [8, 24], 'low' => [16, 48]];
        DB::table('support_tickets')->select(['id', 'priority', 'created_at'])->orderBy('id')->chunkById(200, function ($tickets) use ($targets) {
            foreach ($tickets as $ticket) {
                [$responseHours, $resolutionHours] = $targets[$ticket->priority] ?? $targets['normal'];
                $createdAt = Carbon::parse($ticket->created_at);
                DB::table('support_tickets')->where('id', $ticket->id)->update([
                    'response_due_at' => $createdAt->copy()->addHours($responseHours),
                    'resolution_due_at' => $createdAt->copy()->addHours($resolutionHours),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_resolution_due_index');
            $table->dropColumn(['first_response_at', 'response_due_at', 'resolution_due_at']);
        });
    }
};
