<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('is_minute')->default(false)->after('is_public');
            $table->text('agenda')->nullable()->after('is_minute');
            $table->string('recipient_type')->default('all')->after('agenda');
            $table->json('attendees')->nullable()->after('recipient_type');
            $table->json('department_ids')->nullable()->after('attendees');
        });
    }

    public function down()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['is_minute', 'agenda', 'recipient_type', 'attendees', 'department_ids']);
        });
    }
};