<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\CostCollector\Models\ExpenseCode;
use App\Modules\Finance\Models\PaymentSource;
use App\Modules\Finance\PettyCash\Models\PettyCashOfflineBatch;
use App\Modules\Finance\PettyCash\Models\PettyCashRequisitionType;
use App\Modules\Finance\PettyCash\Services\OfflineBatchService;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PettyCashOfflineBatchController extends Controller
{
    public function __construct(private OfflineBatchService $batches, private PettyCashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        $query = PettyCashOfflineBatch::with(['uploader:id,name,email', 'approver:id,name,email'])->withCount(['rows', 'rows as invalid_rows_count' => fn ($q) => $q->where('status', 'invalid')])->latest();
        if (! $request->user()->can(Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH)) $query->where('uploaded_by', $request->user()->id);
        return response()->json(['success' => true, 'data' => $query->paginate(min((int) $request->input('per_page', 20), 50))]);
    }

    public function show(Request $request, PettyCashOfflineBatch $batch): JsonResponse
    {
        if ($batch->uploaded_by !== $request->user()->id && ! $request->user()->can(Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH)) abort(403);
        return response()->json(['success' => true, 'data' => $batch->load(['rows' => fn ($q) => $q->orderByRaw("FIELD(record_type, 'top_up','requisition','payout')")->orderBy('row_number'), 'uploader:id,name,email', 'approver:id,name,email'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        $batch = $this->batches->stage($request->file('file'), $request->user()->id);
        return response()->json(['success' => true, 'message' => $batch->status === 'ready' ? 'Workbook validated and is ready for independent approval.' : 'Workbook staged. Correct the reported rows and upload a new workbook.', 'data' => $batch], 201);
    }

    public function approve(Request $request, PettyCashOfflineBatch $batch): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH), 403);
        $posted = $this->batches->approveAndPost($batch, $request->user()->id, $this->cash);
        return response()->json(['success' => true, 'message' => 'The complete offline batch was approved and posted atomically.', 'data' => $posted]);
    }

    public function reject(Request $request, PettyCashOfflineBatch $batch): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::FINANCE_PETTY_CASH_APPROVE_OFFLINE_BATCH), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        if (! in_array($batch->status, ['ready', 'invalid'], true)) return response()->json(['message' => 'Only an unposted batch may be rejected.'], 422);
        $batch->update(['status' => 'rejected', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'rejection_reason' => $data['reason']]);
        return response()->json(['success' => true, 'message' => 'Offline batch rejected.', 'data' => $batch]);
    }

    public function template()
    {
        $book = new Spreadsheet();
        $instructions = $book->getActiveSheet(); $instructions->setTitle('Instructions');
        $instructions->fromArray([
            ['PETTY CASH OFFLINE JOURNAL', 'Do not rename sheets or headings. Paste values only; formulas are rejected.'],
            ['Workbook version', OfflineBatchService::VERSION],
            ['Safe workflow', 'Upload stages data only. A different authorized user must approve before any cash moves.'],
            ['Offline references', 'Create unique references such as TRIP-20260823-T01. Use the same requisition reference in Payouts.'],
            ['JSON example', '[{"description":"Fuel","amount":2500,"remarks":"Site visit"}]'],
        ]);
        $instructions->getColumnDimension('A')->setWidth(24); $instructions->getColumnDimension('B')->setWidth(100); $instructions->getStyle('A1:B1')->getFont()->setBold(true);
        $sheets = [
            'TopUps' => ['offline_reference', 'date_received', 'amount', 'payment_method', 'transaction_code', 'description'],
            'Requisitions' => ['offline_reference', 'requester_email', 'department_id', 'type_code', 'purpose', 'payee_name', 'payee_phone', 'project_name', 'venue', 'custom_fields_json', 'items_json'],
            'Payouts' => ['offline_reference', 'requisition_reference', 'date_paid', 'receiver', 'amount', 'transaction_cost', 'expense_code', 'payment_source_code', 'transaction_code', 'receipt_type', 'receipt_number', 'tax_amount', 'description', 'direct_payment_reason'],
        ];
        foreach ($sheets as $name => $headers) {
            $sheet = $book->createSheet()->setTitle($name); $sheet->fromArray($headers); $sheet->freezePane('A2'); $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
            $sheet->getStyle('1:1')->getFont()->setBold(true); foreach (range(1, count($headers)) as $column) $sheet->getColumnDimensionByColumn($column)->setWidth(22);
        }
        $refs = $book->createSheet()->setTitle('ReferenceData');
        $refs->fromArray(['REQUISITION TYPES', 'DESCRIPTION', 'EXPENSE CODES', 'EXPENSE TYPE', 'PETTY CASH SOURCES', 'SOURCE NAME', 'DEPARTMENT ID', 'DEPARTMENT']);
        $types = PettyCashRequisitionType::where('is_active', true)->orderBy('sort_order')->get(['code', 'name'])->values();
        $expenses = ExpenseCode::active()->orderBy('code')->get(['code', 'expense_type'])->values();
        $sources = PaymentSource::where('type', 'petty_cash')->where('is_active', true)->orderBy('code')->get(['code', 'name'])->values();
        $departments = \DB::table('departments')->orderBy('name')->get(['id', 'name'])->values();
        for ($i = 0, $max = max($types->count(), $expenses->count(), $sources->count(), $departments->count()); $i < $max; $i++) $refs->fromArray([[$types[$i]->code ?? null, $types[$i]->name ?? null, $expenses[$i]->code ?? null, $expenses[$i]->expense_type ?? null, $sources[$i]->code ?? null, $sources[$i]->name ?? null, $departments[$i]->id ?? null, $departments[$i]->name ?? null]], null, 'A'.($i + 2));
        $refs->freezePane('A2'); $refs->getStyle('1:1')->getFont()->setBold(true);

        $path = tempnam(sys_get_temp_dir(), 'petty-cash-offline-').'.xlsx'; (new Xlsx($book))->save($path); $book->disconnectWorksheets();
        return response()->download($path, 'petty-cash-offline-journal-'.OfflineBatchService::VERSION.'.xlsx')->deleteFileAfterSend(true);
    }
}
