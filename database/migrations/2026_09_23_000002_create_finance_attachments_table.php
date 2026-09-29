<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Finance evidence/attachment mechanism (shared foundation for
 * confirmed W1-2, W1-6, and — once built — W2-2/W3-4/W4).
 *
 * Deliberately one polymorphic table rather than BillAttachment /
 * PettyCashAttachment / VoucherAttachment, per the confirmed direction: the
 * mechanism enables evidence, policy (still open in W2-2/W3-4) decides when
 * it is mandatory. `source_type`/`source_id` follow the same convention
 * already used throughout Cost Collector (`cost_lines.source_type`,
 * `journal_entries.source_type`), not a new naming scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            // e.g. 'no_quote_exception_evidence', 'credit_note_evidence',
            // 'supplier_invoice_scan' — an open vocabulary, not an enum, since
            // W2-2/W3-4 have not yet confirmed the evidence-type taxonomy.
            $table->string('evidence_type', 100)->nullable();
            // A file actually stored via this mechanism.
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            // A reference where no file exists yet, or evidence lives outside
            // the ERP (e.g. a physical receipt number) — enabling evidence
            // does not require every caller to have a file.
            $table->string('reference', 255)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_attachments');
    }
};
