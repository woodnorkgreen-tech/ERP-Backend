<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('production_ncrs')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            if (! Schema::hasColumn('production_ncrs', 'reinspection_performed_status')) {
                $table->string('reinspection_performed_status', 20)->nullable()->after('reinspection_performed');
            }

            if (! Schema::hasColumn('production_ncrs', 'reinspection_performed_other')) {
                $table->string('reinspection_performed_other', 255)->nullable()->after('reinspection_performed_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('production_ncrs')) {
            return;
        }

        Schema::table('production_ncrs', function (Blueprint $table) {
            $drops = [];
            if (Schema::hasColumn('production_ncrs', 'reinspection_performed_other')) {
                $drops[] = 'reinspection_performed_other';
            }
            if (Schema::hasColumn('production_ncrs', 'reinspection_performed_status')) {
                $drops[] = 'reinspection_performed_status';
            }
            if (! empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};
