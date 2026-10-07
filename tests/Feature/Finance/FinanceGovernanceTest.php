<?php

namespace Tests\Feature\Finance;

use App\Constants\Permissions;
use App\Constants\RolePermissions;
use App\Models\User;
use App\Modules\Finance\Database\Seeders\AccountingPeriodSeeder;
use App\Modules\Finance\Database\Seeders\ExpenseCodeSeeder;
use App\Modules\Finance\Database\Seeders\FinanceSettingsSeeder;
use App\Modules\Finance\Database\Seeders\FinanceTaxSeeder;
use App\Modules\Finance\Database\Seeders\PaymentSourceSeeder;
use App\Modules\Finance\Governance\FinanceConfigVersion;
use App\Modules\Finance\Governance\GovernanceRuntime;
use App\Modules\Finance\Models\FinanceSetting;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\Finance\Support\ChartAccountMap;
use App\Modules\Finance\Support\FinanceAccountFunctions;
use App\Modules\Finance\Support\FinanceChartProfile;
use App\Modules\Finance\Support\FinanceReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Report 75: Finance configuration is proposed, reviewed, approved, dated and
 * activated; nothing a person clicks changes the books before that.
 *
 * The fixture is WNG's completed chart with its profile active and NO WIP policy
 * anywhere, which is the state the cutover target will be in on the day these
 * decisions have to be made.
 */
class FinanceGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $preparer;
    protected User $reviewer;
    protected User $accountant;
    protected User $manager;
    protected User $financeApprover;
    protected User $activator;
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(9, 0));
        FinanceChartProfile::flush();

        // WNG's chart, completed.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('expense_codes')->update(['default_debit_account_id' => null]);
        DB::table('chart_of_accounts')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/wng_chart_of_accounts.json')), true)['accounts'] as $account) {
            DB::table('chart_of_accounts')->insert($account + ['created_at' => now(), 'updated_at' => now()]);
        }
        $this->artisan('finance:complete-chart', ['--profile' => 'wng', '--execute' => true, '--confirm' => DB::selectOne('SELECT DATABASE() AS db')->db])->assertSuccessful();
        $this->activateProfile(null);
        $this->seed([PaymentSourceSeeder::class, FinanceTaxSeeder::class, FinanceSettingsSeeder::class, AccountingPeriodSeeder::class]);

        foreach ([...array_filter(Permissions::all(), fn ($p) => str_starts_with($p, 'finance.config.')), Permissions::APPROVALS_SELF_APPROVE, Permissions::FINANCE_REPORTS_VIEW] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = function (array $permissions) {
            $user = User::factory()->create(['is_active' => true]);
            $user->givePermissionTo($permissions);

            return $user;
        };
        $view = Permissions::FINANCE_CONFIG_VIEW;
        $this->preparer = $user([$view, Permissions::FINANCE_CONFIG_PROPOSE]);
        $this->reviewer = $user([$view, Permissions::FINANCE_CONFIG_REVIEW]);
        $this->accountant = $user([$view, Permissions::FINANCE_CONFIG_APPROVE_ACCOUNTING]);
        $this->manager = $user([$view, Permissions::FINANCE_CONFIG_APPROVE_MANAGEMENT]);
        $this->financeApprover = $user([$view, Permissions::FINANCE_CONFIG_APPROVE_OPERATIONAL]);
        $this->activator = $user([$view, Permissions::FINANCE_CONFIG_ACTIVATE]);

        // A Super Admin exactly as production makes one: the role, with the role's grants.
        $role = Role::findOrCreate('Super Admin', 'web');
        foreach (RolePermissions::matrix()['Super Admin'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions(RolePermissions::matrix()['Super Admin']);
        $this->superAdmin = User::factory()->create(['is_active' => true]);
        $this->superAdmin->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function activateProfile(?string $deploymentPolicy, string $authority = 'transition'): void
    {
        config([
            'finance_accounts.profile' => 'wng',
            'finance_accounts.wip_policy' => $deploymentPolicy,
            'finance_accounts.wip_policy_authority' => $authority,
            'finance_accounts.map' => FinanceChartProfile::map('wng', $deploymentPolicy),
            'finance_accounts.payment_sources' => FinanceChartProfile::paymentSources('wng'),
        ]);
        GovernanceRuntime::flush();
    }

    // ---- Helpers: the API, as each person --------------------------------------

    protected function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    protected function item(string $key, ?User $as = null): array
    {
        return $this->as($as ?? $this->preparer)->getJson("/api/finance/governance/items/{$key}")->assertOk()->json('data');
    }

    protected function propose(string $key, array $value, array $more = [], ?User $as = null)
    {
        return $this->as($as ?? $this->preparer)->postJson("/api/finance/governance/items/{$key}/proposals",
            $more + ['value' => $value, 'reason' => 'Agreed at the Finance meeting.', 'effective_from' => '2026-10-06', 'submit' => true]);
    }

    protected function act(User $user, array $proposal, string $action, array $more = [])
    {
        return $this->as($user)->postJson("/api/finance/governance/proposals/{$proposal['id']}/{$action}", $more + ['revision' => $proposal['revision']]);
    }

    /** Propose, approve and activate, each by the right person. Returns the item afterwards. */
    protected function decide(string $key, array $value, ?User $approver = null, string $from = '2026-10-06'): array
    {
        $proposal = $this->propose($key, $value, ['effective_from' => $from])->assertCreated()->json('data.proposal');
        $proposal = $this->act($approver ?? $this->accountant, $proposal, 'approve')->assertOk()->json('data.proposal');

        return $this->act($this->activator, $proposal, 'activate')->assertOk()->json('data');
    }

    protected function wip(string $choice = 'capitalise'): array
    {
        return ['choice' => $choice];
    }

    protected function postingBlock(): ?string
    {
        GovernanceRuntime::flush();

        return FinanceChartProfile::wipPolicyBlock();
    }

    // ---- Lifecycle -----------------------------------------------------------

    public function test_a_proposal_moves_from_draft_through_approval_to_active_and_only_then_changes_anything(): void
    {
        $this->assertSame('decision_required', $this->item('policy.wip')['state']);
        $this->assertNull($this->item('policy.wip')['suggestion'], 'the profile default is not put forward as the answer');

        // Draft: saved, editable, changes nothing.
        $draft = $this->propose('policy.wip', $this->wip(), ['submit' => false, 'reason' => null, 'effective_from' => null])->assertCreated()->json('data');
        $this->assertSame('draft', $draft['state']);
        $this->assertSame(1, $draft['proposal']['version']);
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock());

        $edited = $this->as($this->preparer)->putJson("/api/finance/governance/proposals/{$draft['proposal']['id']}", [
            'revision' => $draft['proposal']['revision'], 'value' => $this->wip('expense_on_capture'), 'reason' => 'Costs follow the invoice closely.', 'effective_from' => '2026-11-01',
        ])->assertOk()->json('data.proposal');
        $this->assertSame('Recognise as project cost immediately', $edited['label']);
        $this->assertSame($draft['proposal']['revision'] + 1, $edited['revision']);

        $submitted = $this->act($this->preparer, $edited, 'submit')->assertOk()->json('data');
        $this->assertSame('awaiting_approval', $submitted['state']);
        $this->assertSame('The accountant', $submitted['blocked']['who']);

        $reviewing = $this->act($this->reviewer, $submitted['proposal'], 'review')->assertOk()->json('data.proposal');
        $this->assertSame('under_review', $reviewing['status']);

        $approved = $this->act($this->accountant, $reviewing, 'approve', ['comment' => 'Agreed.'])->assertOk()->json('data');
        $this->assertSame('awaiting_activation', $approved['state'], 'approved is not active');
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock(), 'an approved but unactivated policy governs nothing');

        $active = $this->act($this->activator, $approved['proposal'], 'activate')->assertOk()->json('data');
        $this->assertSame('scheduled', $active['state'], 'activated ahead of its date, it waits for the date');
        $this->assertSame('2026-11-01', $active['scheduled']['in_force_from']);
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock());

        $this->travelTo(now()->setDate(2026, 11, 1));
        $this->assertNull($this->postingBlock());
        $this->assertSame('active', $this->item('policy.wip')['state']);
        $this->assertSame('expense_on_capture', GovernanceRuntime::instance()->wipPolicy()['policy']);
    }

    public function test_a_proposal_can_be_returned_corrected_resubmitted_rejected_or_withdrawn(): void
    {
        $submitted = $this->propose('policy.wip', $this->wip())->assertCreated()->json('data.proposal');

        $this->act($this->accountant, $submitted, 'return', ['comment' => ''])->assertStatus(422);
        $returned = $this->act($this->accountant, $submitted, 'return', ['comment' => 'Say which projects this was tested on.'])->assertOk()->json('data');
        $this->assertSame('returned', $returned['state']);
        $this->assertStringContainsString('Say which projects', $returned['blocked']['why']);

        $corrected = $this->as($this->preparer)->putJson("/api/finance/governance/proposals/{$returned['proposal']['id']}", [
            'revision' => $returned['proposal']['revision'], 'value' => $this->wip(), 'reason' => 'Tested on the last ten jobs.', 'effective_from' => '2026-10-06',
        ])->assertOk()->json('data.proposal');
        $resubmitted = $this->act($this->preparer, $corrected, 'submit')->assertOk()->json('data.proposal');
        $this->assertSame(1, $resubmitted['version'], 'a correction is the same version, not a new one');

        $this->act($this->accountant, $resubmitted, 'reject', ['comment' => 'Not this year.'])->assertOk();
        $item = $this->item('policy.wip');
        $this->assertSame('decision_required', $item['state'], 'a rejection leaves the question open');
        $this->assertNull($item['proposal']);
        $this->assertSame('rejected', $item['versions'][0]['status'], 'and the rejected version is kept');

        // A new proposal is version 2; it can be withdrawn by its preparer and is still kept.
        $second = $this->propose('policy.wip', $this->wip('expense_on_capture'))->assertCreated()->json('data.proposal');
        $this->assertSame(2, $second['version']);
        $this->act($this->preparer, $second, 'withdraw')->assertOk();
        $this->assertSame(['withdrawn', 'rejected'], array_column($this->item('policy.wip')['versions'], 'status'));
        $this->assertSame(2, FinanceConfigVersion::where('item_key', 'policy.wip')->count(), 'nothing is deleted');
    }

    public function test_a_new_version_supersedes_the_old_one_on_its_date_and_history_is_never_rewritten(): void
    {
        $first = $this->decide('policy.wip', $this->wip('capitalise'));
        $this->assertSame('active', $first['state']);
        $firstId = $first['active']['id'];

        $second = $this->decide('policy.wip', $this->wip('expense_on_capture'), null, '2027-01-01');
        $this->assertSame('active', $second['state'], 'the first still governs today');
        $this->assertSame('Hold as Work in Progress until release', $second['active']['label']);
        $this->assertSame('2027-01-01', $second['scheduled']['in_force_from']);

        $old = FinanceConfigVersion::findOrFail($firstId);
        $this->assertSame('2026-12-31', $old->in_force_to->toDateString(), 'it ends the day before the next begins');
        $this->assertSame(['choice' => 'capitalise'], $old->value, 'and its value is untouched');
        $this->assertNotNull($old->decided_by);

        // As-of resolution: each date gets the policy that governed it.
        $runtime = GovernanceRuntime::instance();
        $this->assertSame('capitalise', $runtime->value('policy.wip', '2026-12-31')['choice']);
        $this->assertSame('expense_on_capture', $runtime->value('policy.wip', '2027-01-01')['choice']);
        $this->assertNull($runtime->value('policy.wip', '2026-10-05'), 'nothing governed before the first was activated');

        $this->travelTo(now()->setDate(2027, 1, 2));
        $item = $this->item('policy.wip');
        $this->assertSame('Recognise as project cost immediately', $item['active']['label']);
        $this->assertSame(['active', 'superseded'], array_column($item['versions'], 'state'));
        $this->assertStringContainsString('Version 1', $item['story']['replaced']);
    }

    public function test_only_one_proposal_is_open_per_item_and_two_versions_never_start_on_the_same_day(): void
    {
        $this->propose('policy.wip', $this->wip(), ['submit' => false])->assertCreated();
        $this->propose('policy.wip', $this->wip('expense_on_capture'), ['submit' => false])->assertStatus(422);

        // The database refuses it too, not only the check before it.
        $refused = false;
        try {
            DB::table('finance_config_versions')->insert(['item_key' => 'policy.wip', 'domain' => 'policies', 'version' => 9, 'value' => '{}',
                'status' => 'draft', 'open_key' => 'policy.wip', 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Illuminate\Database\QueryException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'A second open proposal was stored.');
        $this->assertSame(1, FinanceConfigVersion::where('item_key', 'policy.wip')->count());
    }

    public function test_a_second_activation_on_the_same_day_is_refused(): void
    {
        $this->decide('policy.wip', $this->wip('capitalise'));
        $proposal = $this->propose('policy.wip', $this->wip('expense_on_capture'))->assertCreated()->json('data.proposal');
        $proposal = $this->act($this->accountant, $proposal, 'approve')->assertOk()->json('data.proposal');

        $this->act($this->activator, $proposal, 'activate')->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->assertSame(1, FinanceConfigVersion::where('item_key', 'policy.wip')->where('status', 'active')->count());
        $this->assertSame('capitalise', GovernanceRuntime::instance()->wipPolicy()['policy']);
    }

    public function test_acting_on_a_proposal_someone_else_has_changed_is_refused(): void
    {
        $submitted = $this->propose('policy.wip', $this->wip())->assertCreated()->json('data.proposal');
        $this->act($this->reviewer, $submitted, 'review')->assertOk();

        // The accountant still has the page from before the review started.
        $this->act($this->accountant, $submitted, 'approve')->assertStatus(409);
        $this->assertSame('under_review', FinanceConfigVersion::findOrFail($submitted['id'])->status);

        // Likewise approving something that was withdrawn in the meantime.
        $fresh = $this->item('policy.wip')['proposal'];
        $this->act($this->accountant, $fresh, 'return', ['comment' => 'Hold on.'])->assertOk();
        $this->act($this->accountant, $fresh, 'approve')->assertStatus(409);
    }

    public function test_the_effective_date_is_required_and_can_never_be_in_the_past(): void
    {
        $this->propose('policy.wip', $this->wip(), ['effective_from' => null])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->propose('policy.wip', $this->wip(), ['effective_from' => '2026-10-05'])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->propose('policy.wip', $this->wip(), ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame(0, FinanceConfigVersion::where('status', '!=', 'draft')->count());

        // Approved for a date that then passes: it comes into force on the day it is activated, never earlier.
        FinanceConfigVersion::query()->delete();
        $proposal = $this->propose('policy.wip', $this->wip())->assertCreated()->json('data.proposal');
        $proposal = $this->act($this->accountant, $proposal, 'approve')->assertOk()->json('data.proposal');
        $this->travelTo(now()->setDate(2026, 10, 20));
        $active = $this->act($this->activator, $proposal, 'activate')->assertOk()->json('data.active');
        $this->assertSame('2026-10-06', $active['effective_from']);
        $this->assertSame('2026-10-20', $active['in_force_from'], 'no transaction before activation changes meaning');
    }

    public function test_every_step_is_audited_with_who_what_and_why(): void
    {
        $proposal = $this->propose('policy.wip', $this->wip(), ['submit' => false])->assertCreated()->json('data.proposal');
        $proposal = $this->act($this->preparer, $proposal, 'submit')->assertOk()->json('data.proposal');
        $proposal = $this->act($this->accountant, $proposal, 'approve', ['comment' => 'Agreed with the auditors.'])->assertOk()->json('data.proposal');
        $this->act($this->activator, $proposal, 'activate')->assertOk();

        $history = $this->item('policy.wip')['history'];
        $this->assertSame(['activated', 'approved', 'submitted', 'created'], array_column($history, 'action'));
        $approved = collect($history)->firstWhere('action', 'approved');
        $this->assertSame($this->accountant->name, $approved['actor']);
        $this->assertSame('Agreed with the auditors.', $approved['reason']);
        $this->assertSame('Hold as Work in Progress until release', $approved['after']);
        $this->assertSame('2026-10-06', collect($history)->firstWhere('action', 'activated')['effective_from']);

        $story = $this->item('policy.wip')['story'];
        $this->assertSame('Hold as Work in Progress until release', $story['what']);
        $this->assertSame($this->preparer->name, $story['who_proposed']);
        $this->assertSame($this->accountant->name, $story['who_approved']);
        $this->assertSame('06-Oct-2026', $story['effective']);
        $this->assertSame('No previous active version.', $story['replaced']);
        $this->assertSame('Active', $story['status']);

        $feed = $this->as($this->preparer)->getJson('/api/finance/governance/history?domain=policies')->assertOk()->json('data');
        $this->assertCount(4, $feed);
        $this->assertSame(1, DB::table('finance_config_approvals')->where('decision', 'approved')->count());
    }

    // ---- Authority -----------------------------------------------------------

    public function test_each_action_needs_its_own_permission(): void
    {
        $outsider = User::factory()->create(['is_active' => true]);
        $this->as($outsider)->getJson('/api/finance/governance')->assertForbidden();
        $this->as($outsider)->getJson('/api/finance/governance/items/policy.wip')->assertForbidden();

        // A viewer may look, and may do nothing else.
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(Permissions::FINANCE_CONFIG_VIEW);
        $this->assertSame(array_fill_keys(['propose', 'edit', 'submit', 'withdraw', 'review', 'return', 'reject', 'approve', 'activate', 'apply_now'], false),
            array_diff_key($this->item('policy.wip', $viewer)['actions'], ['approve_blocked_reason' => 0]));
        $this->propose('policy.wip', $this->wip(), [], $viewer)->assertForbidden();

        $submitted = $this->propose('policy.wip', $this->wip())->assertCreated()->json('data.proposal');
        foreach ([$this->preparer, $this->reviewer, $this->manager, $this->financeApprover, $this->activator] as $notTheAccountant) {
            $this->act($notTheAccountant, $submitted, 'approve')->assertForbidden();
        }
        $this->act($this->preparer, $submitted, 'review')->assertForbidden();
        $approved = $this->act($this->accountant, $submitted, 'approve')->assertOk()->json('data.proposal');

        // Approving is not activating.
        $this->act($this->accountant, $approved, 'activate')->assertForbidden();
        $this->act($this->activator, $approved, 'activate')->assertOk();
    }

    /**
     * WNG's decision (October 2026), replacing Report 75's rule that administering
     * the system conferred no accounting authority: a Super Admin approves and
     * applies Finance setup directly. Nobody else gains anything by it.
     */
    public function test_a_super_admin_approves_and_applies_a_setting_in_one_step(): void
    {
        $this->activateProfile(null);
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock());
        $actions = $this->item('policy.wip', $this->superAdmin)['actions'];
        $this->assertTrue($actions['apply_now']);

        // Not for anyone else, whatever else they hold.
        foreach ([$this->preparer, $this->accountant, $this->activator] as $user) {
            $this->assertFalse($this->item('policy.wip', $user)['actions']['apply_now']);
            $this->as($user)->postJson('/api/finance/governance/items/policy.wip/apply', ['value' => $this->wip()])->assertForbidden();
        }
        $this->assertSame(0, FinanceConfigVersion::count());

        $this->as($this->superAdmin)->postJson('/api/finance/governance/items/policy.wip/apply', ['value' => $this->wip()])
            ->assertOk()->assertJsonPath('data.state', 'active');

        $version = FinanceConfigVersion::where('item_key', 'policy.wip')->sole();
        $this->assertSame(['active', $this->superAdmin->id, $this->superAdmin->id, $this->superAdmin->id],
            [$version->status, $version->proposed_by, $version->decided_by, $version->activated_by]);
        $this->assertSame(now()->toDateString(), $version->in_force_from->toDateString());
        // The whole route is still on record; it was simply walked by one person.
        $this->assertSame(['created', 'submitted', 'approved', 'activated'],
            DB::table('finance_config_audit')->where('version_id', $version->id)->orderBy('id')->pluck('action')->all());
        $this->assertNull($this->postingBlock(), 'The policy is in force, so project costs can post.');

        // Changing it later is the same single step, and the old answer is kept as history.
        $this->as($this->superAdmin)->postJson('/api/finance/governance/items/policy.wip/apply',
            ['value' => ['choice' => \App\Modules\Finance\Support\FinanceChartProfile::WIP_EXPENSE_ON_CAPTURE], 'reason' => 'Agreed with the accountant'])->assertStatus(422);
        $this->assertSame(1, FinanceConfigVersion::where('item_key', 'policy.wip')->count(), 'A second answer cannot start on the same day as the first.');
    }

    public function test_a_super_admin_carries_someone_elses_proposal_through_and_may_approve_their_own(): void
    {
        $submitted = $this->propose('policy.wip', $this->wip())->assertCreated()->json('data.proposal');
        $this->act($this->superAdmin, $submitted, 'approve')->assertOk();
        $this->as($this->superAdmin)->postJson('/api/finance/governance/items/policy.wip/apply', [])->assertOk()->assertJsonPath('data.state', 'active');
        $this->assertSame($this->preparer->id, FinanceConfigVersion::where('item_key', 'policy.wip')->sole()->proposed_by, 'The proposer stays on record.');

        // The overview names Super Admins among those who can decide.
        $authority = collect($this->as($this->superAdmin)->getJson('/api/finance/governance')->json('data.authority'))->keyBy('permission');
        $this->assertContains($this->superAdmin->name, $authority[Permissions::FINANCE_CONFIG_APPROVE_ACCOUNTING]['holders']);
        $this->assertContains($this->superAdmin->name, $authority[Permissions::FINANCE_CONFIG_ACTIVATE]['holders']);
        // The role's stored grants are unchanged: this is a rule about the role, not a new grant.
        foreach (Permissions::accountingAuthority() as $authority) {
            $this->assertNotContains($authority, RolePermissions::matrix()['Super Admin']);
        }
    }

    public function test_a_super_admin_approves_everything_that_has_an_answer_in_one_go(): void
    {
        $this->activateProfile(null);
        $direct = $this->as($this->superAdmin)->getJson('/api/finance/governance')->assertOk()->json('data.direct');
        $this->assertTrue($direct['allowed']);
        $this->assertGreaterThan(30, $direct['ready']);
        $this->assertContains('policy.wip', array_column($direct['needs_answer'], 'key'), 'Nothing is recommended for it, so it is left to a person.');
        $this->assertFalse($this->as($this->accountant)->getJson('/api/finance/governance')->json('data.direct.allowed'));
        $this->as($this->accountant)->postJson('/api/finance/governance/apply-all')->assertForbidden();
        $this->assertSame(0, FinanceConfigVersion::count(), 'Counting what a click would do does nothing.');

        $result = $this->as($this->superAdmin)->postJson('/api/finance/governance/apply-all')->assertOk()->json('data');
        $this->assertGreaterThan(30, count($result['applied']));
        $this->assertContains('policy.wip', array_column($result['needs_answer'], 'key'));
        $this->assertSame(0, FinanceConfigVersion::where('item_key', 'policy.wip')->count(), 'No answer was made up.');
        $this->assertSame(count($result['applied']), FinanceConfigVersion::where('status', 'active')->count());
        $this->assertSame(0, FinanceConfigVersion::whereNotIn('status', ['active'])->count(), 'Nothing is left half-way.');
        foreach ($result['skipped'] as $skipped) {
            $this->assertNotEmpty($skipped['reason'], 'Anything that could not be applied says why.');
        }
        $mapping = collect($result['applied'])->firstWhere('domain', 'mapping');
        $this->assertSame('recommended', $mapping['basis']);
        $this->assertNotEmpty($mapping['answer']);

        // Pressing it again changes nothing.
        $again = $this->postJson('/api/finance/governance/apply-all')->assertOk()->json('data');
        $this->assertSame([], $again['applied']);
        $this->assertSame(count($result['applied']), FinanceConfigVersion::where('status', 'active')->count());

        // The one left over is then a single step too.
        $this->postJson('/api/finance/governance/items/policy.wip/apply', ['value' => $this->wip()])->assertOk();
        $this->assertNotContains('policy.wip', array_column($this->getJson('/api/finance/governance')->json('data.direct.needs_answer'), 'key'));
    }

    public function test_nobody_approves_their_own_accounting_or_management_proposal(): void
    {
        // Someone who both prepares and holds accounting authority.
        $both = User::factory()->create(['is_active' => true]);
        $both->givePermissionTo([Permissions::FINANCE_CONFIG_VIEW, Permissions::FINANCE_CONFIG_PROPOSE, Permissions::FINANCE_CONFIG_APPROVE_ACCOUNTING,
            Permissions::FINANCE_CONFIG_APPROVE_OPERATIONAL, Permissions::APPROVALS_SELF_APPROVE]);

        $own = $this->propose('policy.wip', $this->wip(), [], $both)->assertCreated()->json('data');
        $this->assertFalse($own['actions']['approve']);
        $this->assertSame('You prepared this proposal, so someone else must approve it.', $own['actions']['approve_blocked_reason']);
        $this->act($both, $own['proposal'], 'approve')->assertStatus(422)->assertJsonValidationErrors('approval');
        // Even the self-approval permission does not lift it for an accounting decision.
        $this->assertSame('submitted', FinanceConfigVersion::findOrFail($own['proposal']['id'])->status);
        $this->act($this->accountant, $own['proposal'], 'approve')->assertOk();

        // An operational item: refused without the self-approval permission, allowed and recorded with it.
        $operational = $this->propose('setting.petty_cash_surrender_due_days', ['amount' => 7], [], $both)->assertCreated()->json('data.proposal');
        $both->revokePermissionTo(Permissions::APPROVALS_SELF_APPROVE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->act($both->fresh(), $operational, 'approve')->assertStatus(422);
        $both->givePermissionTo(Permissions::APPROVALS_SELF_APPROVE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->act($both->fresh(), $operational, 'approve')->assertOk();
        $this->assertSame($both->id, (int) DB::table('finance_config_approvals')->where('version_id', $operational['id'])->value('actor_id'), 'the self-approval is on the record');
    }

    // ---- WIP policy ----------------------------------------------------------

    public function test_with_no_decision_a_draft_or_a_submission_the_policy_is_still_required(): void
    {
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock(), 'no decision');
        $this->assertSame(FinanceChartProfile::WIP_POLICY_REQUIRED, ChartAccountMap::local(FinanceAccountFunctions::WIP_DIRECT_MATERIALS));

        $draft = $this->propose('policy.wip', $this->wip(), ['submit' => false])->assertCreated()->json('data.proposal');
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock(), 'a draft');

        $submitted = $this->act($this->preparer, $draft, 'submit')->assertOk()->json('data.proposal');
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock(), 'a submission');
        $this->assertEqualsCanonicalizing(FinanceChartProfile::wipFunctions(),
            array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved'])), 'the nine WIP functions resolve to nothing');

        $gate = fn () => collect($this->as($this->reportsUser())->getJson('/api/finance/readiness')->assertOk()->json('data.governance'))->firstWhere('key', 'wip_policy');
        $this->assertSame('AWAITING APPROVAL', $gate()['state']);
        $this->assertFalse($gate()['pass']);

        $this->act($this->accountant, $submitted, 'approve')->assertOk();
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock(), 'approved but not activated');
        $this->assertSame('APPROVED — NOT ACTIVATED', $gate()['state']);
        $this->assertFalse(collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Chart profile')['ok']);
    }

    public function test_an_active_policy_permits_project_cost_posting_and_resolves_the_accounts_it_implies(): void
    {
        $this->decide('policy.wip', $this->wip('capitalise'));

        $this->assertNull($this->postingBlock());
        $this->assertSame('WIP-002', ChartAccountMap::local(FinanceAccountFunctions::WIP_DIRECT_MATERIALS));
        $this->assertSame([], array_keys(array_filter(FinanceAccountFunctions::resolution(), fn ($f) => ! $f['resolved'])), '37 of 37 resolve');
        $guard = new ReflectionMethod(JournalPostingService::class, 'assertWipPolicy');
        $guard->invoke(app(JournalPostingService::class), '1211 Project WIP – Direct Materials', 'cost line CL-TEST');   // does not throw
        $this->assertNotNull((new ReflectionMethod(JournalPostingService::class, 'accountByCode'))->invoke(app(JournalPostingService::class), FinanceAccountFunctions::UNCODED_COST_FALLBACK));

        $profile = collect(app(FinanceReadiness::class)->checks())->firstWhere('check', 'Chart profile');
        $this->assertTrue($profile['ok'], $profile['detail']);
        $this->assertStringContainsString('Approved and active in Finance Setup', $profile['detail']);
        $gate = collect($this->as($this->reportsUser())->getJson('/api/finance/readiness')->json('data.governance'))->firstWhere('key', 'wip_policy');
        $this->assertTrue($gate['pass']);
        $this->assertSame('ACTIVE', $gate['state']);

        // The catalogue seeds against the approved policy.
        $this->seed(ExpenseCodeSeeder::class);
        $this->assertSame('WIP-002', DB::table('chart_of_accounts')->where('id', DB::table('expense_codes')->where('code', 'DM-EL-001')->value('default_debit_account_id'))->value('code'));
    }

    public function test_recognising_cost_immediately_sends_each_wip_function_to_its_cost_of_sales_account(): void
    {
        $this->decide('policy.wip', $this->wip('expense_on_capture'));

        $this->assertSame('COS-008', ChartAccountMap::local(FinanceAccountFunctions::WIP_DIRECT_MATERIALS));
        $this->assertSame('PE-007', ChartAccountMap::local(FinanceAccountFunctions::WIP_DIRECT_LABOUR));
        $this->assertSame(ChartAccountMap::local(FinanceAccountFunctions::COS_EQUIPMENT_SITE), ChartAccountMap::local(FinanceAccountFunctions::WIP_EQUIPMENT_SITE));
    }

    public function test_a_deployment_setting_that_contradicts_the_approved_policy_fails_closed(): void
    {
        $this->decide('policy.wip', $this->wip('capitalise'));

        // Agreement: fine.
        $this->activateProfile('capitalise');
        $this->assertNull($this->postingBlock());
        $this->assertSame('governed', GovernanceRuntime::instance()->wipPolicy()['state'], 'the approved policy is the authority, not the setting');

        // Disagreement: neither is applied.
        $this->activateProfile('expense_on_capture');
        $block = $this->postingBlock();
        $this->assertStringStartsWith('CONFIGURATION CONFLICT', $block);
        $this->assertNull(GovernanceRuntime::instance()->wipPolicy()['policy']);
        $this->assertSame(FinanceChartProfile::WIP_POLICY_REQUIRED, ChartAccountMap::local(FinanceAccountFunctions::WIP_DIRECT_MATERIALS), 'not capitalise, and not expense on capture');
        try {
            (new ReflectionMethod(JournalPostingService::class, 'assertWipPolicy'))->invoke(app(JournalPostingService::class), '1211 Project WIP – Direct Materials', 'cost line CL-TEST');
            $this->fail('A project cost posted during a configuration conflict.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('CONFIGURATION CONFLICT', $e->getMessage());
        }
        $this->assertSame('conflict', $this->item('policy.wip')['state']);
        $gate = collect($this->as($this->reportsUser())->getJson('/api/finance/readiness')->json('data.governance'))->firstWhere('key', 'wip_policy');
        $this->assertSame('CONFIGURATION CONFLICT', $gate['state']);
        $this->artisan('finance:complete-chart', ['--profile' => 'wng'])->expectsOutputToContain('CONFIGURATION CONFLICT');
    }

    public function test_the_deployment_setting_is_a_bootstrap_that_can_be_retired(): void
    {
        // Transition (default): with nothing approved in the ERP, the deployment setting still lets tooling run, and is reported as unapproved.
        $this->activateProfile('capitalise');
        $this->assertNull($this->postingBlock());
        $resolved = GovernanceRuntime::instance()->wipPolicy();
        $this->assertSame(['capitalise', 'deployment'], [$resolved['policy'], $resolved['state']]);
        $gate = collect($this->as($this->reportsUser())->getJson('/api/finance/readiness')->json('data.governance'))->firstWhere('key', 'wip_policy');
        $this->assertFalse($gate['pass'], 'a deployment setting is not an approval');
        $this->assertStringContainsString('that is not an approval', $gate['message']);

        // Governed: the ERP is the only authority; the setting alone supplies nothing.
        $this->activateProfile('capitalise', 'governed');
        $this->assertStringStartsWith('POLICY REQUIRED', $this->postingBlock());
        $this->assertStringContainsString('deployment setting is ignored', $this->postingBlock());
        $this->decide('policy.wip', $this->wip('capitalise'));
        $this->assertNull($this->postingBlock());
    }

    protected function reportsUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(Permissions::FINANCE_REPORTS_VIEW);

        return $user;
    }

    // ---- Nothing existing is treated as approved -------------------------------

    public function test_existing_recommendations_are_suggestions_awaiting_review_never_approvals(): void
    {
        $this->activateProfile('capitalise');   // the software can resolve everything
        $overview = $this->as($this->preparer)->getJson('/api/finance/governance')->assertOk()->json('data');
        $areas = collect($overview['areas'])->keyBy('key');

        $this->assertSame('0 of 37 approved and active', $areas['mapping']['headline']);
        $this->assertSame('awaiting_review', $areas['mapping']['state']);
        $this->assertSame('decision_required', $areas['policies']['state'], 'capitalise is suggested by the profile and supplied by deployment; it is still not decided');
        $this->assertSame('decision_required', $areas['mpesa']['state']);
        $this->assertSame('decision_required', $areas['card']['state']);
        $this->assertSame('Verified', $areas['structure']['state_label']);
        $this->assertSame('ready', $areas['structure']['bucket']);
        $this->assertSame(collect($overview['areas'])->where('bucket', 'ready')->count(), $overview['ready_areas']);
        $this->assertSame([], collect($overview['areas'])->where('bucket', 'ready')->pluck('key')->diff(['structure', 'periods'])->values()->all(),
            'no area that needs a human decision is ready');
        $this->assertSame($overview['ready_areas'].' of '.$overview['required_areas'].' required areas ready', $overview['summary']);
        $this->assertSame(0, FinanceConfigVersion::count(), 'opening the centre creates nothing');

        $mappings = collect($this->as($this->preparer)->getJson('/api/finance/governance/items?domain=mapping')->assertOk()->json('data'));
        $this->assertCount(37, $mappings);
        $this->assertArrayNotHasKey('options', $mappings->first(), 'a list row carries no form definition');
        $this->assertArrayNotHasKey('story', $mappings->first());
        $this->assertSame(37, $overview['pending']['mapping'], 'each tab is told how many of its items still need somebody');
        $this->assertSame(116, $overview['pending']['classification'], 'the four left for the accountant are not counted as owed');
        $this->assertSame(37, $mappings->where('state', 'awaiting_review')->count());
        $this->assertSame(37, $mappings->filter(fn ($m) => $m['current']['resolves'])->count(), 'all 37 technically resolve');
        $this->assertEqualsCanonicalizing(['staff_advances', 'cos_direct_labour', 'cos_project_facilitation', 'cos_rework_warranty', 'inventory_adjustments', 'bank_charges'],
            $mappings->filter(fn ($m) => isset($m['flags']['judgement']))->pluck('function')->all());

        $gate = collect($this->as($this->reportsUser())->getJson('/api/finance/readiness')->json('data.governance'))->firstWhere('key', 'account_mappings');
        $this->assertSame('ACCOUNTANT APPROVAL REQUIRED', $gate['state']);
        $this->assertStringContainsString('37 of 37 posting functions technically resolve', $gate['message']);
        $this->assertStringContainsString('0 of 37 are approved', $gate['message']);
    }

    // ---- Account mapping -----------------------------------------------------

    public function test_suggested_mappings_are_put_forward_together_and_approved_individually_or_as_an_explicit_selection(): void
    {
        $keys = ['mapping.accounts_receivable', 'mapping.accounts_payable', 'mapping.output_vat', 'mapping.cos_direct_labour'];
        $this->as($this->preparer)->postJson('/api/finance/governance/suggestions/submit', ['keys' => $keys, 'reason' => 'As set out in Report 74.', 'effective_from' => '2026-10-06'])
            ->assertCreated()->assertJsonPath('data.submitted', $keys);
        $this->assertSame(4, FinanceConfigVersion::where('status', 'submitted')->count(), 'submitted, not approved');
        $proposal = fn (string $key) => $this->item($key)['proposal'];

        // The judgement mapping cannot ride along in a bulk approval, and nothing in that request is approved.
        $selection = array_map(fn ($key) => ['id' => $proposal($key)['id'], 'revision' => $proposal($key)['revision']], $keys);
        $this->as($this->accountant)->postJson('/api/finance/governance/proposals/approve', ['selection' => $selection, 'confirmed_count' => 4])
            ->assertStatus(422)->assertJsonValidationErrors('selection');
        $this->assertSame(0, FinanceConfigVersion::where('status', 'approved')->count(), 'all or nothing');

        // A count that does not match what was selected is refused.
        $this->as($this->accountant)->postJson('/api/finance/governance/proposals/approve', ['selection' => array_slice($selection, 0, 3), 'confirmed_count' => 2])->assertStatus(422);

        $this->as($this->accountant)->postJson('/api/finance/governance/proposals/approve', ['selection' => array_slice($selection, 0, 3), 'confirmed_count' => 3])
            ->assertOk()->assertJsonPath('data.approved', array_slice($keys, 0, 3));
        $this->assertSame(3, FinanceConfigVersion::where('status', 'approved')->count());
        $this->assertSame(0, FinanceConfigVersion::where('status', 'active')->count(), 'approval activates nothing');

        // Activation in bulk: only approved proposals, only by someone who may activate, all or nothing.
        $approved = array_map(fn ($key) => ['id' => $proposal($key)['id'], 'revision' => $proposal($key)['revision']], array_slice($keys, 0, 3));
        $withUnapproved = [...$approved, ['id' => $proposal('mapping.cos_direct_labour')['id'], 'revision' => $proposal('mapping.cos_direct_labour')['revision']]];
        $this->as($this->accountant)->postJson('/api/finance/governance/proposals/activate', ['selection' => $approved, 'confirmed_count' => 3])->assertForbidden();
        $this->as($this->activator)->postJson('/api/finance/governance/proposals/activate', ['selection' => $withUnapproved, 'confirmed_count' => 4])->assertStatus(422);
        $this->assertSame(0, FinanceConfigVersion::where('status', 'active')->count(), 'one unapproved proposal in the list activates none of them');
        $this->as($this->activator)->postJson('/api/finance/governance/proposals/activate', ['selection' => $approved, 'confirmed_count' => 3])
            ->assertOk()->assertJsonPath('data.activated', array_slice($keys, 0, 3));
        $this->assertSame(3, FinanceConfigVersion::where('status', 'active')->count());
        $this->assertSame('active', $this->item('mapping.output_vat')['state']);

        // The flagged one is decided on its own.
        $this->assertFalse($this->item('mapping.cos_direct_labour')['bulk']);
        $this->act($this->accountant, $proposal('mapping.cos_direct_labour'), 'approve', ['comment' => 'Direct labour belongs in gross margin.'])->assertOk();

        // Not the accountant: no bulk approval.
        // Someone who only prepares proposals cannot approve them. (A Super Admin now can: WNG's decision, tested above.)
        $this->as($this->preparer)->postJson('/api/finance/governance/proposals/approve', ['selection' => [$selection[0]], 'confirmed_count' => 1])->assertForbidden();
    }

    public function test_a_changed_assignment_must_be_a_compatible_account_and_feeds_the_existing_map_once_active(): void
    {
        $item = $this->item('mapping.bank_charges');
        $this->assertSame('FIN-003', $item['suggestion']['account_code']);
        $eligible = array_column($item['eligible_accounts'], 'code');
        $this->assertContains('FIN-005', $eligible, 'WNG\'s own M-Pesa charges account is a candidate');
        $this->assertNotContains('AP-001', $eligible, 'a liability is never offered for an expense function');
        $this->assertNotContains('SAL-001', $eligible, 'nor a header');

        $this->propose('mapping.bank_charges', ['account_code' => 'AP-001'])->assertStatus(422)->assertJsonValidationErrors('value.account_code');
        $this->propose('mapping.bank_charges', ['account_code' => 'NOPE-999'])->assertStatus(422);
        $this->propose('mapping.accounts_receivable', ['account_code' => 'SAL-001'])->assertStatus(422);
        DB::table('chart_of_accounts')->where('code', 'FIN-004')->update(['is_active' => false]);
        $this->propose('mapping.bank_charges', ['account_code' => 'FIN-004'])->assertStatus(422);
        $this->assertSame(0, FinanceConfigVersion::count());

        // The search returns only accounts that would be accepted.
        $found = $this->as($this->preparer)->getJson('/api/finance/governance/items/mapping.bank_charges/accounts?q=mpesa')->assertOk()->json('data');
        $this->assertSame(['FIN-005'], array_column($found, 'code'));

        // Until it is active, the map is unchanged; once active, the existing architecture uses it.
        $this->activateProfile('capitalise');
        $proposal = $this->propose('mapping.bank_charges', ['account_code' => 'FIN-005'])->assertCreated()->json('data.proposal');
        $proposal = $this->act($this->accountant, $proposal, 'approve')->assertOk()->json('data.proposal');
        GovernanceRuntime::flush();
        $this->assertSame('FIN-003', ChartAccountMap::local(FinanceAccountFunctions::BANK_CHARGES));
        $this->act($this->activator, $proposal, 'activate')->assertOk();
        GovernanceRuntime::flush();
        $this->assertSame('FIN-005', ChartAccountMap::local(FinanceAccountFunctions::BANK_CHARGES));
        $this->assertSame('FIN-005', FinanceAccountFunctions::resolution()['bank_charges']['local_code']);
        $this->assertSame('AR-001', ChartAccountMap::local(FinanceAccountFunctions::ACCOUNTS_RECEIVABLE), 'nothing else moved');

        // The cutover tool is told the profile no longer matches what Finance approved.
        $this->artisan('finance:complete-chart', ['--profile' => 'wng'])
            ->expectsOutputToContain("approved, active mapping for 'bank_charges' to FIN-005; the profile plans FIN-003");
    }

    // ---- Classification ------------------------------------------------------

    public function test_classifications_are_reviewed_by_confidence_and_approval_writes_nothing_to_the_chart(): void
    {
        $items = collect($this->as($this->preparer)->getJson('/api/finance/governance/items?domain=classification')->assertOk()->json('data'));
        $this->assertSame(['accountant_judgement' => 13, 'deterministic' => 17, 'strong_evidence' => 86, 'unclassified' => 4],
            $items->countBy('confidence')->sortKeys()->all());
        $this->assertEqualsCanonicalizing(['OPE-026', 'ITX-001', 'LDO-001', 'EQE-001'], $items->where('confidence', 'unclassified')->pluck('account.code')->all());
        $this->assertSame(13 + 4, $items->where('bulk', false)->count(), 'judgement and undecided accounts are never bulk-approved');

        // The four are not given an answer.
        $undecided = $this->item('classification.OPE-026');
        $this->assertNull($undecided['suggestion']);
        $this->assertSame('decision_required', $undecided['state']);
        $this->assertFalse($undecided['required']);
        $this->assertSame(['direct_cost', 'overhead', 'opex'], array_keys($undecided['options']['account_types']), 'only classifications that suit an expense account');
        $this->propose('classification.OPE-026', ['account_type' => 'revenue', 'normal_balance' => 'debit'])->assertStatus(422)->assertJsonValidationErrors('value.account_type');

        $before = DB::table('chart_of_accounts')->orderBy('id')->get()->map(fn ($a) => (array) $a)->all();
        $active = $this->decide('classification.OPE-026', ['account_type' => 'direct_cost', 'normal_balance' => 'debit']);
        $this->assertSame('active', $active['state']);
        $this->assertSame('Direct cost (cost of sales), debit balance', $active['active']['label']);
        $this->assertSame($before, DB::table('chart_of_accounts')->orderBy('id')->get()->map(fn ($a) => (array) $a)->all(),
            'the approval is recorded; the chart is classified at cutover, not from this screen');
    }

    // ---- Banks, M-Pesa, card -------------------------------------------------

    public function test_mpesa_asks_only_what_the_answer_makes_relevant_and_never_invents_an_account(): void
    {
        $mpesa = fn () => DB::table('payment_sources')->where('code', 'MPESA')->first();
        $this->assertNull($mpesa()->gl_account_id);

        // Yes, but how? must be answered.
        $this->propose('channel.mpesa', ['in_use' => true])->assertStatus(422)->assertJsonValidationErrors('value.mode');
        // A held balance needs an account that already exists in the chart.
        $this->propose('channel.mpesa', ['in_use' => true, 'mode' => 'held_balance'])->assertStatus(422)->assertJsonValidationErrors('value.account_code');
        $this->propose('channel.mpesa', ['in_use' => true, 'mode' => 'held_balance', 'account_code' => 'MPESA-001'])->assertStatus(422);
        $this->assertFalse(DB::table('chart_of_accounts')->where('code', 'MPESA-001')->exists(), 'no account is created');
        $this->propose('channel.mpesa', ['in_use' => true, 'mode' => 'held_balance', 'account_code' => 'AP-001'])->assertStatus(422);
        // A settlement channel needs a bank, and no bank is assumed.
        $this->propose('channel.mpesa', ['in_use' => true, 'mode' => 'settlement_channel'])->assertStatus(422)->assertJsonValidationErrors('value.settlement_source');
        $this->assertSame(0, FinanceConfigVersion::count());

        // Fields that do not belong to the answer are dropped, not stored.
        $no = $this->propose('channel.mpesa', ['in_use' => false, 'mode' => 'held_balance', 'account_code' => 'EQB-001'])->assertCreated()->json('data.proposal');
        $this->assertSame(['in_use' => false], $no['value']);
        $this->act($this->preparer, $no, 'withdraw')->assertOk();

        // Decided, approved, activated: only then is the paying account switched on, against the bank it settles to.
        $proposal = $this->propose('channel.mpesa', ['in_use' => true, 'mode' => 'settlement_channel', 'settlement_source' => 'BANK-ALT'])->assertCreated()->json('data.proposal');
        $this->assertNull($mpesa()->gl_account_id, 'a submission changes nothing');
        $proposal = $this->act($this->accountant, $proposal, 'approve')->assertOk()->json('data.proposal');
        $this->assertFalse((bool) $mpesa()->is_active && $mpesa()->gl_account_id !== null, 'nor does an approval');
        $item = $this->act($this->activator, $proposal, 'activate')->assertOk()->json('data');

        $this->assertTrue((bool) $mpesa()->is_active);
        $this->assertSame('NCBA-001', DB::table('chart_of_accounts')->where('id', $mpesa()->gl_account_id)->value('code'));
        $this->assertSame('A channel settling to NCBA Bank – Operations Account', $item['active']['label']);

        // Changed on the old screen afterwards: the approval no longer describes it.
        DB::table('payment_sources')->where('code', 'MPESA')->update(['is_active' => false]);
        $this->assertSame('review_required', $this->item('channel.mpesa')['state']);
    }

    public function test_the_company_card_stores_a_name_and_at_most_four_digits(): void
    {
        $card = ['in_use' => true, 'label' => 'Operations card', 'settlement_source' => 'BANK-MAIN', 'last_four' => '4821', 'custodian_role' => 'Accounts'];
        Role::findOrCreate('Accounts', 'web');

        $this->propose('channel.card', ['in_use' => true])->assertStatus(422)->assertJsonValidationErrors('value.label');
        $this->propose('channel.card', ['label' => '4539 1488 0343 6467'] + $card)->assertStatus(422)->assertJsonValidationErrors('value.label');
        $this->propose('channel.card', ['last_four' => '4539148803436467'] + $card)->assertStatus(422)->assertJsonValidationErrors('value.last_four');
        $this->propose('channel.card', ['settlement_source' => 'MPESA'] + $card)->assertStatus(422)->assertJsonValidationErrors('value.settlement_source');
        $this->propose('channel.card', ['custodian_role' => 'George'] + $card)->assertStatus(422)->assertJsonValidationErrors('value.custodian_role');

        // "No" hides and discards everything else.
        $no = $this->propose('channel.card', ['in_use' => false] + $card)->assertCreated()->json('data.proposal');
        $this->assertSame(['in_use' => false], $no['value']);
        $this->act($this->preparer, $no, 'withdraw')->assertOk();

        $item = $this->decide('channel.card', $card);
        $this->assertSame('Operations card (•••• 4821), drawn on Equity Bank – Operating Account', $item['active']['label']);
        $source = DB::table('payment_sources')->where('code', 'CARD')->first();
        $this->assertTrue((bool) $source->is_active);
        $this->assertSame('EQB-001', DB::table('chart_of_accounts')->where('id', $source->gl_account_id)->value('code'));
        $this->assertStringNotContainsString('4539', json_encode(FinanceConfigVersion::all()->toArray()));
    }

    public function test_a_bank_account_is_switched_on_only_by_an_approved_decision_and_not_ahead_of_its_date(): void
    {
        $kcb = fn () => DB::table('payment_sources')->where('code', 'BANK-KCB')->first();
        $this->assertFalse((bool) $kcb()->is_active, 'in the chart, linked, and still not in use');
        $item = $this->item('bank.BANK-KCB');
        $this->assertSame(['active' => false, 'account_code' => 'KCB-001'], $item['suggestion'], 'the suggestion does not switch it on');
        $this->assertSame('finance_review', $item['requirement']);

        $this->propose('bank.BANK-KCB', ['active' => true, 'account_code' => 'AP-001'])->assertStatus(422);
        $this->propose('bank.BANK-KCB', ['active' => true, 'account_code' => 'IA-001'], ['submit' => false])->assertCreated();   // an asset, so structurally allowed
        FinanceConfigVersion::query()->delete();

        $proposal = $this->propose('bank.BANK-KCB', ['active' => true, 'account_code' => 'KCB-001'], ['effective_from' => '2026-10-10'])->assertCreated()->json('data.proposal');
        $this->act($this->accountant, $proposal, 'approve')->assertForbidden();   // not an accounting decision: Finance approves it
        $proposal = $this->act($this->financeApprover, $proposal, 'approve')->assertOk()->json('data.proposal');
        $this->act($this->activator, $proposal, 'activate')->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->assertFalse((bool) $kcb()->is_active);

        $this->travelTo(now()->setDate(2026, 10, 10));
        $this->act($this->activator, $this->item('bank.BANK-KCB')['proposal'], 'activate')->assertOk();
        $this->assertTrue((bool) $kcb()->is_active);
    }

    // ---- Settings ------------------------------------------------------------

    public function test_a_threshold_is_a_validated_number_and_reaches_the_existing_setting_only_when_active(): void
    {
        $item = $this->item('setting.petty_cash_max_per_transaction');
        $this->assertSame('KES', $item['unit']);
        $this->assertSame(['amount' => 20000], $item['suggestion'], 'the seeded figure is a suggestion');
        $this->assertSame('awaiting_review', $item['state']);
        $this->assertNull(FinanceSetting::approvedValue('petty_cash_max_per_transaction'), 'and is not enforced');
        $this->assertSame('decision_required', $this->item('setting.purchase_order_senior_approval_threshold')['state'], 'an unset threshold has no suggestion to review');

        foreach (['twenty thousand', '', null, -1, 20000.123] as $bad) {
            $this->propose('setting.petty_cash_max_per_transaction', ['amount' => $bad])->assertStatus(422)->assertJsonValidationErrors('value.amount');
        }
        $this->propose('setting.petty_cash_surrender_due_days', ['amount' => 7.5])->assertStatus(422);
        $this->propose('setting.tax_return_due_day', ['amount' => 31])->assertStatus(422);
        $this->propose('setting.margin_warning_percent', ['amount' => 140])->assertStatus(422);

        $proposal = $this->propose('setting.petty_cash_max_per_transaction', ['amount' => '25000'])->assertCreated()->json('data.proposal');
        $this->assertSame(25000, $proposal['value']['amount']);
        $this->assertIsNotString($proposal['value']['amount'], 'stored as a number');
        $this->assertSame('KES 25,000.00', $proposal['label']);
        $this->act($this->accountant, $proposal, 'approve')->assertForbidden();   // management's to approve
        $proposal = $this->act($this->manager, $proposal, 'approve')->assertOk()->json('data.proposal');
        $this->assertNull(FinanceSetting::approvedValue('petty_cash_max_per_transaction'), 'approved is not active');

        $this->act($this->activator, $proposal, 'activate')->assertOk();
        $this->assertSame('25000', (string) FinanceSetting::approvedValue('petty_cash_max_per_transaction'), 'exactly what the existing code reads');
        $row = DB::table('finance_settings')->where('key', 'petty_cash_max_per_transaction')->orderByDesc('effective_from')->first();
        $this->assertSame([$this->manager->id, '2026-10-06', null], [(int) $row->approved_by, $row->effective_from, $row->effective_to]);
        $this->assertSame('2026-10-05', DB::table('finance_settings')->where('key', 'petty_cash_max_per_transaction')->orderBy('effective_from')->value('effective_to'),
            'the unapproved recommendation is closed, not overwritten');

        // "No limit" is a decision too, and is recorded as one.
        $none = $this->decide('setting.purchase_order_senior_approval_threshold', ['not_applicable' => true], $this->manager);
        $this->assertSame('No limit set (this control stays off)', $none['active']['label']);
        $this->assertNull(FinanceSetting::approvedValue('purchase_order_senior_approval_threshold'));
    }

    public function test_two_approval_rules_cannot_contradict_each_other(): void
    {
        $this->decide('setting.purchase_order_senior_approval_threshold', ['amount' => 100000], $this->manager);

        $this->propose('setting.purchase_order_auto_approval_limit', ['amount' => 150000])->assertStatus(422)->assertJsonValidationErrors('value.amount');
        $this->propose('setting.purchase_order_auto_approval_limit', ['amount' => 100000])->assertStatus(422);
        $this->propose('setting.purchase_order_auto_approval_limit', ['amount' => 30000])->assertCreated();

        $this->decide('setting.petty_cash_low_balance_threshold', ['amount' => 20000], $this->financeApprover);
        $this->propose('setting.petty_cash_critical_balance_threshold', ['amount' => 25000])->assertStatus(422);
    }

    // ---- Tax -----------------------------------------------------------------

    public function test_tax_reference_data_stays_unverified_until_the_accountant_verifies_what_is_actually_there(): void
    {
        $items = collect($this->as($this->preparer)->getJson('/api/finance/governance/items?domain=tax')->assertOk()->json('data'));
        $vat = $items->firstWhere('key', 'tax.vat.STD16-REC');
        $this->assertSame('decision_required', $vat['state']);
        $this->assertSame(16.0, (float) $vat['current']['rate_percent']);
        $this->assertSame('VAT-002', $vat['current']['account_code']);

        // Whatever the client sends, what is verified is what the table holds.
        $item = $this->decide('tax.vat.STD16-REC', ['snapshot' => ['rate_percent' => 99]]);
        $this->assertSame('active', $item['state']);
        $this->assertSame(16.0, (float) $item['active']['value']['snapshot']['rate_percent']);
        $this->assertSame('Verified at 16%, posting to VAT-002', $item['active']['label']);
        $this->assertSame(16.0, (float) DB::table('vat_treatments')->where('code', 'STD16-REC')->value('rate_percent'), 'verifying changes no rate');

        // The rate changes afterwards: the verification no longer covers it.
        DB::table('vat_treatments')->where('code', 'STD16-REC')->update(['rate_percent' => 18]);
        $this->assertSame('review_required', $this->item('tax.vat.STD16-REC')['state']);
    }

    // ---- Numbering, periods, chart identity -----------------------------------

    public function test_document_numbering_is_reported_as_it_is(): void
    {
        $report = $this->as($this->preparer)->getJson('/api/finance/governance/numbering')->assertOk()->json('data');
        $series = collect($report['series'])->keyBy('key');

        $this->assertTrue($series['payment']['controlled']);
        $this->assertFalse($series['client_invoice']['gap_free'], 'invoices are numbered from the row id and are not gap-free');
        $this->assertFalse($series['supplier_bill']['concurrency_safe']);
        $this->assertSame(1, $series->where('controlled', true)->count(), 'only one series is controlled, and the register does not pretend otherwise');
        $this->assertSame('Data required', collect($this->as($this->preparer)->getJson('/api/finance/governance')->json('data.areas'))->firstWhere('key', 'numbering')['state_label']);
    }

    public function test_reopening_a_closed_period_still_needs_permission_and_a_reason(): void
    {
        $period = DB::table('accounting_periods')->where('year', 2026)->where('month', 9)->first();
        DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'closed']);
        Permission::findOrCreate(Permissions::FINANCE_PERIODS_MANAGE, 'web');

        $this->as($this->preparer)->postJson("/api/finance/accounting-periods/{$period->id}/reopen", ['reason' => 'A late supplier bill.'])->assertForbidden();
        $this->preparer->givePermissionTo(Permissions::FINANCE_PERIODS_MANAGE);
        $this->as($this->preparer->fresh())->postJson("/api/finance/accounting-periods/{$period->id}/reopen", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('closed', DB::table('accounting_periods')->where('id', $period->id)->value('status'));
        $this->as($this->preparer->fresh())->postJson("/api/finance/accounting-periods/{$period->id}/reopen", ['reason' => 'A late supplier bill.'])->assertOk();

        // 2024 and 2025 are all still open, and the overview says so instead of calling it ready.
        $periods = collect($this->as($this->preparer)->getJson('/api/finance/governance')->json('data.areas'))->firstWhere('key', 'periods');
        $this->assertSame('review_required', $periods['state']);
        $this->assertStringContainsString('earlier years are still open', $periods['why']);
    }

    public function test_nothing_approved_here_overrides_the_two_chart_stop(): void
    {
        // Every governed decision made...
        $this->decide('policy.wip', $this->wip('capitalise'));
        // ...and then the reference chart turns up beside WNG's.
        DB::table('chart_of_accounts')->insert(['code' => '1010', 'name' => 'Bank – Main Account', 'category' => 'asset', 'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('chart_of_accounts')->insert(['code' => '1100', 'name' => 'Accounts Receivable', 'category' => 'asset', 'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $structure = collect($this->as($this->preparer)->getJson('/api/finance/governance')->assertOk()->json('data.areas'))->firstWhere('key', 'structure');
        $this->assertSame('Two charts detected', $structure['state_label']);
        $this->assertSame('blocked', $structure['bucket']);
        $this->assertSame('TWO_CHART_STATE', $structure['identity']['identity']);
        $this->assertStringContainsString('ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED', $structure['why']);

        $database = DB::selectOne('SELECT DATABASE() AS db')->db;
        $this->artisan('finance:complete-chart', ['--profile' => 'wng'])->expectsOutputToContain('Target chart identity: TWO_CHART_STATE')->assertFailed();
        $this->artisan('finance:complete-chart', ['--profile' => 'wng', '--execute' => true, '--confirm' => $database])
            ->expectsOutputToContain('ACCOUNTANT REVIEW REQUIRED — TWO CHARTS DETECTED')->assertFailed();
    }
}
