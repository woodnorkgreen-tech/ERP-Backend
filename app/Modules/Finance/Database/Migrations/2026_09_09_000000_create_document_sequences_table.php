<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One counter per document series, so a document number is issued once.
 *
 * `PAY-` was previously derived three separate ways — BillPayment::boot from
 * `substr()` of the highest existing code, and twice more inline in
 * BillController from `max(id) + 1`. All three read-then-write without a lock,
 * so two concurrent payments could be issued the same number, and the substr
 * form silently breaks the moment the prefix length changes.
 *
 * Rows are claimed with SELECT ... FOR UPDATE, which is why this is a table and
 * not a computed max().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            // e.g. 'PAY'. The series a number belongs to.
            $table->string('prefix', 16);
            // The reset window — a calendar year for most series. Series that
            // never reset use a single fixed period.
            $table->string('period', 16);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['prefix', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
