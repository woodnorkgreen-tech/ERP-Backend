<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionItem;
use App\Modules\HR\Models\Employee;
use App\Modules\ProcurementStores\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Report 75R-A: who a requisition line is payable to.
 *
 * A receiver is exactly one of:
 *   employee  an Employee record (the line's own, or the requisition's payee);
 *   supplier  a Supplier record;
 *   other     a recipient who is in neither directory, identified by a reference
 *             stated on the requisition (an ID, registration or agreement number)
 *             together with their name. Verification and approval cover both, and
 *             the identity holds for that requisition only.
 *
 * A typed name on its own is never an identity: two lines that share a name but
 * not a record or a reference are two receivers, and one reference cannot carry
 * two different names. Nothing here stores bank or mobile-money credentials.
 */
final class RequisitionReceiverIdentity
{
    public const EMPLOYEE = 'employee';
    public const SUPPLIER = 'supplier';
    public const OTHER = 'other';

    /**
     * @return array{type: string, identity: string, key: string, id: int|null, name: string}
     *
     * @throws ValidationException when the line has no single, usable receiver
     */
    public function forLine(PettyCashRequisition $requisition, PettyCashRequisitionItem $line): array
    {
        $ownReceiver = $line->payee_id || $line->supplier_id || filled($line->other_recipient_reference);
        $employeeId = $line->payee_id ?: ($ownReceiver ? null : $requisition->payee_id);
        $stated = collect([$employeeId, $line->supplier_id, $line->other_recipient_reference])->filter(fn ($v) => filled($v));

        if ($stated->count() > 1) {
            throw ValidationException::withMessages([
                'items' => "\"{$line->description}\" names more than one receiver. Choose an employee, a supplier or another approved recipient.",
            ]);
        }
        if ($stated->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => "\"{$line->description}\" has no identified receiver. Choose an employee or supplier, or give the recipient's name and reference.",
            ]);
        }

        if ($employeeId) {
            $employee = Employee::query()->find($employeeId);
            if (! $employee) {
                throw ValidationException::withMessages(['items' => "The employee on \"{$line->description}\" no longer exists."]);
            }

            return $this->identity(self::EMPLOYEE, (string) $employee->id, $employee->id,
                trim($employee->first_name.' '.$employee->last_name));
        }

        if ($line->supplier_id) {
            $supplier = Supplier::query()->find($line->supplier_id);
            if (! $supplier) {
                throw ValidationException::withMessages(['items' => "The supplier on \"{$line->description}\" no longer exists."]);
            }

            return $this->identity(self::SUPPLIER, (string) $supplier->id, $supplier->id, (string) $supplier->supplier_name);
        }

        if (blank($line->payee_name)) {
            throw ValidationException::withMessages([
                'items' => "\"{$line->description}\" gives a recipient reference but no name.",
            ]);
        }

        return $this->identity(
            self::OTHER,
            $requisition->id.':'.mb_strtolower(trim((string) $line->other_recipient_reference)),
            null,
            trim((string) $line->payee_name),
        );
    }

    /**
     * Every line's receiver, keyed by line id.
     *
     * @return array<int, array{type: string, identity: string, key: string, id: int|null, name: string}>
     *
     * @throws ValidationException on the first line that cannot be identified
     */
    public function forRequisition(PettyCashRequisition $requisition, ?Collection $lines = null): array
    {
        $lines ??= $requisition->items()->orderBy('id')->get();
        $identities = [];
        $otherNames = [];
        foreach ($lines as $line) {
            $identity = $this->forLine($requisition, $line);
            if ($identity['type'] === self::OTHER) {
                $name = mb_strtolower($identity['name']);
                $otherNames[$identity['key']] ??= $name;
                if ($otherNames[$identity['key']] !== $name) {
                    throw ValidationException::withMessages([
                        'items' => "Recipient reference \"{$line->other_recipient_reference}\" is used for two different names. One reference identifies one recipient.",
                    ]);
                }
            }
            $identities[$line->id] = $identity;
        }

        return $identities;
    }

    /**
     * The same rules, applied to a requisition as it is being submitted, so a
     * receiver that cannot be paid is corrected by its creator and never reaches
     * the verifier. Errors are keyed to the line that caused them.
     *
     * A requisition that names nobody but one typed payee is left alone: it is
     * the long-standing single-payment form and is still paid as one payment.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function validateSubmittedLines(array $items, mixed $parentEmployeeId, ?string $parentName = null): void
    {
        $errors = [];
        $otherNames = [];
        // Receiver allocation applies as soon as the lines name a supplier or a
        // referenced recipient, or simply name more than one person: several
        // receivers cannot be told apart, or paid separately, by typed names.
        $distinct = collect($items)->map(fn ($item) => match (true) {
            filled($item['payee_id'] ?? null) => 'employee:'.$item['payee_id'],
            filled($item['supplier_id'] ?? null) => 'supplier:'.$item['supplier_id'],
            filled($item['other_recipient_reference'] ?? null) => 'other:'.mb_strtolower(trim((string) $item['other_recipient_reference'])),
            filled($item['payee_name'] ?? null) => 'name:'.mb_strtolower(trim((string) $item['payee_name'])),
            filled($parentEmployeeId) => 'employee:'.$parentEmployeeId,
            default => 'name:'.mb_strtolower(trim((string) $parentName)),
        })->unique();
        $allocated = $distinct->count() > 1 || collect($items)->contains(fn ($item) => filled($item['supplier_id'] ?? null)
            || filled($item['other_recipient_reference'] ?? null));

        foreach (array_values($items) as $index => $item) {
            $own = array_filter([
                $item['payee_id'] ?? null, $item['supplier_id'] ?? null, $item['other_recipient_reference'] ?? null,
            ], fn ($value) => filled($value));

            if (count($own) > 1) {
                $errors["items.{$index}.payee_name"][] = 'Choose one receiver for this line: an employee, a supplier or another approved recipient.';

                continue;
            }
            if (filled($item['other_recipient_reference'] ?? null)) {
                $name = mb_strtolower(trim((string) ($item['payee_name'] ?? '')));
                if ($name === '') {
                    $errors["items.{$index}.payee_name"][] = "Give the recipient's name as well as their reference.";

                    continue;
                }
                $reference = mb_strtolower(trim((string) $item['other_recipient_reference']));
                $otherNames[$reference] ??= $name;
                if ($otherNames[$reference] !== $name) {
                    $errors["items.{$index}.other_recipient_reference"][] = 'This reference is already used for a different name on this requisition.';
                }
            }
            if ($allocated && $own === [] && (blank($parentEmployeeId) || filled($item['payee_name'] ?? null))) {
                $errors["items.{$index}.payee_name"][] = filled($item['payee_name'] ?? null)
                    ? 'A name alone does not identify this recipient. Choose an employee or supplier, or add their ID or registration number.'
                    : 'Identify who this line is payable to: an employee, a supplier, or a recipient with a reference.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A supplier line carries the supplier's registered name, whatever was typed:
     * the record is the identity, and vouchers and reports must not show another.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function withSupplierNames(array $items): array
    {
        $ids = collect($items)->pluck('supplier_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return $items;
        }
        $names = Supplier::query()->whereIn('id', $ids)->pluck('supplier_name', 'id');

        return array_map(function ($item) use ($names) {
            if (is_array($item) && filled($item['supplier_id'] ?? null) && isset($names[$item['supplier_id']])) {
                $item['payee_name'] = $names[$item['supplier_id']];
            }

            return $item;
        }, $items);
    }

    /** Whether any line states a receiver in the way receiver allocation needs. */
    public function usesAllocatedReceivers(Collection $lines): bool
    {
        return $lines->contains(fn ($line) => $line->supplier_id || filled($line->other_recipient_reference));
    }

    /**
     * Whether the requisition is one receiver's money, payable as the single
     * whole-amount payment that predates receiver allocation. Typed names count
     * here only to tell one payee from several; they are never treated as an
     * identity anywhere money is allocated.
     */
    public function isSinglePayment(PettyCashRequisition $requisition): bool
    {
        $lines = $requisition->relationLoaded('items') ? $requisition->items : $requisition->items()->get();
        if ($this->usesAllocatedReceivers($lines)) {
            return false;
        }

        return $lines->map(fn ($line) => match (true) {
            (bool) $line->payee_id => 'employee:'.$line->payee_id,
            (bool) $requisition->payee_id => 'employee:'.$requisition->payee_id,
            default => 'name:'.mb_strtolower(trim((string) ($line->payee_name ?: $requisition->payee_name))),
        })->unique()->count() <= 1;
    }

    /** The approved amount for one receiver: the sum of that receiver's lines. */
    public function approvedFor(Collection $lines, array $identities, string $receiverKey): string
    {
        return $lines->filter(fn ($line) => ($identities[$line->id]['key'] ?? null) === $receiverKey)
            ->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->amount, 2), '0.00');
    }

    private function identity(string $type, string $identity, ?int $id, string $name): array
    {
        return ['type' => $type, 'identity' => $identity, 'key' => $type.':'.$identity, 'id' => $id, 'name' => $name];
    }
}
