<?php

namespace App\Modules\Finance\Governance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\Governance\FinanceConfigVersion as Version;
use App\Modules\Finance\Support\ChartIdentity;
use App\Modules\Finance\Support\FinanceChartProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What Finance Setup shows (Report 75): every item with where it stands, why,
 * and who has to do what next. Read-only; GovernanceService does the changing.
 *
 * Two things are kept apart on purpose, everywhere:
 *
 *   TECHNICALLY RESOLVED   the software can find an account, a value, a link;
 *   APPROVED AND ACTIVE    a person entitled to decide has decided, and it is in force.
 *
 * Only the second makes an item ready.
 */
class GovernanceCentre
{
    /** Item states, worst first. An area takes the state of its worst required item. */
    public const STATES = [
        'conflict' => ['Configuration conflict', 'blocked'],
        'decision_required' => ['Decision required', 'decision'],
        'review_required' => ['Review required', 'decision'],
        'awaiting_review' => ['Awaiting review', 'review'],
        'returned' => ['Returned for correction', 'review'],
        'draft' => ['Draft', 'review'],
        'awaiting_approval' => ['Awaiting approval', 'approval'],
        'awaiting_activation' => ['Approved — awaiting activation', 'approval'],
        'scheduled' => ['Approved — takes effect later', 'approval'],
        'active' => ['Active', 'ready'],
    ];

    public function __construct(private GovernanceCatalogue $catalogue, private GovernanceService $service)
    {
    }

    // ---- Items ---------------------------------------------------------------

    /** What a list row does not need; the item's own page carries all of it. */
    private const DETAIL_ONLY = ['options', 'story', 'question', 'affects', 'expected', 'capabilities', 'min', 'max', 'kind', 'allow_none', 'reference', 'tax'];

    /**
     * Items for a list. Slimmed by default: with 120 accounts to classify, sending
     * every row its full form definition made one response several hundred kilobytes.
     *
     * @return list<array>
     */
    public function listing(?string $domain, ?User $user): array
    {
        return array_map(fn (array $item) => array_diff_key($item, array_flip(self::DETAIL_ONLY)), $this->items($domain, $user));
    }

    /** @return list<array> */
    public function items(?string $domain, ?User $user): array
    {
        $items = $this->catalogue->items($domain);
        $versions = Version::query()->whereIn('item_key', array_keys($items))->with(['proposer:id,name', 'decider:id,name', 'activator:id,name', 'reviewer:id,name'])
            ->orderBy('version')->get()->groupBy('item_key');

        return array_values(array_map(fn ($item) => $this->view($item, $versions->get($item['key'], collect()), $user), $items));
    }

    public function item(string $key, ?User $user): ?array
    {
        $item = $this->catalogue->item($key);
        if (! $item) {
            return null;
        }
        $versions = Version::query()->where('item_key', $key)->with(['proposer:id,name', 'decider:id,name', 'activator:id,name', 'reviewer:id,name'])->orderBy('version')->get();
        $view = $this->view($item, $versions, $user);
        $view['versions'] = $versions->sortByDesc('version')->map(fn (Version $v) => $this->versionView($item, $v))->values()->all();
        $view['history'] = $this->history($key, 200);
        if (in_array($item['type'], ['account', 'bank', 'mpesa'], true)) {
            $view['eligible_accounts'] = $this->catalogue->eligibleAccounts($item, null, 200);
        }

        return $view;
    }

    private function view(array $item, Collection $versions, ?User $user): array
    {
        $today = now()->toDateString();
        $open = $versions->first(fn (Version $v) => $v->isOpen());
        $inForce = $versions->first(fn (Version $v) => $v->state($today) === 'active');
        $scheduled = $versions->first(fn (Version $v) => $v->state($today) === 'scheduled');
        $previous = $inForce?->supersedes_id ? $versions->firstWhere('id', $inForce->supersedes_id) : null;

        $inSync = $inForce ? $this->catalogue->inSync($item, (array) $inForce->value) : true;
        $conflict = $item['key'] === GovernanceRuntime::WIP_ITEM && GovernanceRuntime::instance()->wipPolicy()['state'] === 'conflict';

        $state = match (true) {
            $conflict => 'conflict',
            $inForce && ! $inSync => 'review_required',
            (bool) $inForce => 'active',
            $open?->status === Version::APPROVED => 'awaiting_activation',
            in_array($open?->status, [Version::SUBMITTED, Version::UNDER_REVIEW], true) => 'awaiting_approval',
            $open?->status === Version::RETURNED => 'returned',
            $open?->status === Version::DRAFT => 'draft',
            (bool) $scheduled => 'scheduled',
            $item['suggestion'] !== null => 'awaiting_review',
            default => 'decision_required',
        };
        $requirement = GovernanceCatalogue::REQUIREMENTS[$item['requirement']];

        return [
            'key' => $item['key'], 'domain' => $item['domain'], 'type' => $item['type'], 'title' => $item['title'],
            'question' => $item['question'], 'why' => $item['why'], 'affects' => $item['affects'],
            'required' => $item['required'], 'requirement' => $item['requirement'], 'requirement_label' => $item['requirement_label'],
            'state' => $state, 'state_label' => self::STATES[$state][0], 'ready' => $state === 'active',
            'unit' => $item['unit'], 'kind' => $item['kind'] ?? null, 'min' => $item['min'] ?? null, 'max' => $item['max'] ?? null,
            'allow_none' => $item['allow_none'] ?? false, 'options' => $item['options'], 'flags' => $item['flags'], 'bulk' => $item['bulk'],
            'confidence' => $item['confidence'] ?? null, 'account' => $item['account'] ?? null, 'function' => $item['function'] ?? null,
            'reference' => $item['reference'] ?? null, 'expected' => $item['expected'] ?? null, 'source' => $item['source'] ?? null,
            'capabilities' => $item['capabilities'] ?? null, 'tax' => $item['tax'] ?? null,
            // What the software does today, whether or not anyone approved it.
            'current' => $item['current'],
            'current_label' => $this->currentLabel($item),
            // Input for review. Never an approval.
            'suggestion' => $item['suggestion'], 'suggestion_source' => $item['suggestion_source'],
            'suggestion_label' => $item['suggestion'] === null ? null : $this->catalogue->describe($item, $item['suggestion']),
            'suggestion_account' => $item['suggestion_account'] ?? null,
            'active' => $inForce ? $this->versionView($item, $inForce) : null,
            'in_sync' => $inSync,
            'scheduled' => $scheduled ? $this->versionView($item, $scheduled) : null,
            'proposal' => $open ? $this->versionView($item, $open) : null,
            'story' => $this->story($item, $inForce, $open ?? $scheduled, $previous, $state),
            'blocked' => $this->blocked($item, $state, $open, $scheduled, $requirement),
            'actions' => $this->actions($item, $open, $user),
        ];
    }

    private function versionView(array $item, Version $v): array
    {
        return [
            'id' => $v->id, 'version' => $v->version, 'revision' => $v->revision, 'status' => $v->status, 'state' => $v->state(),
            'state_label' => match ($v->state()) {
                'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under review', 'returned' => 'Returned for correction',
                'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'approved' => 'Approved', 'scheduled' => 'Approved — takes effect later',
                'active' => 'Active', 'superseded' => 'Superseded', default => ucfirst($v->state()),
            },
            'value' => $v->value, 'label' => $this->catalogue->describe($item, (array) $v->value), 'reason' => $v->reason,
            'effective_from' => $v->effective_from?->toDateString(), 'in_force_from' => $v->in_force_from?->toDateString(), 'in_force_to' => $v->in_force_to?->toDateString(),
            'proposed_by' => $v->proposer?->only(['id', 'name']), 'proposed_at' => $v->created_at?->toIso8601String(), 'submitted_at' => $v->submitted_at?->toIso8601String(),
            'reviewed_by' => $v->reviewer?->only(['id', 'name']), 'reviewed_at' => $v->reviewed_at?->toIso8601String(),
            'decided_by' => $v->decider?->only(['id', 'name']), 'decided_at' => $v->decided_at?->toIso8601String(), 'decision_comment' => $v->decision_comment,
            'activated_by' => $v->activator?->only(['id', 'name']), 'activated_at' => $v->activated_at?->toIso8601String(),
        ];
    }

    private function currentLabel(array $item): ?string
    {
        $current = $item['current'];
        if ($current === null) {
            return null;
        }

        return match ($item['type']) {
            'account' => $current['resolves'] ? trim($current['account_code'].' · '.($current['account']['name'] ?? '')) : 'No usable account is in place',
            'classification' => $current['account_type'] === null && $current['normal_balance'] === null ? 'Unclassified'
                : (GovernanceCatalogue::ACCOUNT_TYPES[$current['account_type']] ?? 'No type').', '.($current['normal_balance'] ?? 'no').' balance',
            'number' => $current['amount'] === null ? 'Not set' : $this->catalogue->describe($item, ['amount' => $current['amount']]).(($current['approved'] ?? false) ? '' : ' (not approved; not enforced)'),
            'bank', 'mpesa', 'card' => ($current['active'] ? 'Switched on' : 'Switched off').($current['account_code'] ? ' · '.$current['account_code'].' '.$current['account_name'] : ' · no ledger account'),
            'verification' => rtrim(rtrim(number_format((float) $current['rate_percent'], 3), '0'), '.').'%'.($current['account_code'] ? ' → '.$current['account_code'].' '.$current['account_name'] : ' · no ledger account'),
            default => null,
        };
    }

    /** The record as a person would tell it. */
    private function story(array $item, ?Version $inForce, ?Version $pending, ?Version $previous, string $state): array
    {
        $subject = $inForce ?? $pending;
        $date = fn (?\DateTimeInterface $d) => $d ? $d->format('d-M-Y') : null;

        return [
            'what' => $subject ? $this->catalogue->describe($item, (array) $subject->value) : 'Nothing has been decided yet.',
            'why' => $subject?->reason ?? $item['why'] ?? $item['question'],
            'who_proposed' => $subject?->proposer?->name,
            'who_approved' => $subject?->decided_at && in_array($subject->status, [Version::APPROVED, Version::ACTIVE], true) ? $subject->decider?->name : null,
            'approved_on' => $subject && in_array($subject->status, [Version::APPROVED, Version::ACTIVE], true) ? $date($subject->decided_at) : null,
            'effective' => $date($subject?->in_force_from ?? $subject?->effective_from),
            'replaced' => $inForce ? ($previous ? 'Version '.$previous->version.': '.$this->catalogue->describe($item, (array) $previous->value) : 'No previous active version.') : null,
            'affects' => $item['affects'],
            'status' => self::STATES[$state][0],
            'version' => $subject?->version,
        ];
    }

    /** Why it is not ready, what has to happen, and who has to do it. Null when ready. */
    private function blocked(array $item, string $state, ?Version $open, ?Version $scheduled, array $requirement): ?array
    {
        $who = $requirement['who'];

        return match ($state) {
            'active' => null,
            'conflict' => ['why' => 'The policy approved here and the deployment setting disagree, so the system applies neither.',
                'next' => 'Remove or correct the deployment setting FINANCE_WIP_POLICY so it agrees with the approved policy.', 'who' => 'The system administrator'],
            'review_required' => ['why' => 'The system has been changed since this was approved, so the approval no longer describes what is in place.',
                'next' => 'Propose the current position for approval again, or restore what was approved.', 'who' => 'Finance'],
            'decision_required' => ['why' => 'Nobody has answered this yet, and the system will not assume an answer.',
                'next' => 'Prepare a proposal with the answer, a reason and the date it should apply from.', 'who' => 'Finance prepares; '.lcfirst($who).' approves'],
            'awaiting_review' => ['why' => 'There is a suggested answer, but a suggestion is not an approval.',
                'next' => 'Submit the suggestion for approval, or change it and submit that.', 'who' => 'Finance submits; '.lcfirst($who).' approves'],
            'draft' => ['why' => 'A proposal has been started but not submitted.', 'next' => 'Complete and submit it.', 'who' => $open?->proposer?->name ?? 'Whoever prepared it'],
            'returned' => ['why' => 'The proposal was returned'.($open?->decision_comment ? ': "'.$open->decision_comment.'"' : '.'),
                'next' => 'Correct it and submit it again.', 'who' => $open?->proposer?->name ?? 'Whoever prepared it'],
            'awaiting_approval' => ['why' => 'The proposal has been submitted and is waiting for a decision.', 'next' => $item['requirement_label'].'.', 'who' => $who],
            'awaiting_activation' => ['why' => 'It is approved but has not been switched on.', 'next' => 'Activate it.', 'who' => 'Someone authorised to activate Finance configuration'],
            'scheduled' => ['why' => 'It is approved and activated, and takes effect on '.$scheduled?->in_force_from?->format('d-M-Y').'.', 'next' => 'Nothing. It starts on that date.', 'who' => 'Nobody'],
            default => null,
        };
    }

    /** What THIS user may do right now. The screen shows exactly these and nothing else. */
    private function actions(array $item, ?Version $open, ?User $user): array
    {
        $holds = fn (string $permission) => GovernanceService::holds($user, $permission);
        $approver = $holds($this->service->approvalPermission($item));
        $own = $open && $user && (int) $open->proposed_by === (int) $user->id;
        $selfBlocked = $own && (in_array($item['requirement'], ['accountant_approval', 'management_approval'], true) || ! $holds(Permissions::APPROVALS_SELF_APPROVE));
        $status = $open?->status;

        return [
            'propose' => $open === null && $holds(Permissions::FINANCE_CONFIG_PROPOSE),
            'edit' => in_array($status, [Version::DRAFT, Version::RETURNED], true) && $holds(Permissions::FINANCE_CONFIG_PROPOSE),
            'submit' => in_array($status, [Version::DRAFT, Version::RETURNED], true) && $holds(Permissions::FINANCE_CONFIG_PROPOSE),
            'withdraw' => in_array($status, [Version::DRAFT, Version::RETURNED, Version::SUBMITTED], true) && $holds(Permissions::FINANCE_CONFIG_PROPOSE),
            'review' => $status === Version::SUBMITTED && $holds(Permissions::FINANCE_CONFIG_REVIEW),
            'return' => in_array($status, [Version::SUBMITTED, Version::UNDER_REVIEW, Version::APPROVED], true) && ($approver || $holds(Permissions::FINANCE_CONFIG_REVIEW)),
            'reject' => in_array($status, [Version::SUBMITTED, Version::UNDER_REVIEW], true) && $approver,
            'approve' => in_array($status, [Version::SUBMITTED, Version::UNDER_REVIEW], true) && $approver && ! $selfBlocked,
            'approve_blocked_reason' => in_array($status, [Version::SUBMITTED, Version::UNDER_REVIEW], true) && $approver && $selfBlocked
                ? 'You prepared this proposal, so someone else must approve it.' : null,
            'activate' => $status === Version::APPROVED && $holds(Permissions::FINANCE_CONFIG_ACTIVATE),
            // WNG's decision: a Super Admin approves and applies in one step.
            'apply_now' => GovernanceService::decidesDirectly($user),
        ];
    }

    // ---- Overview ------------------------------------------------------------

    public function overview(?User $user): array
    {
        $items = collect($this->items(null, $user));
        $areas = [];

        $areas[] = $this->structureArea();
        $areas[] = $this->area('policies', 'Project cost treatment (WIP policy)', $items->where('key', GovernanceRuntime::WIP_ITEM), 'policies');
        $areas[] = $this->area('mapping', 'Account mapping', $items->where('domain', 'mapping'), 'mapping', 'approved');
        $areas[] = $this->area('classification', 'Account classification', $items->where('domain', 'classification'), 'classification', 'approved');
        $areas[] = $this->area('banks', 'Bank accounts', $items->where('type', 'bank'), 'banks', 'confirmed');
        $areas[] = $this->area('mpesa', 'M-Pesa', $items->where('key', 'channel.mpesa'), 'banks');
        $areas[] = $this->area('card', 'Company card', $items->where('key', 'channel.card'), 'banks');
        $areas[] = $this->area('petty_cash', 'Petty cash controls', $items->where('domain', 'petty_cash'), 'petty-cash', 'approved');
        $areas[] = $this->area('approval_rules', 'Approval rules', $items->where('domain', 'approval_rules'), 'approval-rules', 'approved');
        $areas[] = $this->area('other_policies', 'Other accounting thresholds', $items->where('domain', 'policies')->where('key', '!=', GovernanceRuntime::WIP_ITEM), 'policies', 'approved');
        $areas[] = $this->area('tax', 'Tax', $items->where('domain', 'tax'), 'tax', 'verified');
        $areas[] = $this->periodsArea();
        $areas[] = $this->numberingArea();

        $areas = array_values(array_filter($areas));
        $required = collect($areas)->where('required', true);
        $buckets = collect(['ready' => 'Active', 'approval' => 'Awaiting approval', 'review' => 'Awaiting review', 'decision' => 'Decision required', 'data' => 'Data required', 'blocked' => 'Blocked'])
            ->map(fn ($label, $bucket) => ['bucket' => $bucket, 'label' => $label, 'areas' => collect($areas)->where('bucket', $bucket)->count()])->values()->all();

        return [
            // A count of areas, not a percentage: the areas are not comparable in size or weight.
            'ready_areas' => $required->where('bucket', 'ready')->count(),
            'required_areas' => $required->count(),
            'summary' => $required->where('bucket', 'ready')->count().' of '.$required->count().' required areas ready',
            'buckets' => $buckets,
            'areas' => $areas,
            // Required items not yet approved and active, per section: what each tab still owes.
            'pending' => $items->where('required', true)->where('state', '!=', 'active')->countBy('domain')->all(),
            'authority' => $this->authority(),
            // One-step approval for a Super Admin: what one click would do, counted, not done.
            'direct' => $this->directSummary($items, $user),
            // Report 75R-C: WNG's Financial Requisition decisions, each shown as it really stands.
            'requisition_controls' => $this->requisitionControls($items),
            'profile' => ['name' => $this->catalogue->profile(), 'active' => $this->catalogue->profileIsActive()],
            'wip' => GovernanceRuntime::instance()->wipPolicy(),
            'note' => 'An area is ready only when every required item in it is approved, activated and in force. A suggestion, a draft, or something the software can merely resolve does not count.',
        ];
    }

    /**
     * WNG's four Financial Requisition decisions (Report 75R-C).
     *
     * Two are rules the system enforces and are shown as active. The advance
     * account is a policy that is only proposed until someone with authority
     * approves it here; and the release authority is only configured once
     * somebody actually holds it. Neither is shown as more than it is.
     */
    private function requisitionControls(Collection $items): array
    {
        $advance = $items->filter(fn ($item) => str_starts_with($item['key'], 'policy.requisition_advance.'))->values();
        $allActive = $advance->isNotEmpty() && $advance->every(fn ($item) => $item['state'] === 'active');
        $account = $advance->first()['suggestion_label'] ?? $advance->first()['current_label'] ?? 'Staff Advances';
        try {
            $releasers = User::permission(Permissions::FINANCE_REQUISITIONS_RELEASE_UNUSED)->where('is_active', true)->orderBy('name')->limit(20)->pluck('name')->all();
        } catch (\Throwable) {
            $releasers = [];
        }

        return [
            ['key' => 'advance_account', 'title' => 'Advance account',
                'rule' => "All requisition payments are held in {$account} until accounted for.",
                'state' => $allActive ? 'active' : 'proposed',
                'status' => $allActive ? 'CONFIGURED / ACTIVE' : 'PROPOSED',
                'detail' => $allActive ? 'Approved and in force for employees, suppliers and other recipients.'
                    : 'WNG has decided this. It is proposed, and becomes approved policy once approved here.',
                'items' => $advance->map(fn ($item) => ['key' => $item['key'], 'title' => $item['title'], 'state' => $item['state']])->all()],
            ['key' => 'overspend', 'title' => 'Overspend',
                'rule' => 'NO AUTOMATIC REIMBURSEMENT — ADDITIONAL FUNDING REQUIRES APPROVAL.',
                'state' => 'active', 'status' => 'ACTIVE',
                'detail' => \App\Modules\Finance\PettyCash\Services\RequisitionAccountabilityService::OVERSPEND_INSTRUCTION, 'items' => []],
            ['key' => 'unused_balance', 'title' => 'Unused approved balance',
                'rule' => 'Finance releases an unused balance, with a reason. The requester cannot release their own.',
                'state' => $releasers ? 'active' : 'assignment_required',
                'status' => $releasers ? 'AUTHORITY ASSIGNED' : 'AUTHORITY ASSIGNMENT REQUIRED',
                'detail' => $releasers ? 'Can release: '.implode(', ', $releasers).'.'
                    : 'Nobody holds "Release Unused Approved Requisition Balance" yet. Assign it to the Finance role or person WNG chooses, in Admin > Roles.',
                'items' => []],
            ['key' => 'segregation', 'title' => 'Segregation of duties',
                'rule' => 'APPROVER ≠ PAYER',
                'state' => 'active', 'status' => 'ACTIVE',
                'detail' => 'The person who approved a requisition cannot pay it. The payer may reconcile and close.', 'items' => []],
        ];
    }

    /**
     * What "approve everything" would cover for this person, so the button can
     * say exactly what it will do before it is pressed.
     */
    private function directSummary(Collection $items, ?User $user): array
    {
        if (! GovernanceService::decidesDirectly($user)) {
            return ['allowed' => false];
        }
        $undecided = $items->where('state', '!=', 'active')->whereNotIn('state', ['scheduled']);
        $withProposal = $undecided->filter(fn ($item) => $item['proposal'] !== null);
        $rest = $undecided->filter(fn ($item) => $item['proposal'] === null);
        $recommended = $rest->filter(fn ($item) => $item['suggestion'] !== null);
        $inUse = $rest->filter(fn ($item) => $item['suggestion'] === null && filled($item['current_label']));
        $needs = $rest->filter(fn ($item) => $item['suggestion'] === null && blank($item['current_label']));

        return [
            'allowed' => true,
            'ready' => $withProposal->count() + $recommended->count() + $inUse->count(),
            'proposals' => $withProposal->count(), 'recommended' => $recommended->count(), 'in_use' => $inUse->count(),
            'needs_answer' => $needs->map(fn ($item) => ['key' => $item['key'], 'title' => $item['title'], 'domain' => $item['domain']])->values()->all(),
        ];
    }

    private function area(string $key, string $label, Collection $items, string $section, ?string $verb = null): ?array
    {
        if ($items->isEmpty()) {
            return null;
        }
        $required = $items->where('required', true);
        $counted = $required->isEmpty() ? $items : $required;
        $order = array_keys(self::STATES);
        $worst = $counted->sortBy(fn ($i) => array_search($i['state'], $order, true))->first();
        $state = $worst['state'];
        $ready = $counted->where('state', 'active')->count();
        $optionalOpen = $items->where('required', false)->where('state', '!=', 'active')->count();
        $first = $worst['blocked'];

        return [
            'key' => $key, 'label' => $label, 'section' => $section, 'required' => true,
            'state' => $state, 'state_label' => self::STATES[$state][0], 'bucket' => self::STATES[$state][1],
            'total' => $counted->count(), 'ready' => $ready,
            // One item: say what the answer is (or that there is none), not the status a second time.
            'headline' => $counted->count() === 1 ? ($worst['proposal']['label'] ?? $worst['active']['label'] ?? 'No answer yet.')
                : "{$ready} of {$counted->count()} ".($verb ?? 'approved').' and active'.($optionalOpen > 0 ? " · {$optionalOpen} more left open for the accountant" : ''),
            'counts' => $counted->countBy('state')->all(),
            'why' => $first['why'] ?? null, 'next' => $first['next'] ?? null, 'who' => $first['who'] ?? null,
            'first_item' => $state === 'active' ? null : $worst['key'],
        ];
    }

    /** What chart this database holds. Never overridden by anything approved here. */
    private function structureArea(): array
    {
        $profileName = $this->catalogue->profile();
        $profile = FinanceChartProfile::load($profileName);
        if ($profile === null) {
            return ['key' => 'structure', 'label' => 'Accounting structure', 'section' => 'overview', 'required' => true,
                'state' => 'active', 'state_label' => 'Reference chart', 'bucket' => 'ready', 'total' => 1, 'ready' => 1,
                'headline' => 'This installation keeps the reference chart of accounts.', 'counts' => [], 'why' => null, 'next' => null, 'who' => null, 'first_item' => null, 'identity' => null];
        }
        $usage = DB::getSchemaBuilder()->hasTable('journal_lines')
            ? DB::table('journal_lines')->selectRaw('account_id, COUNT(*) AS n')->groupBy('account_id')->pluck('n', 'account_id')->map(fn ($n) => (int) $n)->all() : [];
        $identity = ChartIdentity::inspect($profileName, $profile, $this->catalogue->chart(), array_values(FinanceChartProfile::reuse($profileName)), $usage);
        $ok = $identity['blocking'] === null;

        return ['key' => 'structure', 'label' => 'Accounting structure', 'section' => 'overview', 'required' => true,
            'state' => $ok ? 'active' : 'conflict', 'state_label' => $ok ? 'Verified' : ($identity['identity'] === 'TWO_CHART_STATE' ? 'Two charts detected' : 'Manual review required'),
            'bucket' => $ok ? 'ready' : 'blocked', 'total' => 1, 'ready' => $ok ? 1 : 0,
            'headline' => $ok ? "This database holds the company's own chart ({$identity['counts']['total']} accounts)." : ucfirst($identity['reason']).'.',
            'counts' => [], 'identity' => array_diff_key($identity, ['blocking' => 0]),
            'why' => $ok ? null : $identity['blocking'],
            'next' => $ok ? null : 'An accountant decides which chart this database keeps. Nothing is merged, moved, renamed or adopted automatically, and nothing approved in Finance Setup overrides this.',
            'who' => $ok ? null : 'The accountant', 'first_item' => null];
    }

    private function periodsArea(): array
    {
        $today = now()->toDateString();
        $current = AccountingPeriod::forDate(now());
        $earlierOpen = DB::table('accounting_periods')->where('status', 'open')->whereDate('ends_on', '<', now()->startOfYear()->toDateString())->count();
        [$state, $label, $bucket, $why, $next] = match (true) {
            ! $current => ['decision_required', 'No current period', 'blocked', 'No accounting period covers today, so nothing can be posted.', 'Create the accounting periods for this year.'],
            ! $current->isOpen() => ['review_required', 'Current period is '.$current->status, 'decision', 'The period covering today is '.$current->status.'; postings into it are refused.', 'Reopen it with a reason, if postings are expected.'],
            $earlierOpen > 0 => ['review_required', 'Review required', 'decision', "{$earlierOpen} period(s) from earlier years are still open, so a back-dated entry would be accepted.", 'Decide which earlier periods should be closed. Nothing is closed automatically.'],
            default => ['active', 'Open', 'ready', null, null],
        };

        return ['key' => 'periods', 'label' => 'Accounting periods', 'section' => 'periods', 'required' => true, 'state' => $state, 'state_label' => $label,
            'bucket' => $bucket, 'total' => 1, 'ready' => $state === 'active' ? 1 : 0,
            'headline' => $current ? 'Current period: '.$current->starts_on->format('F Y').' is '.$current->status.'.' : 'No period covers '.$today.'.',
            'counts' => [], 'why' => $why, 'next' => $next, 'who' => $why ? 'Finance, with the accountant' : null, 'first_item' => null];
    }

    private function numberingArea(): array
    {
        $controlled = collect(DocumentNumberingRegister::series())->where('controlled', true)->count();
        $total = count(DocumentNumberingRegister::series());

        return ['key' => 'numbering', 'label' => 'Document numbering', 'section' => 'numbering', 'required' => true, 'state' => 'decision_required',
            'state_label' => 'Data required', 'bucket' => 'data', 'total' => $total, 'ready' => 0,
            'headline' => "{$controlled} of {$total} document types use a controlled, gap-protected series.",
            'counts' => [], 'why' => 'A numbering policy has not been set, and the numbers each series should continue from are not on record.',
            'next' => 'Provide the last number WNG used for each document type, and decide which documents need an unbroken series.',
            'who' => 'Finance, with the accountant', 'first_item' => null];
    }

    /** Who can actually approve. An empty list is the first thing to fix. */
    private function authority(): array
    {
        $rows = [
            Permissions::FINANCE_CONFIG_APPROVE_ACCOUNTING => 'Accountant approval',
            Permissions::FINANCE_CONFIG_APPROVE_MANAGEMENT => 'Management approval',
            Permissions::FINANCE_CONFIG_APPROVE_OPERATIONAL => 'Finance review / operational confirmation',
            Permissions::FINANCE_CONFIG_ACTIVATE => 'Activation',
        ];

        // WNG's decision: a Super Admin holds every one of these.
        try {
            $superAdmins = User::role('Super Admin')->where('is_active', true)->orderBy('name')->limit(20)->pluck('name')->all();
        } catch (\Throwable) {
            $superAdmins = [];
        }

        return collect($rows)->map(function ($label, $permission) use ($superAdmins) {
            try {
                $holders = User::permission($permission)->orderBy('name')->limit(20)->pluck('name')->all();
            } catch (\Throwable) {
                $holders = [];
            }
            $holders = array_values(array_unique([...$superAdmins, ...$holders]));

            return ['permission' => $permission, 'label' => $label, 'holders' => $holders,
                'message' => $holders === [] ? "Nobody can give {$label} yet. A Super Admin can; others are given it in Admin > Roles." : null];
        })->values()->all();
    }

    // ---- Readiness -----------------------------------------------------------

    /**
     * The governance gates as Finance readiness reports them. Each says separately
     * what the software can do and what a person has approved: 37 mappings that
     * resolve are 37 mappings that resolve, not 37 approved mappings.
     *
     * @return list<array{key: string, label: string, pass: bool, state: string, message: string, href: string}>
     */
    public function readiness(?User $user = null): array
    {
        $items = collect($this->items(null, $user));
        $gate = fn (string $key, string $label, bool $pass, string $state, string $message, string $section) => compact('key', 'label', 'pass', 'state', 'message') + ['href' => '/finance/setup?section='.$section];

        $wip = $items->firstWhere('key', GovernanceRuntime::WIP_ITEM);
        $runtime = GovernanceRuntime::instance()->wipPolicy();
        [$state, $message] = match (true) {
            $runtime['state'] === 'conflict' => ['CONFIGURATION CONFLICT', (string) $runtime['message']],
            $wip['state'] === 'active' => ['ACTIVE', 'Approved and in force: '.$wip['active']['label'].'.'],
            $wip['state'] === 'awaiting_approval' => ['AWAITING APPROVAL', 'A proposal has been submitted. Until it is approved and activated, project costs cannot post.'],
            $wip['state'] === 'awaiting_activation' => ['APPROVED — NOT ACTIVATED', 'The policy is approved but has not been activated, so it is not yet in force.'],
            $wip['state'] === 'scheduled' => ['POLICY REQUIRED', 'A policy is approved and takes effect on '.$wip['scheduled']['in_force_from'].'. Until that date there is none in force.'],
            default => ['POLICY REQUIRED', 'No project cost treatment is approved and active. A draft or a suggestion does not count.'],
        };
        if ($runtime['state'] === 'deployment') {
            $message .= " The deployment setting currently supplies '{$runtime['policy']}' so the cutover tooling can run; that is not an approval.";
        }
        $gates = [$gate('wip_policy', 'Project cost treatment (WIP policy)', $wip['state'] === 'active' && $runtime['state'] === 'governed', $state, $message, 'policies')];

        $mappings = $items->where('domain', 'mapping');
        $resolved = $mappings->filter(fn ($i) => $i['current']['resolves'] ?? false)->count();
        $approved = $mappings->where('state', 'active')->count();
        $total = $mappings->count();
        $gates[] = $gate('account_mappings', 'Account mappings', $approved === $total,
            $approved === $total ? 'APPROVED' : ($resolved === $total ? 'ACCOUNTANT APPROVAL REQUIRED' : 'CONFIGURATION REQUIRED'),
            "{$resolved} of {$total} posting functions technically resolve to a usable account. {$approved} of {$total} are approved by the accountant and active."
                .($approved < $total ? ' Resolving is not approval.' : ''), 'mapping');

        $classifications = $items->where('domain', 'classification')->where('required', true);
        if ($classifications->isNotEmpty()) {
            $done = $classifications->where('state', 'active')->count();
            $open = $items->where('domain', 'classification')->where('required', false)->where('state', '!=', 'active')->count();
            $gates[] = $gate('account_classification', 'Account classification', $done === $classifications->count(),
                $done === $classifications->count() ? 'APPROVED' : 'ACCOUNTANT APPROVAL REQUIRED',
                "{$done} of {$classifications->count()} proposed classifications are approved and active.".($open > 0 ? " {$open} account(s) are deliberately left for the accountant to classify." : ''), 'classification');
        }

        foreach (['channel.mpesa' => ['mpesa', 'M-Pesa'], 'channel.card' => ['card', 'Company card']] as $key => [$gateKey, $label]) {
            if ($item = $items->firstWhere('key', $key)) {
                $gates[] = $gate($gateKey, $label, $item['state'] === 'active', strtoupper($item['state_label']),
                    $item['state'] === 'active' ? $item['active']['label'].'.' : ($item['blocked']['why'] ?? 'Not decided.'), 'banks');
            }
        }

        return $gates;
    }

    // ---- History -------------------------------------------------------------

    /** @return list<array> */
    public function history(?string $key = null, int $limit = 100, ?string $domain = null): array
    {
        $catalogue = $this->catalogue->items();
        $rows = DB::table('finance_config_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->when($key, fn ($q) => $q->where('a.item_key', $key))
            ->when($domain, fn ($q) => $q->whereIn('a.item_key', array_keys(array_filter($catalogue, fn ($i) => $i['domain'] === $domain))))
            ->orderByDesc('a.id')->limit($limit)->get(['a.*', 'u.name as actor_name']);
        $labels = ['created' => 'Drafted', 'edited' => 'Edited', 'submitted' => 'Submitted for approval', 'review_started' => 'Review started', 'returned' => 'Returned for correction',
            'rejected' => 'Rejected', 'approved' => 'Approved', 'activated' => 'Activated', 'superseded' => 'Superseded', 'withdrawn' => 'Withdrawn'];

        return $rows->map(function ($row) use ($catalogue, $labels) {
            $item = $catalogue[$row->item_key] ?? null;
            $before = $row->before ? json_decode($row->before, true) : null;
            $after = $row->after ? json_decode($row->after, true) : null;
            $describe = fn (?array $snapshot) => $snapshot === null || $item === null ? null : $this->catalogue->describe($item, (array) ($snapshot['value'] ?? []));

            return ['id' => $row->id, 'item_key' => $row->item_key, 'item' => $item['title'] ?? $row->item_key, 'domain' => $item['domain'] ?? null,
                'action' => $row->action, 'action_label' => $labels[$row->action] ?? ucfirst($row->action), 'actor' => $row->actor_name ?? 'Former or unavailable user',
                'at' => $row->created_at, 'version' => $after['version'] ?? $before['version'] ?? null,
                'before' => $row->action === 'created' ? null : $describe($before), 'after' => $describe($after),
                'reason' => $row->reason, 'effective_from' => $row->effective_from];
        })->all();
    }
}
