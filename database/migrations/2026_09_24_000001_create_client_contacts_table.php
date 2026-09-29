<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20);
            $table->string('job_title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['client_id', 'is_primary']);
        });

        DB::table('clients')
            ->whereIn('customer_type', ['company', 'organization'])
            ->whereNotNull('contact_person')
            ->where('contact_person', '!=', '')
            ->get(['id', 'contact_person', 'email', 'phone'])
            ->each(function (object $client): void {
                DB::table('client_contacts')->insert([
                    'client_id' => $client->id,
                    'name' => $client->contact_person,
                    'email' => $client->email,
                    'phone' => $client->phone,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
    }
};
