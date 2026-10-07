<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Report 75: Finance Configuration & Governance.
 *
 * One mechanism for every Finance decision that changes how the books behave:
 * a proposal is drafted, submitted, reviewed, approved, given a date and only
 * then activated. Nothing here holds a decision yet. The tables arrive empty,
 * and every existing recommendation (the chart profile's mappings, its
 * suggested WIP policy, the seeded settings) stays what it was: input for
 * review, not an approval.
 *
 * What the items ARE is defined in code (GovernanceCatalogue), because it is
 * derived from things that already exist: the 37 posting functions, the
 * finance_settings rows, the paying accounts, the tax tables. Only what people
 * decide about them is stored.
 *
 * Constraint and index names are given explicitly: MariaDB caps identifiers at
 * 64 characters and the generated names for these tables exceed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_config_versions', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 120);
            $table->string('domain', 40);
            $table->unsignedInteger('version');
            $table->json('value');
            $table->text('reason')->nullable();
            // The date Finance asked for: "not before".
            $table->date('effective_from')->nullable();
            // When it actually governs. Set at activation, never earlier than the
            // day it was activated, so no past transaction changes meaning.
            $table->date('in_force_from')->nullable();
            $table->date('in_force_to')->nullable();
            $table->string('status', 24)->default('draft');
            // Bumped on every change, and quoted back by whoever acts on it.
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('proposed_by')->nullable()->constrained('users', 'id', 'fin_cfg_ver_proposed_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users', 'id', 'fin_cfg_ver_reviewed_fk')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users', 'id', 'fin_cfg_ver_decided_fk')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users', 'id', 'fin_cfg_ver_activated_fk')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            // The item key while this version is still a proposal, NULL once it is
            // decided. Unique: one open proposal per item, enforced by the
            // database and not only by the code that checks first.
            $table->string('open_key', 120)->nullable();
            // "<item>|<in_force_from>" once activated: no two versions of one item
            // can come into force on the same day.
            $table->string('active_key', 140)->nullable();
            $table->timestamps();

            $table->unique(['item_key', 'version'], 'fin_cfg_ver_item_version_uq');
            $table->unique('open_key', 'fin_cfg_ver_open_uq');
            $table->unique('active_key', 'fin_cfg_ver_active_uq');
            $table->index(['domain', 'status'], 'fin_cfg_ver_domain_status_ix');
            $table->index(['item_key', 'in_force_from'], 'fin_cfg_ver_item_force_ix');
        });

        Schema::create('finance_config_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('finance_config_versions', 'id', 'fin_cfg_apr_version_fk')->cascadeOnDelete();
            $table->string('requirement', 40);
            $table->string('decision', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'fin_cfg_apr_actor_fk')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['version_id', 'decision'], 'fin_cfg_apr_version_ix');
        });

        // Append-only. Nothing updates or deletes a row here.
        Schema::create('finance_config_audit', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 120);
            $table->unsignedBigInteger('version_id')->nullable();
            $table->string('action', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users', 'id', 'fin_cfg_aud_actor_fk')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['item_key', 'id'], 'fin_cfg_aud_item_ix');
            $table->index('version_id', 'fin_cfg_aud_version_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_config_audit');
        Schema::dropIfExists('finance_config_approvals');
        Schema::dropIfExists('finance_config_versions');
    }
};
