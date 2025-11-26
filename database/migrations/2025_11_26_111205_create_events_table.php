<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('event_name');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('color');
            $table->boolean('is_all_day')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('events');
    }
};