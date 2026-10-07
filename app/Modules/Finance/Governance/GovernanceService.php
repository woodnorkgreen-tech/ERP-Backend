<?php

namespace App\Modules\Finance\Governance;

use App\Constants\Permissions;
use App\Models\User;
use App\Modules\Finance\Governance\FinanceConfigVersion as Version;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The one way a Finance configuration changes (Report 75).
 *
 *   draft → submitted → (under review) → approved → activated → in force on its date
 *                    ↘ returned for correction → resubmitted
 *                    ↘ rejected                    (draft/returned may be withdrawn)
 *
 * Nothing a person clicks changes how the books behave except `activate()`, and
 * that refuses anything not approved by somebody entitled to approve it.
 *
 * Rules held here, each also held by the database where it can be:
 *  - one open proposal per item (unique open_key);
 *  - a decided version is never edited; a change is a new version;
 *  - whoever acts quotes the revision they looked at, and a stale one is refused;
 *  - nobody approves their own proposal where accounting or management authority
 *    is required;
 *  - approving and activating need permissions that are checked directly, so the
 *    Super Admin bypass does not confer them;
 *  - a version comes into force no earlier than the day it is activated, so no
 *    past transaction changes meaning;
 *  - two versions of one item never come into force on the same day (unique
 *    active_key), and activating one closes the one before it on the day before.
 */
class GovernanceService
{
    public function __construct(private GovernanceCatalogue $catalogue, private GovernanceApplier $applier)
    {
    }

    // ---- Authority -----------------------------------------------------------

    /**
     * WNG's decision (October 2026): a Super Admin decides Finance setup directly.
     *
     * Report 75 held approval and activation back from everyone until WNG named
     * who should hold them. WNG has now said: a Super Admin may approve and apply
     * any setting in one step, without a second person. That is a business
     * decision about who carries the responsibility, and it is recorded on every
     * change made this way — each one still names who applied it and when.
     */
    public static function decidesDirectly(?User $user): bool
    {
        try {
            return (bool) $user?->is_active && $user->hasRole('Super Admin');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the user holds a permission in their own right. Deliberately not
     * `can()`. A Super Admin holds every Finance setup authority by WNG's
     * decision above; everyone else only what they were given.
     */
    public static function holds(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }
        if (str_starts_with($permission, 'finance.config.') && self::decidesDirectly($user)) {
            return true;
        }
        try {
            return $user->hasPermissionTo($permission);
        } catch (\Throwable) {
            return false;   // the permission has not been created on this database
        }
    }

    private function require(User $user, string $permission, string $doing): void
    {
        if (! self::holds($user, $permission)) {
            abort(403, "You are not authorised to {$doing}.");
        }
    }

    public function approvalPermission(array $item): string
    {
        return GovernanceCatalogue::REQUIREMENTS[$item['requirement']]['permission'];
    }

    // ---- Reading -------------------------------------------------------------

    private function item(string $key): array
    {
        return $this->catalogue->item($key) ?? abort(404, 'That configuration item does not exist.');
    }

    /** The open proposal for an item, if any. */
    public function open(string $key): ?Version
    {
        return Version::query()->where('open_key', $key)->first();
    }

    /** Values in force for other items, for rules that compare two settings. */
    private function othersInForce(): array
    {
        return GovernanceRuntime::instance()->inForce();
    }

    // ---- Preparing -----------------------------------------------------------

    public function draft(string $key, User $user, array $value, ?string $reason, ?string $effectiveFrom): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_PROPOSE, 'prepare Finance configuration');
        $item = $this->item($key);
        $value = $this->catalogue->validate($item, $value, $this->othersInForce());
        $this->checkEffectiveDate($effectiveFrom, false);

        return DB::transaction(function () use ($item, $user, $value, $reason, $effectiveFrom) {
            // Serialise on the item: version numbers must not collide.
            $latest = Version::query()->where('item_key', $item['key'])->lockForUpdate()->orderByDesc('version')->first();
            if (Version::query()->where('open_key', $item['key'])->exists()) {
                throw ValidationException::withMessages(['item' => 'There is already a proposal in progress for this. Finish or withdraw it before starting another.']);
            }
            try {
                $version = Version::create([
                    'item_key' => $item['key'], 'domain' => $item['domain'], 'version' => ($latest?->version ?? 0) + 1,
                    'value' => $value, 'reason' => $this->clean($reason), 'effective_from' => $effectiveFrom,
                    'status' => Version::DRAFT, 'revision' => 1, 'proposed_by' => $user->id, 'open_key' => $item['key'],
                ]);
            } catch (QueryException $e) {
                // Two people started at the same moment: the unique key let one through.
                throw new ConflictHttpException('Somebody else has just started a proposal for this. Reload to see it.', $e);
            }
            $this->audit($version, 'created', $user, null, $version, $version->reason);

            return $version;
        });
    }

    public function update(Version $version, User $user, int $revision, array $value, ?string $reason, ?string $effectiveFrom): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_PROPOSE, 'prepare Finance configuration');

        return $this->change($version, $user, $revision, [Version::DRAFT, Version::RETURNED], 'edited', function (Version $v, array $item) use ($value, $reason, $effectiveFrom) {
            $this->checkEffectiveDate($effectiveFrom, false);
            $v->fill(['value' => $this->catalogue->validate($item, $value, $this->othersInForce()), 'reason' => $this->clean($reason), 'effective_from' => $effectiveFrom]);

            return $v->reason;
        });
    }

    public function submit(Version $version, User $user, int $revision): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_PROPOSE, 'submit Finance configuration');

        return $this->change($version, $user, $revision, [Version::DRAFT, Version::RETURNED], 'submitted', function (Version $v, array $item) {
            if (blank($v->reason)) {
                throw ValidationException::withMessages(['reason' => 'Say why this is being proposed. The approver reads this.']);
            }
            $this->checkEffectiveDate($v->effective_from?->toDateString(), true);
            // Re-checked: the chart, or a related setting, may have moved since the draft was written.
            $v->value = $this->catalogue->validate($item, (array) $v->value, $this->othersInForce());
            $v->fill(['status' => Version::SUBMITTED, 'submitted_at' => now(),
                'reviewed_by' => null, 'reviewed_at' => null, 'decided_by' => null, 'decided_at' => null, 'decision_comment' => null]);

            return $v->reason;
        });
    }

    public function withdraw(Version $version, User $user, int $revision, ?string $comment): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_PROPOSE, 'withdraw Finance configuration');

        return $this->change($version, $user, $revision, [Version::DRAFT, Version::RETURNED, Version::SUBMITTED], 'withdrawn', function (Version $v) use ($comment) {
            $v->fill(['status' => Version::WITHDRAWN, 'open_key' => null, 'decision_comment' => $this->clean($comment)]);

            return $v->decision_comment;
        });
    }

    // ---- Reviewing -----------------------------------------------------------

    public function startReview(Version $version, User $user, int $revision): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_REVIEW, 'review Finance configuration');

        return $this->change($version, $user, $revision, [Version::SUBMITTED], 'review_started', function (Version $v, array $item) use ($user) {
            $v->fill(['status' => Version::UNDER_REVIEW, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $this->record($v, $item, 'reviewed', $user, null);

            return null;
        });
    }

    /** Send it back to whoever prepared it. A reviewer or an approver may. */
    public function returnForCorrection(Version $version, User $user, int $revision, string $comment): Version
    {
        $item = $this->item($version->item_key);
        if (! self::holds($user, Permissions::FINANCE_CONFIG_REVIEW) && ! self::holds($user, $this->approvalPermission($item))) {
            abort(403, 'You are not authorised to return this for correction.');
        }

        return $this->change($version, $user, $revision, [Version::SUBMITTED, Version::UNDER_REVIEW, Version::APPROVED], 'returned', function (Version $v, array $item) use ($user, $comment) {
            $comment = $this->clean($comment) ?: throw ValidationException::withMessages(['comment' => 'Say what needs correcting.']);
            $v->fill(['status' => Version::RETURNED, 'decision_comment' => $comment, 'decided_by' => null, 'decided_at' => null]);
            $this->record($v, $item, 'returned', $user, $comment);

            return $comment;
        });
    }

    public function reject(Version $version, User $user, int $revision, string $comment): Version
    {
        $item = $this->item($version->item_key);
        $this->require($user, $this->approvalPermission($item), 'reject this');

        return $this->change($version, $user, $revision, [Version::SUBMITTED, Version::UNDER_REVIEW], 'rejected', function (Version $v, array $item) use ($user, $comment) {
            $comment = $this->clean($comment) ?: throw ValidationException::withMessages(['comment' => 'Say why this is rejected.']);
            $v->fill(['status' => Version::REJECTED, 'open_key' => null, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_comment' => $comment]);
            $this->record($v, $item, 'rejected', $user, $comment);

            return $comment;
        });
    }

    // ---- Approving -----------------------------------------------------------

    public function approve(Version $version, User $user, int $revision, ?string $comment = null): Version
    {
        $item = $this->item($version->item_key);
        $this->require($user, $this->approvalPermission($item), 'approve this ('.$item['requirement_label'].' is required)');

        return $this->change($version, $user, $revision, [Version::SUBMITTED, Version::UNDER_REVIEW], 'approved', function (Version $v, array $item) use ($user, $comment) {
            $this->refuseSelfApproval($v, $item, $user);
            // What is approved must still be valid at the moment it is approved.
            $v->value = $this->catalogue->validate($item, (array) $v->value, $this->othersInForce());
            $v->fill(['status' => Version::APPROVED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_comment' => $this->clean($comment)]);
            $this->record($v, $item, 'approved', $user, $this->clean($comment));

            return $this->clean($comment);
        });
    }

    /**
     * Approve several at once. Everything named is approved, or nothing is: an
     * accountant who was shown twelve mappings has approved those twelve.
     *
     * @param  list<array{id: int, revision: int}>  $selection  exactly what the approver was shown
     * @return list<Version>
     */
    public function approveMany(array $selection, User $user, ?string $comment = null): array
    {
        if ($selection === []) {
            throw ValidationException::withMessages(['selection' => 'Select at least one item to approve.']);
        }

        return DB::transaction(function () use ($selection, $user, $comment) {
            $approved = [];
            foreach ($selection as $choice) {
                $version = Version::query()->findOrFail((int) $choice['id']);
                $item = $this->item($version->item_key);
                if (! ($item['bulk'] ?? false)) {
                    throw ValidationException::withMessages(['selection' => "{$item['title']} needs an individual decision and cannot be approved in bulk."]);
                }
                $approved[] = $this->approve($version, $user, (int) $choice['revision'], $comment);
            }

            return $approved;
        });
    }

    private function refuseSelfApproval(Version $version, array $item, User $user): void
    {
        if ((int) $version->proposed_by !== (int) $user->id || self::decidesDirectly($user)) {
            return;
        }
        // Accounting and management decisions: never your own, whatever else you hold.
        if (in_array($item['requirement'], ['accountant_approval', 'management_approval'], true)) {
            throw ValidationException::withMessages(['approval' => 'You prepared this proposal, so you cannot approve it. '.$item['requirement_label'].' must come from someone else.']);
        }
        // Operational items: only with the explicit self-approval permission, and it is recorded.
        if (! self::holds($user, Permissions::APPROVALS_SELF_APPROVE)) {
            throw ValidationException::withMessages(['approval' => 'You prepared this proposal, so you cannot approve it.']);
        }
    }

    // ---- Activating ----------------------------------------------------------

    public function activate(Version $version, User $user, int $revision): Version
    {
        $this->require($user, Permissions::FINANCE_CONFIG_ACTIVATE, 'activate Finance configuration');

        return $this->change($version, $user, $revision, [Version::APPROVED], 'activated', function (Version $v, array $item) use ($user) {
            $today = Carbon::today();
            $requested = $v->effective_from ? Carbon::parse($v->effective_from)->startOfDay() : $today;
            $immediate = in_array($item['applies'], ['payment_source', 'float_limit'], true);
            // A change written straight into a live record takes effect the moment it is
            // written, so it cannot be activated ahead of its date.
            if ($immediate && $requested->gt($today)) {
                throw ValidationException::withMessages(['effective_from' => 'This takes effect the moment it is activated, and its approved date is '.$requested->toFormattedDateString().'. Activate it on or after that date.']);
            }
            $inForceFrom = $requested->gt($today) ? $requested : $today;

            // Still valid, on the day it starts to matter.
            $v->value = $this->catalogue->validate($item, (array) $v->value, $this->othersInForce());

            // Lock the item's activated versions; the one in force until now ends the day before.
            $activated = Version::query()->where('item_key', $v->item_key)->where('status', Version::ACTIVE)->lockForUpdate()->orderBy('in_force_from')->get();
            if ($later = $activated->first(fn (Version $a) => $a->in_force_from->gte($inForceFrom))) {
                throw ValidationException::withMessages(['effective_from' => 'Version '.$later->version.' already takes effect on '.$later->in_force_from->toFormattedDateString().'. A new version must start after it.']);
            }
            $previous = $activated->last();
            if ($previous && ($previous->in_force_to === null || $previous->in_force_to->gte($inForceFrom))) {
                $previous->forceFill(['in_force_to' => $inForceFrom->copy()->subDay()->toDateString(), 'revision' => $previous->revision + 1])->save();
                $this->audit($previous, 'superseded', $user, null, $previous, 'Replaced by version '.$v->version, $inForceFrom->toDateString());
            }

            $v->fill(['status' => Version::ACTIVE, 'open_key' => null, 'activated_by' => $user->id, 'activated_at' => now(),
                'in_force_from' => $inForceFrom->toDateString(), 'supersedes_id' => $previous?->id,
                'active_key' => $v->item_key.'|'.$inForceFrom->toDateString()]);
            // The change itself: written to the record the existing code already reads.
            $this->applier->apply($item, $v, $user);

            return 'In force from '.$inForceFrom->toFormattedDateString();
        });
    }

    /**
     * Activate several approved proposals at once. Every one named is activated, or
     * none is. Each was approved on its own account; this only spares the activator
     * pressing the same button for each of 31 mappings. The list they confirmed is
     * the list activated, at the revisions they were shown.
     *
     * @param  list<array{id: int, revision: int}>  $selection
     * @return list<Version>
     */
    public function activateMany(array $selection, User $user): array
    {
        if ($selection === []) {
            throw ValidationException::withMessages(['selection' => 'Select at least one approved proposal to activate.']);
        }

        return DB::transaction(fn () => array_map(
            fn (array $choice) => $this->activate(Version::query()->findOrFail((int) $choice['id']), $user, (int) $choice['revision']),
            $selection,
        ));
    }

    // ---- Deciding directly (Super Admin) ---------------------------------------

    public const DIRECT_REASON = 'Approved and applied directly by a Super Admin.';

    /**
     * Approve and apply one setting in a single step.
     *
     * The same four records are written as in the long route — prepared,
     * submitted, approved, activated — so the history is complete; they are simply
     * all made by one person at one moment. A proposal somebody else already
     * started for the item is carried through rather than replaced, unless a new
     * value is given while it can still be edited.
     *
     * @param  ?array  $value  the answer to apply, or null to carry an open proposal through
     */
    public function applyNow(string $key, User $user, ?array $value, ?string $reason = null): Version
    {
        if (! self::decidesDirectly($user)) {
            abort(403, 'Only a Super Admin can approve and apply a Finance setting in one step.');
        }
        $reason = $this->clean($reason) ?: self::DIRECT_REASON;
        $today = Carbon::today()->toDateString();

        return DB::transaction(function () use ($key, $user, $value, $reason, $today) {
            $version = $this->open($key);
            if ($version === null) {
                if ($value === null) {
                    throw ValidationException::withMessages(['value' => 'Choose an answer for this setting.']);
                }
                $version = $this->draft($key, $user, $value, $reason, $today);
            } elseif (in_array($version->status, [Version::DRAFT, Version::RETURNED], true)) {
                $version = $this->update($version, $user, $version->revision, $value ?? (array) $version->value,
                    $version->reason ?: $reason, $version->effective_from?->toDateString() ?? $today);
            }
            if (in_array($version->status, [Version::DRAFT, Version::RETURNED], true)) {
                $version = $this->submit($version, $user, $version->revision);
            }
            if (in_array($version->status, [Version::SUBMITTED, Version::UNDER_REVIEW], true)) {
                $version = $this->approve($version, $user, $version->revision, self::DIRECT_REASON);
            }

            return $this->activate($version, $user, $version->revision);
        });
    }

    /**
     * Approve and apply everything that already has an answer, in one go.
     *
     * For each setting not yet decided: an open proposal is carried through; a
     * setting with a recommended answer gets that answer; a setting the system is
     * already using a value for has that value confirmed. A setting with no answer
     * to confirm is left for the Super Admin to choose — nothing is made up. One
     * setting that cannot be applied does not stop the others.
     *
     * @param  ?list<string>  $keys  limit to these settings; null for all
     * @return array{applied: list<array>, needs_answer: list<array>, skipped: list<array>}
     */
    public function applyAll(User $user, ?array $keys = null): array
    {
        if (! self::decidesDirectly($user)) {
            abort(403, 'Only a Super Admin can approve and apply Finance settings in one step.');
        }
        $result = ['applied' => [], 'needs_answer' => [], 'skipped' => []];

        foreach ($this->catalogue->items() as $key => $item) {
            if ($keys !== null && ! in_array($key, $keys, true)) {
                continue;
            }
            $label = ['key' => $key, 'title' => $item['title'], 'domain' => $item['domain']];
            $open = $this->open($key);
            if ($open === null && GovernanceRuntime::instance()->value($key) !== null) {
                continue;   // already decided and in force
            }

            $value = null;
            if ($open === null) {
                foreach (array_filter([$item['suggestion'], $item['current']]) as $candidate) {
                    try {
                        $value = $this->catalogue->validate($item, (array) $candidate, $this->othersInForce());
                        break;
                    } catch (ValidationException) {
                        // not a usable answer; try the next, or leave it for a person
                    }
                }
                if ($value === null) {
                    $result['needs_answer'][] = $label;

                    continue;
                }
            }

            try {
                $applied = $this->applyNow($key, $user, $value);
                $result['applied'][] = $label + ['answer' => $this->catalogue->describe($item, (array) $applied->value),
                    'basis' => $open ? 'proposal' : ($item['suggestion'] !== null ? 'recommended' : 'in_use')];
            } catch (ValidationException $e) {
                $result['skipped'][] = $label + ['reason' => collect($e->errors())->flatten()->first()];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $result['skipped'][] = $label + ['reason' => $e->getMessage()];
            }
        }

        return $result;
    }

    // ---- The one place a version changes --------------------------------------

    /**
     * Lock the version, check the caller saw its current revision and that it is
     * in a state the action allows, change it, bump the revision, audit it.
     *
     * @param  list<string>  $from
     * @param  \Closure(Version, array): ?string  $mutate  returns the reason to record
     */
    private function change(Version $version, User $user, int $revision, array $from, string $action, \Closure $mutate): Version
    {
        return DB::transaction(function () use ($version, $user, $revision, $from, $action, $mutate) {
            $locked = Version::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->revision !== $revision) {
                throw new ConflictHttpException('This proposal has changed since you opened it. Reload it and look again before acting.');
            }
            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'This proposal is '.str_replace('_', ' ', $locked->status).', so that action is not available.']);
            }
            $item = $this->item($locked->item_key);
            $before = $locked->replicate(['id']);
            $before->id = $locked->id;

            $reason = $mutate($locked, $item);
            $locked->revision = $revision + 1;
            try {
                $locked->save();
            } catch (QueryException $e) {
                throw new ConflictHttpException('Another change to this item was made at the same moment. Reload and try again.', $e);
            }
            $this->audit($locked, $action, $user, $before, $locked, $reason);
            GovernanceRuntime::flush();

            return $locked;
        });
    }

    private function checkEffectiveDate(?string $date, bool $required): void
    {
        if (blank($date)) {
            if ($required) {
                throw ValidationException::withMessages(['effective_from' => 'Say from which date this should apply.']);
            }

            return;
        }
        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_from' => 'Enter a valid date.']);
        }
        if ($parsed->lt(Carbon::today())) {
            throw ValidationException::withMessages(['effective_from' => 'The effective date cannot be in the past: a decision made today cannot change what was posted before it.']);
        }
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }

    private function record(Version $version, array $item, string $decision, User $user, ?string $comment): void
    {
        FinanceConfigApproval::create(['version_id' => $version->id, 'requirement' => $item['requirement'],
            'decision' => $decision, 'actor_id' => $user->id, 'comment' => $comment]);
    }

    private function audit(Version $version, string $action, ?User $user, ?Version $before, ?Version $after, ?string $reason, ?string $effective = null): void
    {
        $snapshot = fn (?Version $v) => $v === null ? null : json_encode([
            'version' => $v->version, 'status' => $v->status, 'value' => $v->value, 'reason' => $v->reason,
            'effective_from' => $v->effective_from?->toDateString(), 'in_force_from' => $v->in_force_from?->toDateString(),
            'in_force_to' => $v->in_force_to?->toDateString(),
        ]);
        DB::table('finance_config_audit')->insert([
            'item_key' => $version->item_key, 'version_id' => $version->id, 'action' => $action, 'actor_id' => $user?->id,
            'before' => $snapshot($before), 'after' => $snapshot($after), 'reason' => $reason,
            'effective_from' => $effective ?? ($after?->in_force_from?->toDateString() ?? $after?->effective_from?->toDateString()),
            'created_at' => now(),
        ]);
    }
}
