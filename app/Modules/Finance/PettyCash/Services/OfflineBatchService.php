<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Models\User;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashOfflineBatch;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisition;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionItem;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class OfflineBatchService
{
    // Prefixing with a letter prevents Excel from coercing 1.0 into numeric 1
    // when a workbook is saved and reopened.
    public const VERSION = 'v1.0';
    private const SHEETS = [
        'TopUps' => ['offline_reference', 'date_received', 'amount', 'payment_method', 'transaction_code', 'description'],
        'Requisitions' => ['offline_reference', 'requester_email', 'department_id', 'type_code', 'purpose', 'payee_name', 'payee_phone', 'project_name', 'venue', 'custom_fields_json', 'items_json'],
        'Payouts' => ['offline_reference', 'requisition_reference', 'date_paid', 'receiver', 'amount', 'transaction_cost', 'expense_code', 'payment_source_code', 'transaction_code', 'receipt_type', 'receipt_number', 'tax_amount', 'description', 'direct_payment_reason'],
    ];

    public function stage(UploadedFile $file, int $userId): PettyCashOfflineBatch
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if (PettyCashOfflineBatch::where('file_sha256', $hash)->exists()) {
            throw ValidationException::withMessages(['file' => 'This exact workbook has already been uploaded. Open the existing batch instead.']);
        }

        $book = IOFactory::load($file->getRealPath());
        $settings = $book->getSheetByName('Instructions');
        if (! $settings || trim((string) $settings->getCell('B2')->getValue()) !== self::VERSION) {
            throw ValidationException::withMessages(['file' => 'Unsupported or missing workbook version. Download a fresh template.']);
        }

        return DB::transaction(function () use ($book, $file, $hash, $userId) {
            $batch = PettyCashOfflineBatch::create([
                'batch_reference' => (string) Str::uuid(), 'workbook_version' => self::VERSION,
                'original_filename' => $file->getClientOriginalName(), 'file_sha256' => $hash,
                'status' => 'uploaded', 'uploaded_by' => $userId,
            ]);
            $references = [];
            $totals = ['top_ups' => 0, 'requisitions' => 0, 'payouts' => 0, 'top_up_amount' => 0, 'payout_amount' => 0];
            $errorCount = 0;

            foreach (self::SHEETS as $sheetName => $headers) {
                $sheet = $book->getSheetByName($sheetName);
                if (! $sheet) throw ValidationException::withMessages(['file' => "Required sheet {$sheetName} is missing."]);
                $actual = array_map(fn ($v) => trim((string) $v), array_slice($sheet->rangeToArray('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)).'1')[0], 0, count($headers)));
                if ($actual !== $headers) throw ValidationException::withMessages(['file' => "{$sheetName} headings were changed. Download a fresh template and paste values into it."]);

                for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                    $payload = [];
                    $hasValue = false;
                    foreach ($headers as $index => $header) {
                        $cell = $sheet->getCell([$index + 1, $number]);
                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            $payload[$header] = null; $formulaError = "Formulas are not allowed ({$header}). Paste the calculated value.";
                        } else {
                            $value = $cell->getValue();
                            if (in_array($header, ['date_received', 'date_paid'], true) && is_numeric($value)) $value = ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
                            $payload[$header] = is_string($value) ? trim($value) : $value;
                            $hasValue = $hasValue || ($value !== null && $value !== '');
                        }
                    }
                    if (! $hasValue) continue;
                    $type = match ($sheetName) { 'TopUps' => 'top_up', 'Requisitions' => 'requisition', default => 'payout' };
                    $errors = isset($formulaError) ? [$formulaError] : $this->validate($type, $payload, $references, $userId);
                    unset($formulaError);
                    $reference = strtoupper((string) ($payload['offline_reference'] ?? ''));
                    if ($reference !== '') {
                        $key = $type.':'.$reference;
                        if (isset($references[$key])) $errors[] = "Duplicate {$type} offline_reference; first used on row {$references[$key]}.";
                        $references[$key] = $number;
                    }
                    $batch->rows()->create(['record_type' => $type, 'row_number' => $number, 'offline_reference' => $reference ?: "ROW-{$number}", 'payload' => $payload, 'errors' => $errors ?: null, 'status' => $errors ? 'invalid' : 'validated']);
                    $errorCount += $errors ? 1 : 0;
                    $totals[$type === 'top_up' ? 'top_ups' : ($type === 'requisition' ? 'requisitions' : 'payouts')]++;
                    if ($type === 'top_up') $totals['top_up_amount'] += (float) ($payload['amount'] ?? 0);
                    if ($type === 'payout') $totals['payout_amount'] += (float) ($payload['amount'] ?? 0) + (float) ($payload['transaction_cost'] ?? 0);
                }
            }

            // Resolve cross-sheet references only after every sheet has been read.
            foreach ($batch->rows()->where('record_type', 'payout')->get() as $row) {
                $ref = strtoupper((string) ($row->payload['requisition_reference'] ?? ''));
                if ($ref && ! isset($references['requisition:'.$ref])) {
                    $errors = $row->errors ?? []; $errors[] = "Requisition reference {$ref} is not present in this workbook.";
                    $row->update(['errors' => $errors, 'status' => 'invalid']); $errorCount++;
                } elseif ($ref) {
                    $requestRow = $batch->rows()->where('record_type', 'requisition')->where('offline_reference', $ref)->first();
                    $requestTotal = collect($this->jsonValue($requestRow?->payload['items_json'] ?? null, []))->sum('amount');
                    if (bccomp((string) ($row->payload['amount'] ?? 0), (string) $requestTotal, 2) !== 0) {
                        $errors = $row->errors ?? []; $errors[] = 'Payout amount must equal the linked requisition item total (KES '.number_format((float) $requestTotal, 2).').';
                        $row->update(['errors' => $errors, 'status' => 'invalid']); $errorCount++;
                    }
                }
            }
            $batch->update(['status' => $errorCount ? 'invalid' : 'ready', 'totals' => $totals, 'validation_summary' => ['invalid_rows' => $batch->rows()->where('status', 'invalid')->count(), 'valid_rows' => $batch->rows()->where('status', 'validated')->count()]]);
            return $batch->load(['rows', 'uploader:id,name,email']);
        });
    }

    private function validate(string $type, array $p, array $references, int $uploaderId): array
    {
        $e = [];
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{2,99}$/i', (string) ($p['offline_reference'] ?? ''))) $e[] = 'offline_reference must be 3-100 letters, numbers, dashes or underscores.';
        if ($type === 'top_up') {
            if (! $this->date($p['date_received'] ?? null)) $e[] = 'date_received must be YYYY-MM-DD.';
            if ((float) ($p['amount'] ?? 0) <= 0) $e[] = 'amount must be greater than zero.';
            if (! in_array(strtolower((string) ($p['payment_method'] ?? '')), ['cash', 'bank_transfer', 'mpesa', 'cheque', 'other'], true)) $e[] = 'payment_method is not supported.';
        } elseif ($type === 'requisition') {
            $requester = User::where('email', $p['requester_email'] ?? '')->first();
            if (! $requester) $e[] = 'requester_email must match an active system user.';
            if (! DB::table('departments')->where('id', $p['department_id'] ?? 0)->exists()) $e[] = 'department_id is not valid.';
            if (! PettyCashRequisitionType::where('code', $p['type_code'] ?? '')->where('is_active', true)->exists()) $e[] = 'type_code is not an active requisition type.';
            if (blank($p['purpose'] ?? null)) $e[] = 'purpose is required.';
            $items = $this->json($p['items_json'] ?? null, 'items_json', $e);
            if (! is_array($items) || $items === [] || collect($items)->contains(fn ($i) => ! is_array($i) || blank($i['description'] ?? null) || (float) ($i['amount'] ?? 0) <= 0)) $e[] = 'items_json must be a non-empty JSON array; each item needs description and positive amount.';
            $custom = $this->json(blank($p['custom_fields_json'] ?? null) ? '{}' : $p['custom_fields_json'], 'custom_fields_json', $e);
            $typeModel = PettyCashRequisitionType::where('code', $p['type_code'] ?? '')->where('is_active', true)->first();
            if ($typeModel && is_array($items) && is_array($custom)) {
                try {
                    app(RequisitionSchemaService::class)->validate($typeModel, ['custom_fields' => $custom, 'items' => $items, 'project_name' => $p['project_name'] ?? null, 'payee_name' => $p['payee_name'] ?? null]);
                } catch (ValidationException $exception) {
                    $e = array_merge($e, collect($exception->errors())->flatten()->all());
                }
            }
        } else {
            if (! $this->date($p['date_paid'] ?? null)) $e[] = 'date_paid must be YYYY-MM-DD.';
            if ((float) ($p['amount'] ?? 0) <= 0) $e[] = 'amount must be greater than zero.';
            if ((float) ($p['transaction_cost'] ?? 0) < 0 || (float) ($p['tax_amount'] ?? 0) < 0) $e[] = 'transaction_cost and tax_amount cannot be negative.';
            if (! ExpenseCode::active()->where('code', $p['expense_code'] ?? '')->exists()) $e[] = 'expense_code is not active.';
            if (! PaymentSource::where('code', $p['payment_source_code'] ?? '')->where('type', 'petty_cash')->where('is_active', true)->exists()) $e[] = 'payment_source_code must identify an active petty-cash source.';
            if (blank($p['requisition_reference'] ?? null) && blank($p['direct_payment_reason'] ?? null)) $e[] = 'A payout needs requisition_reference or direct_payment_reason.';
            if (blank($p['receiver'] ?? null) && blank($p['requisition_reference'] ?? null)) $e[] = 'receiver is required for a direct payout.';
            if (! in_array(strtolower((string) ($p['receipt_type'] ?? 'none')), ['etr', 'invoice', 'receipt', 'none'], true)) $e[] = 'receipt_type must be etr, invoice, receipt or none.';
        }
        return array_values(array_unique($e));
    }

    public function approveAndPost(PettyCashOfflineBatch $batch, int $approverId, PettyCashService $cash): PettyCashOfflineBatch
    {
        return DB::transaction(function () use ($batch, $approverId, $cash) {
            $batch = PettyCashOfflineBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'ready') throw ValidationException::withMessages(['batch' => 'Only a fully valid, unposted batch can be approved.']);
            if ($batch->uploaded_by === $approverId) throw ValidationException::withMessages(['batch' => 'The uploader cannot approve and post the same offline batch.']);
            $batch->update(['status' => 'posting', 'approved_by' => $approverId, 'approved_at' => now()]);
            $requisitions = [];

            foreach ($batch->rows()->where('record_type', 'top_up')->orderBy('row_number')->get() as $row) {
                $p = $row->payload;
                $model = $cash->createTopUp(['amount' => $p['amount'], 'date_topped_up' => $p['date_received'], 'payment_method' => strtolower($p['payment_method']), 'transaction_code' => $p['transaction_code'] ?: null, 'description' => trim(($p['description'] ?? '')." [Offline batch {$batch->batch_reference}; ref {$row->offline_reference}]")]);
                $row->update(['status' => 'posted', 'posted_type' => 'top_up', 'posted_id' => $model->id]);
            }
            foreach ($batch->rows()->where('record_type', 'requisition')->orderBy('row_number')->get() as $row) {
                $p = $row->payload; $user = User::where('email', $p['requester_email'])->firstOrFail();
                if ($user->id === $approverId) throw ValidationException::withMessages(['batch' => "Approver is also requester for requisition {$row->offline_reference}."]);
                $type = PettyCashRequisitionType::where('code', $p['type_code'])->firstOrFail();
                $items = json_decode($p['items_json'], true, 512, JSON_THROW_ON_ERROR);
                $typed = app(RequisitionSchemaService::class)->validate($type, ['custom_fields' => $this->jsonValue($p['custom_fields_json'] ?? null, []), 'items' => $items, 'project_name' => $p['project_name'] ?: null, 'payee_name' => $p['payee_name'] ?: null]);
                $req = PettyCashRequisition::create(['requisition_number' => PettyCashRequisition::generateRequisitionNumber(), 'user_id' => $user->id, 'department_id' => $p['department_id'], 'category' => $type->name, 'requisition_type_id' => $type->id, 'purpose' => $p['purpose'], 'custom_fields' => $typed['custom_fields'], 'type_snapshot' => $type->definition(), 'total_amount' => collect($items)->sum('amount'), 'status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now(), 'payee_name' => $p['payee_name'] ?: null, 'payee_phone' => $p['payee_phone'] ?: null, 'project_name' => $p['project_name'] ?: null, 'venue' => $p['venue'] ?: null]);
                foreach ($items as $index => $item) PettyCashRequisitionItem::create(['requisition_id' => $req->id, 'description' => $item['description'], 'remarks' => $item['remarks'] ?? null, 'details' => $typed['item_details'][$index] ?? [], 'amount' => $item['amount'], 'payee_name' => $item['payee_name'] ?? null, 'payee_phone' => $item['payee_phone'] ?? null]);
                $requisitions[$row->offline_reference] = $req;
                $row->update(['status' => 'posted', 'posted_type' => 'requisition', 'posted_id' => $req->id]);
            }
            foreach ($batch->rows()->where('record_type', 'payout')->orderBy('row_number')->get() as $row) {
                $p = $row->payload; $reqRef = strtoupper((string) ($p['requisition_reference'] ?? '')); $req = $reqRef ? ($requisitions[$reqRef] ?? null) : null;
                $expense = ExpenseCode::active()->where('code', $p['expense_code'])->firstOrFail();
                $source = PaymentSource::where('code', $p['payment_source_code'])->where('type', 'petty_cash')->where('is_active', true)->firstOrFail();
                $result = $cash->createDisbursement(['receiver' => $p['receiver'] ?: ($req?->payee_name ?: 'Approved payee'), 'amount' => $p['amount'], 'transaction_cost' => $p['transaction_cost'] ?: 0, 'description' => $p['description'] ?: ($req?->purpose ?: $p['direct_payment_reason']), 'expense_code_id' => $expense->id, 'payment_source_id' => $source->id, 'transaction_code' => $p['transaction_code'] ?: null, 'receipt_type' => strtolower($p['receipt_type'] ?: 'none'), 'receipt_number' => $p['receipt_number'] ?: null, 'tax_amount' => $p['tax_amount'] ?: 0, 'date_disbursed' => $p['date_paid'], 'requisition_id' => $req?->id, 'direct_payment_reason' => $req ? null : $p['direct_payment_reason'], 'idempotency_key' => hash('sha256', $batch->batch_reference.':'.$row->offline_reference)]);
                if (! ($result['success'] ?? false)) throw ValidationException::withMessages(['batch' => ["Payout {$row->offline_reference} failed", $result['errors'] ?? []]]);
                $row->update(['status' => 'posted', 'posted_type' => 'disbursement', 'posted_id' => $result['data']->id]);
            }
            $batch->update(['status' => 'posted', 'posted_by' => $approverId, 'posted_at' => now()]);
            return $batch->load(['rows', 'uploader:id,name,email', 'approver:id,name,email']);
        });
    }

    private function date($value): bool { return is_string($value) && \DateTime::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value; }
    private function json($value, string $field, array &$errors): mixed { try { return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) { $errors[] = "{$field} must contain valid JSON."; return null; } }
    private function jsonValue($value, $default): mixed { if (blank($value)) return $default; return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR); }
}
