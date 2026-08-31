<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The expense catalogue — WNG's posting-rule sheet held as data.
 *
 * A person spending money chooses exactly one thing: `expense_type` in plain
 * language ("MDF boards", "Petty-cash float top-up"). Every accounting
 * consequence — GL account, cost centre, activity, VAT and WHT treatment,
 * whether a Job ID is even permitted, which evidence is mandatory, which extra
 * operational fields the form must ask for — is read off this row.
 *
 * Nobody outside Finance ever sees a GL account. Adding an expense type, or
 * changing what one of them requires, is a row edit.
 *
 * Two json columns carry the dynamic form, and their shape is the contract that
 * makes new expense types cost nothing to add:
 *
 *   extra_operational_data — fields the capture form renders, in order
 *     [{"key":"item_code","label":"Item code","type":"lookup",
 *       "source":"library_materials","required":true},
 *      {"key":"quantity","label":"Quantity","type":"number","required":true,"min":0},
 *      {"key":"uom","label":"Unit","type":"select",
 *       "options":["pcs","m","m2","kg","litre"],"required":true}]
 *
 *     type ∈ text | textarea | number | date | select | lookup | boolean
 *     `source` names a resolver the API exposes; `options` is for fixed lists.
 *
 *   minimum_evidence — attachments the line cannot be verified without
 *     [{"key":"etims_invoice","label":"eTIMS invoice","required":true},
 *      {"key":"grn","label":"Delivery note / GRN","required":true}]
 *
 * Both are validated against that schema on write, so Finance cannot author a
 * form the mobile client is unable to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();          // NE-001, DM-WD-001, ...
            $table->string('accounting_class');
            $table->string('expense_family')->index();
            $table->string('expense_type');                // what the user actually picks
            $table->text('simple_meaning')->nullable();
            $table->text('example')->nullable();
            $table->text('recording_rule')->nullable();

            // The catalogue names its account as free text ("1211 Project WIP –
            // Direct Materials"). That string is kept verbatim for traceability
            // because it is the authority until the chart of accounts is
            // reconciled to the same numbering; the FK is populated once it is.
            $table->string('default_debit_gl')->nullable();
            $table->foreignId('default_debit_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // Normalised for validation, plus the sheet's own wording. Phrasings
            // like "Not required at purchase; required at issue" and "Optional only
            // if built for a project" cannot survive a four-value enum, but a
            // reviewer still needs to read them, so both are stored.
            $table->enum('job_id_rule', ['required', 'optional', 'not_allowed', 'conditional'])
                ->default('optional');
            $table->string('job_id_rule_note')->nullable();

            $table->string('default_cost_centre')->nullable();
            $table->foreignId('default_cost_centre_id')->nullable()
                ->constrained('cost_centres')->nullOnDelete();

            $table->string('project_activity')->nullable();
            $table->foreignId('default_activity_id')->nullable()
                ->constrained('activities')->nullOnDelete();

            $table->string('inventory_treatment')->nullable();

            $table->string('vat_default')->nullable();
            $table->string('default_vat_treatment_code', 32)->nullable();

            $table->string('wht_review')->nullable();
            $table->string('default_wht_category_code', 32)->nullable();

            $table->json('minimum_evidence')->nullable();
            $table->json('extra_operational_data')->nullable();

            $table->text('key_control')->nullable();
            $table->string('pl_report_line')->nullable();

            // Fixed by IAS 7, so an enum here is correct — unlike every other
            // classification in this module, the business cannot add a fifth.
            $table->enum('cash_flow_class', ['operating', 'investing', 'financing', 'none'])
                ->default('operating');
            $table->string('cash_flow_note')->nullable();

            // Capture-time behaviour, derived from the catalogue rather than from
            // branching on the code prefix anywhere in the application.
            $table->boolean('requires_asset_record')->default(false);
            $table->boolean('requires_supplier')->default(false);
            $table->boolean('is_capex_review')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['accounting_class', 'is_active']);
            $table->index(['expense_family', 'is_active']);
        });

        // Deferred from the posting-rules migration, which runs before this table
        // exists.
        Schema::table('posting_rules', function (Blueprint $table) {
            $table->foreign('expense_code_id')->references('id')->on('expense_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posting_rules', function (Blueprint $table) {
            $table->dropForeign(['expense_code_id']);
        });

        Schema::dropIfExists('expense_codes');
    }
};
