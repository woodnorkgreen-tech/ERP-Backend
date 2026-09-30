<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinanceAttachment;
use App\Modules\Finance\Models\ProjectInvoice;
use App\Modules\Finance\Services\FinanceAttachmentService;
use App\Models\ProjectEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceAttachmentController extends Controller
{
    public function index(ProjectEnquiry $enquiry, ProjectInvoice $invoice): JsonResponse
    {
        $this->assertInvoiceScope($enquiry, $invoice);

        return response()->json(['data' => $invoice->attachments()
            ->with('uploader:id,name')
            ->latest()
            ->get()
            ->map(fn (FinanceAttachment $attachment) => $this->present($attachment))]);
    }

    public function store(
        Request $request,
        ProjectEnquiry $enquiry,
        ProjectInvoice $invoice,
        FinanceAttachmentService $attachments,
    ): JsonResponse {
        $this->assertInvoiceScope($enquiry, $invoice);
        abort_unless($invoice->status === 'draft', 422, 'Evidence can only be added while the document is a draft.');
        abort_unless((int) $invoice->created_by === (int) $request->user()->id, 403, 'Only the preparer can add evidence to this draft.');

        $data = $request->validate([
            'evidence_type' => ['required', Rule::in(['no_quote_exception_evidence', 'credit_note_evidence'])],
            'file' => ['nullable', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png,image/webp', 'required_without:reference'],
            'reference' => ['nullable', 'string', 'max:255', 'required_without:file'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['evidence_type'] === 'no_quote_exception_evidence') {
            abort_unless(! $invoice->isCreditNote() && filled($invoice->no_quote_exception_reason), 422,
                'No-commercial-basis evidence can only be attached to an invoice using that controlled exception.');
        }
        if ($data['evidence_type'] === 'credit_note_evidence') {
            abort_unless($invoice->isCreditNote(), 422, 'Credit-note evidence can only be attached to a credit note.');
        }

        $attachment = $request->hasFile('file')
            ? $attachments->attachFile(ProjectInvoice::class, $invoice->id, $request->file('file'), (int) $request->user()->id, $data['evidence_type'], $data['description'] ?? null)
            : $attachments->attachReference(ProjectInvoice::class, $invoice->id, (int) $request->user()->id, $data['reference'] ?? null, $data['evidence_type'], $data['description'] ?? null);

        return response()->json(['message' => 'Evidence attached.', 'data' => $this->present($attachment->load('uploader:id,name'))], 201);
    }

    public function download(ProjectEnquiry $enquiry, ProjectInvoice $invoice, FinanceAttachment $attachment): BinaryFileResponse
    {
        $this->assertInvoiceScope($enquiry, $invoice);
        abort_unless($attachment->source_type === ProjectInvoice::class && (int) $attachment->source_id === (int) $invoice->id, 404);
        abort_unless(filled($attachment->file_path) && Storage::disk('local')->exists($attachment->file_path), 404);

        return response()->download(
            Storage::disk('local')->path($attachment->file_path),
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function assertInvoiceScope(ProjectEnquiry $enquiry, ProjectInvoice $invoice): void
    {
        abort_unless((int) $invoice->project_enquiry_id === (int) $enquiry->id, 404);
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
