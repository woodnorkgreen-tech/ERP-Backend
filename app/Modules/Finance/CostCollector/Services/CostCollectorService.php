<?php

namespace App\Modules\Finance\CostCollector\Services;

use App\Modules\Finance\CostCollector\Contracts\CollectsCost;
use App\Modules\Finance\CostCollector\Contracts\CostContext;
use App\Modules\Finance\CostCollector\Exceptions\CostValidationException;
use App\Modules\Finance\CostCollector\Models\AccountingPeriod;
use App\Modules\Finance\CostCollector\Models\CostLine;
use App\Modules\Finance\CostCollector\Contracts\PlannedLine;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Services\JournalPostingService;
use App\Modules\ProcurementStores\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of cost lines.
 *
 * Every cost in the ERP — captured on a phone, posted by Stores when material is
 * issued, posted by HR when overtime is approved — arrives here. Nothing else
 * inserts into `cost_lines`, which is what makes the invariants (append-only,
 * period-checked, idempotent) hold rather than merely being intended.
 *
 * Validation is driven entirely by the expense catalogue. There is no hardcoded
 * knowledge of what a particular expense type requires, so Finance changing the
 * evidence for site purchases is a row edit and not a release.
 */
class CostCollectorService implements CollectsCost
{
    public function __construct(
        private CostContextResolver $resolver,
        private CostNotifier $notifier,
        private JournalPostingService $journalPosting,
    ) {}

    public function collect(CostContext $context): CostLine
    {
        $code = $this->expenseCode($context);

        // A producer that retries — a GRN sync, a payroll run, a re-run backfill —
        // must be a no-op rather than a duplicate. Checked before validation so a
        // catalogue rule tightened after the fact cannot make an already-recorded
        // cost start throwing on retry.
        if ($existing = $this->existingFor($context)) {
            return $existing;
        }

        $resolved = $this->resolver->resolve($context, $code);

        $this->validate($context, $code, $resolved);

        return DB::transaction(function () use ($context, $code, $resolved) {
            $money = $this->money($context);
            $budget = $this->budgetSnapshot($context, $money['net_amount']);

            $line = CostLine::create([
                ...$resolved,
                ...$money,
                ...$budget,
                'ref' => 'PENDING',
                'nature' => $context->nature,
                'status' => $this->initialStatus($context),
                'consumes_line_id' => $context->consumesLineId,
                'description' => $context->description,
                ...$this->measure($context),
                'source_type' => $context->sourceType,
                'source_id' => $context->sourceId,
                'source_ref' => $context->sourceRef,
                'details' => $context->details ?: null,
                'evidence' => $context->evidence ?: null,
                'submitted_by_user_id' => auth()->id(),
                'payee_id' => $context->payeeId,
                'payee_name' => $context->payeeName,
                // Left deliberately without a verifier: the approval was the
                // source document's, and attributing it to a person who never
                // looked at this line would be a false audit trail.
                'verified_at' => $this->initialStatus($context) === CostLine::STATUS_VERIFIED ? now() : null,
            ]);

            // Assigned from the primary key so it is unique without a counter to
            // race on. job_number already carries the project context.
            $line->forceFill(['ref' => 'CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT)])->save();

            if ($line->status === CostLine::STATUS_VERIFIED
                && in_array($line->nature, [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL], true)) {
                $this->journalPosting->postCostLine($line);
            }

            // Only a cost that is actually waiting warrants telling anyone. A
            // producer-posted line lands verified and needs no queue.
            if ($line->status === CostLine::STATUS_SUBMITTED) {
                $this->notifier->submitted($line);
            }

            return $line;
        });
    }

    /** @param array{amount:string,description:?string,response:string} $changes */
    public function correctQueried(CostLine $line, User $user, array $changes): CostLine
    {
        $payeeType = $line->payee_type_id
            ? DB::table('payee_types')->whereKey($line->payee_type_id)->value('code')
            : null;
        $context = new CostContext(
            expenseCode: (string) $line->expenseCode?->code,
            amount: $changes['amount'], nature: CostLine::NATURE_ACTUAL,
            projectId: $line->project_id, enquiryId: $line->project_enquiry_id,
            jobNumber: $line->job_number, incurredAt: $line->incurred_at?->toIso8601String(),
            currency: $line->currency, fxRate: (string) $line->fx_rate,
            payeeType: $payeeType, payeeId: $line->payee_id, payeeName: $line->payee_name,
            consumesLineId: $line->consumes_line_id, details: $line->details ?? [],
            evidence: $line->evidence ?? [], description: $changes['description'],
        );
        $code = $this->expenseCode($context);
        $resolved = $this->resolver->resolve($context, $code);
        $this->validate($context, $code, $resolved);

        return DB::transaction(function () use ($line, $user, $changes, $context, $resolved) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->status !== CostLine::STATUS_QUERIED
                || $line->submitted_by_user_id !== $user->id || $line->posted_at) {
                throw CostValidationException::withErrors([
                    'status' => ['Only the reporter can correct their queried, unposted cost.'],
                ]);
            }
            $money = $this->money($context);
            $meta = $line->capture_meta ?? [];
            $meta['revisions'][] = [
                'before' => ['amount' => (string) $line->amount, 'description' => $line->description],
                'after' => ['amount' => $money['amount'], 'description' => $changes['description']],
                'response' => $changes['response'], 'revised_by' => $user->id,
                'revised_at' => now()->toIso8601String(),
            ];
            $line->forceFill([
                ...$resolved, ...$money, ...$this->budgetSnapshot($context, $money['net_amount']),
                'description' => $changes['description'], 'status' => CostLine::STATUS_SUBMITTED,
                'capture_meta' => $meta,
            ])->save();
            $this->notifier->submitted($line);
            return $line;
        });
    }

    /** Release an authoritative source commitment; commitments never reach GL. */
    public function releaseCommitment(CostLine $line, string $reason): void
    {
        DB::transaction(function () use ($line, $reason) {
            $line = CostLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->nature === CostLine::NATURE_COMMITTED
                && $line->status === CostLine::STATUS_VERIFIED && ! $line->posted_at) {
                $line->forceFill([
                    'status' => CostLine::STATUS_REVERSED,
                    'query_note' => $reason,
                    'verified_at' => now(),
                ])->save();
            }
        });
    }

    /**
     * Post a budget line as `planned`.
     *
     * Kept on this class rather than letting the projector write directly,
     * because "CostCollectorService is the only writer of cost_lines" is what
     * makes the append-only and period invariants true instead of merely
     * intended. A second writer would quietly repeal both.
     *
     * Planned lines land VERIFIED: completing the budget task is the approval,
     * and queuing a budget for someone to re-approve line by line would be
     * ceremony with no decision behind it.
     */
    public function postPlanned(PlannedLine $line): CostLine
    {
        $this->validatePlanned($line);

        $context = new CostContext(
            expenseCode: '',
            amount: $line->amount,
            nature: CostLine::NATURE_PLANNED,
            projectId: $line->projectId,
            enquiryId: $line->enquiryId,
            jobNumber: $line->jobNumber,
            taskId: $line->taskId,
            costCause: $line->isAddition ? 'CLIENT-CHANGE' : null,
        );

        // Reuses the same resolution path as a captured cost, so a planned line
        // and the actual that consumes it are classified identically. Only the
        // expense code is absent — the catalogue describes spending, and a budget
        // line predates the decision about what will actually be bought.
        $resolved = $this->resolver->resolve($context, new ExpenseCode());

        return DB::transaction(function () use ($line, $resolved) {
            $existing = CostLine::where('source_type', $line->sourceType)
                ->where('source_id', $line->sourceId)
                ->where('source_ref', $line->sourceRef)
                ->lockForUpdate()
                ->first();

            if ($existing && $this->plannedLineMatches($existing, $line, $resolved)) {
                return $existing;
            }

            if ($existing) {
                // Preserve the old financial fact, but release the canonical
                // source key for its replacement. Consumers remain linked to the
                // exact budget version against which their spend was approved.
                $existing->forceFill([
                    'source_ref' => 'REV-' . $existing->id . '-' . substr(sha1((string) $line->sourceRef), 0, 16),
                    'status' => CostLine::STATUS_REVERSED,
                    'query_note' => 'Superseded by a revised project budget line.',
                ])->save();
            }

            $created = CostLine::create([
                ...$resolved,
                'ref' => 'PENDING',
                'nature' => CostLine::NATURE_PLANNED,
                'status' => CostLine::STATUS_VERIFIED,
                'verified_at' => now(),
                'currency' => 'KES',
                'fx_rate' => '1',
                'amount' => $line->amount,
                'tax_amount' => '0.00',
                'net_amount' => $line->amount,
                'base_net_amount' => $line->amount,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'unit_rate' => $line->unitRate,
                'source_type' => $line->sourceType,
                'source_id' => $line->sourceId,
                'source_ref' => $line->sourceRef,
                'details' => [
                    'budget_category' => $line->category,
                    'is_addition' => $line->isAddition,
                    ...$line->details,
                ],
            ]);

            $created->forceFill(['ref' => 'CL-' . str_pad((string) $created->id, 7, '0', STR_PAD_LEFT)])->save();

            return $created;
        });
    }

    /** @param array<string, mixed> $resolved */
    private function plannedLineMatches(CostLine $existing, PlannedLine $line, array $resolved): bool
    {
        return $existing->status === CostLine::STATUS_VERIFIED
            && bccomp((string) $existing->net_amount, $line->amount, 2) === 0
            && (string) $existing->description === (string) $line->description
            && (string) $existing->unit === (string) $line->unit
            && $this->sameDecimal($existing->quantity, $line->quantity, 3)
            && $this->sameDecimal($existing->unit_rate, $line->unitRate, 4)
            && ($existing->details['budget_category'] ?? null) === $line->category
            && (bool) ($existing->details['is_addition'] ?? false) === $line->isAddition
            && collect($line->details)->every(fn ($value, $key) =>
                (string) ($existing->details[$key] ?? '') === (string) $value
            )
            && $existing->project_id === ($resolved['project_id'] ?? null)
            && $existing->project_enquiry_id === ($resolved['project_enquiry_id'] ?? null)
            && $existing->activity_id === ($resolved['activity_id'] ?? null)
            && $existing->cost_cause_id === ($resolved['cost_cause_id'] ?? null);
    }

    private function sameDecimal(mixed $left, mixed $right, int $scale): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return bccomp((string) $left, (string) $right, $scale) === 0;
    }

    /**
     * Post a cost from a source document that already carried its own approval —
     * a paid disbursement, an approved GRN, a completed payroll run.
     *
     * Catalogue validation is deliberately NOT applied here, and only here. It
     * exists to make human capture correct; a payment that already happened is
     * not a proposal to be corrected. Refusing to import 1,384 real payments
     * because the catalogue cannot yet classify them would leave every cost
     * account understated, which is a worse answer than an unclassified line.
     *
     * Everything else still holds: producer-only (a source is required), append
     * -only, idempotent, and period-checked.
     */
    public function postFromSource(CostContext $context, array $attributes = []): CostLine
    {
        if (blank($context->sourceType) || ! $context->sourceId) {
            throw CostValidationException::withErrors([
                'sourceType' => ['postFromSource is for producers; a source document is required.'],
            ]);
        }

        if ($existing = $this->existingFor($context)) {
            return $existing;
        }

        $code = filled($context->expenseCode)
            ? ExpenseCode::active()->where('code', $context->expenseCode)->first()
            : null;

        $resolved = $this->resolver->resolve($context, $code ?? new ExpenseCode());

        $errors = [];
        $this->validatePeriod($resolved, $errors);
        if ($errors) {
            throw CostValidationException::withErrors($errors);
        }

        return DB::transaction(function () use ($context, $code, $resolved, $attributes) {
            $money = $this->money($context);

            $line = CostLine::create([
                ...$resolved,
                ...$money,
                ...$this->measure($context),
                // Caller overrides last: postStockReturn signs its own amounts.
                ...$attributes,
                'expense_code_id' => $code?->id,
                'ref' => 'PENDING',
                'nature' => $context->nature,
                'status' => CostLine::STATUS_VERIFIED,
                'verified_at' => now(),
                'consumes_line_id' => $context->consumesLineId,
                'description' => $context->description,
                'source_type' => $context->sourceType,
                'source_id' => $context->sourceId,
                'source_ref' => $context->sourceRef,
                'details' => $context->details ?: null,
                'payee_name' => $context->payeeName,
            ]);

            $line->forceFill(['ref' => 'CL-' . str_pad((string) $line->id, 7, '0', STR_PAD_LEFT)])->save();

            if (in_array($line->nature, [CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL], true)) {
                $this->journalPosting->postCostLine($line);
            }

            return $line;
        });
    }

    /**
     * How much of what, at what rate.
     *
     * Producers report this in `details` under whichever name their own domain
     * uses — a Stores issue calls the rate `unit_cost`, a requisition calls it
     * `unit_price`. Lifting it onto the columns here means every cost line
     * carries its quantity the same way, whoever reported it, so a cost account
     * can show "4 sheets at 2,250" instead of only a total.
     *
     * @return array<string, mixed>
     */
    private function measure(CostContext $context): array
    {
        return [
            'unit' => $context->details['uom'] ?? $context->details['unit'] ?? null,
            'quantity' => $context->details['quantity'] ?? null,
            'unit_rate' => $context->details['unit_price']
                ?? $context->details['unit_rate']
                ?? $context->details['unit_cost']
                ?? null,
        ];
    }

    private function existingPlanned(PlannedLine $line): ?CostLine
    {
        return CostLine::where('source_type', $line->sourceType)
            ->where('source_id', $line->sourceId)
            ->where('source_ref', $line->sourceRef)
            ->first();
    }

    private function validatePlanned(PlannedLine $line): void
    {
        $errors = [];

        if (! is_numeric($line->amount)) {
            $errors['amount'][] = 'Budget line amount must be numeric.';
        }

        if (! $line->projectId && ! $line->enquiryId && blank($line->jobNumber)) {
            $errors['project'][] = 'A budget line must belong to a project.';
        }

        if (blank($line->sourceRef)) {
            $errors['sourceRef'][] = 'A budget line needs its source key, otherwise re-projection would duplicate it.';
        }

        if ($errors) {
            throw CostValidationException::withErrors($errors);
        }
    }

    private function expenseCode(CostContext $context): ExpenseCode
    {
        $code = ExpenseCode::active()->where('code', $context->expenseCode)->first();

        if (! $code) {
            throw CostValidationException::withErrors([
                'expenseCode' => ["Expense code '{$context->expenseCode}' does not exist or is not active."],
            ]);
        }

        return $code;
    }

    private function existingFor(CostContext $context): ?CostLine
    {
        if (blank($context->sourceType) || ! $context->sourceId) {
            return null;
        }

        return CostLine::where('source_type', $context->sourceType)
            ->where('source_id', $context->sourceId)
            ->where('source_ref', $context->sourceRef)
            ->first();
    }

    /** @param array<string, mixed> $resolved */
    private function validate(CostContext $context, ExpenseCode $code, array $resolved): void
    {
        $errors = [];

        if (! is_numeric($context->amount) || bccomp((string) $context->amount, '0', 2) !== 1) {
            $errors['amount'][] = 'Amount must be greater than zero.';
        }

        if (! in_array($context->nature, [
            CostLine::NATURE_PLANNED, CostLine::NATURE_COMMITTED,
            CostLine::NATURE_ACCRUED, CostLine::NATURE_ACTUAL,
        ], true)) {
            $errors['nature'][] = "'{$context->nature}' is not a valid cost nature.";
        }

        $hasProject = filled($resolved['project_id'])
            || filled($resolved['project_enquiry_id'])
            || filled($resolved['job_number']);

        // Straight from the catalogue's Job ID Rule column — validation as data.
        if ($code->requiresJobId() && ! $hasProject) {
            $errors['jobNumber'][] = "'{$code->expense_type}' must be charged to a Job ID.";
        }

        if ($code->forbidsJobId() && $hasProject) {
            $errors['jobNumber'][] = "'{$code->expense_type}' cannot be charged to a Job ID — it is not a project cost.";
        }

        if ($code->requires_supplier && (! $context->payeeId || $context->payeeType !== 'SUPPLIER')) {
            $errors['payee_id'][] = "'{$code->expense_type}' requires a supplier selected from the supplier master.";
        }

        if ($context->payeeType === 'SUPPLIER'
            && (! $context->payeeId || ! Supplier::whereKey($context->payeeId)->exists())) {
            $errors['payee_id'][] = 'Select a valid supplier from the supplier master.';
        }

        if ($code->requires_asset_record && blank($context->details['asset_id'] ?? null)) {
            $errors['details.asset_id'][] = "'{$code->expense_type}' requires an asset record.";
        }

        $this->validateBudgetLine($context, $resolved, $errors);

        if (($resolved['project_enquiry_id'] ?? null)
            && ! $context->consumesLineId
            && blank($context->details['unbudgeted_reason'] ?? null)) {
            $errors['details.unbudgeted_reason'][] = 'Explain why this project cost is outside the approved budget.';
        }

        foreach ($code->requiredDetailKeys() as $key) {
            if (blank($context->details[$key] ?? null)) {
                $errors["details.{$key}"][] = $this->labelFor($code->detailFields(), $key) . ' is required.';
            }
        }

        $suppliedEvidence = collect($context->evidence)->pluck('key')->filter()->all();
        foreach ($code->requiredEvidenceKeys() as $key) {
            if (! in_array($key, $suppliedEvidence, true)) {
                $errors["evidence.{$key}"][] = $this->labelFor($code->evidenceRequirements(), $key) . ' must be attached.';
            }
        }

        $this->validatePeriod($resolved, $errors);

        if ($errors) {
            throw CostValidationException::withErrors($errors);
        }
    }

    /** @param array<string, mixed> $resolved @param array<string, array<int, string>> $errors */
    private function validateBudgetLine(CostContext $context, array $resolved, array &$errors): void
    {
        if (! $context->consumesLineId) {
            return;
        }

        $planned = CostLine::find($context->consumesLineId);
        if (! $planned || $planned->nature !== CostLine::NATURE_PLANNED
            || $planned->status !== CostLine::STATUS_VERIFIED) {
            $errors['consumes_line_id'][] = 'Select an active approved budget line.';
            return;
        }

        $sameProject = ($resolved['project_enquiry_id'] && $planned->project_enquiry_id === $resolved['project_enquiry_id'])
            || ($resolved['project_id'] && $planned->project_id === $resolved['project_id'])
            || (filled($resolved['job_number']) && $planned->job_number === $resolved['job_number']);

        if (! $sameProject) {
            $errors['consumes_line_id'][] = 'The selected budget line does not belong to this project.';
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function validatePeriod(array $resolved, array &$errors): void
    {
        if (! $resolved['accounting_period_id']) {
            $errors['incurredAt'][] = 'No accounting period covers that date.';

            return;
        }

        $period = AccountingPeriod::find($resolved['accounting_period_id']);

        if ($period && ! $period->isOpen()) {
            $errors['incurredAt'][] = sprintf(
                'The %s %d period is %s. Record this in the current open period instead.',
                $period->starts_on->format('F'),
                $period->year,
                $period->status,
            );
        }
    }

    /** @param array<int, array{key:string,label:string}> $definitions */
    private function labelFor(array $definitions, string $key): string
    {
        return collect($definitions)->firstWhere('key', $key)['label'] ?? $key;
    }

    /**
     * Gross in, net out. The person spending enters what the receipt says and
     * nothing else; Finance sets tax at verification, because recoverability
     * turns on eTIMS validity and the claim window. Project cost is the net.
     *
     * @return array<string, mixed>
     */
    private function money(CostContext $context): array
    {
        // Normalised before any bcmath call. A producer reading a legacy column
        // can hand us an empty string rather than null, and bcmath raises on a
        // malformed operand — the single writer must not be crashable by its
        // callers' data quality.
        $amount = bcadd($this->numeric($context->amount, '0'), '0', 2);
        $tax = bcadd($this->numeric($context->taxAmount, '0'), '0', 2);
        $net = bcsub($amount, $tax, 2);
        $rate = $this->numeric($context->fxRate, '1');

        return [
            'currency' => $context->currency,
            'fx_rate' => $rate,
            'amount' => $amount,
            'tax_amount' => $tax,
            'net_amount' => $net,
            'base_net_amount' => bcmul($net, $rate, 2),
        ];
    }

    /** Blank, null and non-numeric all collapse to the fallback. */
    private function numeric(mixed $value, string $fallback): string
    {
        return is_numeric($value) ? (string) $value : $fallback;
    }

    /**
     * What the budget looked like at the moment of the decision. Snapshotted
     * rather than derived later, because the figure the approver actually saw is
     * itself the audit record.
     *
     * @return array<string, mixed>
     */
    private function budgetSnapshot(CostContext $context, string $net): array
    {
        if (! $context->consumesLineId) {
            return ['budget_remaining_before' => null, 'budget_remaining_after' => null];
        }

        $planned = CostLine::find($context->consumesLineId);

        if (! $planned || $planned->nature !== CostLine::NATURE_PLANNED) {
            return ['budget_remaining_before' => null, 'budget_remaining_after' => null];
        }

        $drawn = (string) CostLine::where('consumes_line_id', $planned->id)
            ->counting()
            ->sum('net_amount');

        $before = bcsub((string) $planned->net_amount, $drawn ?: '0', 2);

        return [
            'budget_remaining_before' => $before,
            'budget_remaining_after' => bcsub($before, $net, 2),
        ];
    }

    /**
     * A cost whose source document was already approved elsewhere — an approved
     * GRN, a completed payroll run — should not queue for someone to approve what
     * procurement or HR already did. Everything else waits for a verifier.
     */
    private function initialStatus(CostContext $context): string
    {
        return $context->sourceApproved && filled($context->sourceType)
            ? CostLine::STATUS_VERIFIED
            : CostLine::STATUS_SUBMITTED;
    }
}
