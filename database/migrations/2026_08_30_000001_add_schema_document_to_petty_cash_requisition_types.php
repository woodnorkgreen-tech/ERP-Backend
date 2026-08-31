<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the requisition schema work: the shape of the form becomes data.
 *
 * `request_fields` and `item_fields` could say which extra questions a type
 * asks, but not where they sit, when they appear, or how wide they are — the
 * form's three sections were a literal array in a Vue computed. This adds one
 * versioned schema document that owns all of it.
 *
 * The old columns are deliberately kept. A type with no `schema` is read
 * through a synthesiser that builds a v2 document out of them, so nothing has
 * to be back-filled and no requisition written before today changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_requisition_types', function (Blueprint $table) {
            $table->json('schema')->nullable()->after('item_fields');
            $table->unsignedSmallInteger('schema_version')->default(1)->after('schema');
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_requisition_types', function (Blueprint $table) {
            $table->dropColumn(['schema', 'schema_version']);
        });
    }
};
