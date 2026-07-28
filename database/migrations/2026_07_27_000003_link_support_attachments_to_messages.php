<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            $table->foreignId('message_id')
                ->nullable()
                ->after('support_ticket_id')
                ->constrained('support_ticket_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('message_id');
        });
    }
};
