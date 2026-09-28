<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Constants\Permissions;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Services\FinanceAttachmentService;
use App\Modules\ProcurementStores\Models\Bill;
use App\Modules\ProcurementStores\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * W2-2 (confirmed 2026-09-23, Option A): supplier supporting documents.
 *
 * Reuses the generic `finance_attachments` mechanism the Wave 1 invoice
 * evidence path built — no `po_attachments`/`bill_attachments` table, per
 * the directive's own instruction against a second, duplicate file engine.
 *
 * The evidence-type list below is deliberately open-ended rather than one
 * mandatory document per transaction: WNG has not confirmed an exact
 * evidence matrix (which category is mandatory for which transaction type),
 * so nothing here makes any of them required — this only makes all of them
 * attachable, safely.
 */
class ProcurementAttachmentController extends Controller
{
    private const EVIDENCE_TYPES = [
        'supplier_invoice', 'quotation', 'receipt', 'delivery_note',
        'grn_evidence', 'tax_evidence', 'service_evidence', 'other',
    ];

    public function poIndex(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return response()->json(['data' => $purchaseOrder->attachments()
            ->with('uploader:id,name')->latest()->get()
            ->map(fn (FinanceAttachment $attachment) => $this->present($attachment))]);
    }

    public function poStore(Request $request, PurchaseOrder $purchaseOrder, FinanceAttachmentService $attachments): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::PROCUREMENT_ORDERS_CREATE), 403, 'You do not have permission to attach evidence to purchase orders.');
        $data = $this->validated($request);

        $attachment = $request->hasFile('file')
            ? $attachments->attachFile(PurchaseOrder::class, $purchaseOrder->id, $request->file('file'), (int) $request->user()->id, $data['evidence_type'], $data['description'] ?? null)
            : $attachments->attachReference(PurchaseOrder::class, $purchaseOrder->id, (int) $request->user()->id, $data['reference'] ?? null, $data['evidence_type'], $data['description'] ?? null);

        return response()->json(['message' => 'Evidence attached.', 'data' => $this->present($attachment->load('uploader:id,name'))], 201);
    }

    public function poDownload(PurchaseOrder $purchaseOrder, FinanceAttachment $attachment): BinaryFileResponse
    {
        return $this->download($purchaseOrder, $attachment, PurchaseOrder::class);
    }

    public function billIndex(Bill $bill): JsonResponse
    {
        return response()->json(['data' => $bill->attachments()
            ->with('uploader:id,name')->latest()->get()
            ->map(fn (FinanceAttachment $attachment) => $this->present($attachment))]);
    }

    public function billStore(Request $request, Bill $bill, FinanceAttachmentService $attachments): JsonResponse
    {
        abort_unless($request->user()?->can(Permissions::PROCUREMENT_ORDERS_CREATE), 403, 'You do not have permission to attach evidence to bills.');
        $data = $this->validated($request);

        $attachment = $request->hasFile('file')
            ? $attachments->attachFile(Bill::class, $bill->id, $request->file('file'), (int) $request->user()->id, $data['evidence_type'], $data['description'] ?? null)
            : $attachments->attachReference(Bill::class, $bill->id, (int) $request->user()->id, $data['reference'] ?? null, $data['evidence_type'], $data['description'] ?? null);

        return response()->json(['message' => 'Evidence attached.', 'data' => $this->present($attachment->load('uploader:id,name'))], 201);
    }

    public function billDownload(Bill $bill, FinanceAttachment $attachment): BinaryFileResponse
    {
        return $this->download($bill, $attachment, Bill::class);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'evidence_type' => ['required', Rule::in(self::EVIDENCE_TYPES)],
            'file' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'required_without:reference'],
            'reference' => ['nullable', 'string', 'max:255', 'required_without:file'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function download(PurchaseOrder|Bill $source, FinanceAttachment $attachment, string $sourceType): BinaryFileResponse
    {
        abort_unless($attachment->source_type === $sourceType && (int) $attachment->source_id === (int) $source->id, 404);
        abort_unless(filled($attachment->file_path) && Storage::disk('local')->exists($attachment->file_path), 404);

        return response()->download(
            Storage::disk('local')->path($attachment->file_path),
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function present(FinanceAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'evidence_type' => $attachment->evidence_type,
            'reference' => $attachment->reference,
            'description' => $attachment->description,
            'original_filename' => $attachment->original_filename,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'uploaded_by' => $attachment->uploaded_by,
            'uploader' => $attachment->uploader,
            'created_at' => $attachment->created_at,
            'downloadable' => filled($attachment->file_path),
        ];
    }
}
