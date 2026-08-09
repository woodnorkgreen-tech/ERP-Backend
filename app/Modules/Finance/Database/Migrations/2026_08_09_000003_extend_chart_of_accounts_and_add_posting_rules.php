<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the chart of accounts postable, and moves the debit/credit decision out
 * of code and into data.
 *
 * `chart_of_accounts` holds 120 seeded rows today but has never received a
 * posting anywhere in the application — it is a dropdown source. These columns
 * are what a journal needs before it can reference an account at all.
 *
 * NOTE FOR FINANCE: the seeded chart uses a `COS-001` / `ADM-002` scheme, while
 * the WNG expense catalogue references a numeric one (`1211 Project WIP – Direct
 * Materials`, `1030 Petty Cash Float`). Those are two different charts. Either
 * the chart is reseeded to the numeric scheme or the catalogue's GL strings are
 * mapped onto the existing codes — this schema supports both, because expense
 * codes keep the catalogue string verbatim alongside a nullable account FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // The brief's §2 A–E grouping, kept separate from the existing
            // `category` (asset/liability/expense) which stays the standard
            // financial-statement classification.
            $table->enum('account_type', [
                'balance_sheet', 'direct_cost', 'overhead', 'opex', 'capex', 'revenue',
            ])->nullable()->after('category');

            // Required to validate a journal: without it there is no way to check
            // that a debit actually increases the account it claims to.
            $table->enum('normal_balance', ['debit', 'credit'])->nullable()->after('account_type');

            // The seeded names are colon-nested ("Cost of Sales:Branding"),
            // i.e. already a hierarchy expressed as text. Making it structural is
            // what allows report rollups without string parsing.
            $table->foreignId('parent_id')->nullable()->after('normal_balance')
                ->constrained('chart_of_accounts')->nullOnDelete();

            // Header accounts aggregate; only leaves accept entries.
            $table->boolean('is_postable')->default(true)->after('parent_id');
        });

        // Where money physically comes from. Each float, bank account or till is a
        // row carrying its own GL account, so opening a second petty-cash float or
        // a new bank account is a data change, not a code change.
        Schema::create('payment_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->enum('type', ['petty_cash', 'bank', 'mobile_money', 'card', 'payable']);
            $table->foreignId('gl_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedBigInteger('custodian_user_id')->nullable()->index();
            $table->decimal('float_limit', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The debit/credit pair for an event, as data.
        //
        // Resolution is most-specific-wins by `priority`, filtered to rules whose
        // effective window contains the transaction date. A null `expense_code_id`
        // is a catch-all; a null `credit_account_id` means "credit whatever the
        // payment source's GL account is", which covers the ordinary payment case
        // without a row per expense code.
        Schema::create('posting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_code_id')->nullable();   // FK added with expense_codes
            $table->string('voucher_type', 32)->nullable();
            $table->foreignId('payment_source_id')->nullable()->constrained('payment_sources')->nullOnDelete();
            $table->foreignId('debit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['voucher_type', 'priority']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('payment_sources');

        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['account_type', 'normal_balance', 'is_postable']);
        });
    }
};
