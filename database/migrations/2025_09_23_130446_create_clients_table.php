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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('contact_person')->nullable();
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('alt_contact')->nullable();
            $table->text('address');
            $table->string('city');
            $table->string('county');
            $table->string('postal_address')->nullable();
            $table->enum('customer_type', ['individual', 'company', 'organization']);
            $table->string('lead_source');
            $table->enum('preferred_contact', ['email', 'phone', 'sms']);
            $table->string('industry')->nullable();
            $table->date('registration_date');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('company_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
