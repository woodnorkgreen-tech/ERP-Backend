<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Modules\HR\Models\HRActionType;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        HRActionType::updateOrCreate(
            ['code' => 'PROFILE_UPDATE'],
            [
                'name' => 'Profile Update Request',
                'description' => 'Employee self-service profile update request',
                'is_active' => true
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        HRActionType::where('code', 'PROFILE_UPDATE')->delete();
    }
};
