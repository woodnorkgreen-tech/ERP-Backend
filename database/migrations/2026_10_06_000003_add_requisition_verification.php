<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->foreignId('responsible_verifier_id')->nullable()->constrained('users')->restrictOnDelete();
            // Existing documents remain explicitly unverified; no historical certification is fabricated.
            $table->string('verification_status', 40)->nullable()->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_comment')->nullable();
            $table->string('verification_fingerprint', 64)->nullable();
        });
        Permission::findOrCreate('finance.requisitions.verify', 'web');
        // Authority is assigned deliberately through the existing role editor.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_verifier_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verification_status', 'verified_at', 'verification_comment', 'verification_fingerprint']);
        });
        Permission::where('name', 'finance.requisitions.verify')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
