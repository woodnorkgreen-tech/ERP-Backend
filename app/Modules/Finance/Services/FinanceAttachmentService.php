<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FinanceAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The one place any Finance document attaches evidence, regardless of which
 * document it is. See `finance_attachments`' migration for why this is a
 * single generic table rather than one per document type.
 */
class FinanceAttachmentService
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Record a reference-only piece of evidence (no file) — e.g. a receipt
     * number kept outside the ERP, or a note explaining why none exists.
     */
    public function attachReference(
        string $sourceType,
        int $sourceId,
        int $uploadedBy,
        ?string $reference = null,
        ?string $evidenceType = null,
        ?string $description = null,
    ): FinanceAttachment {
        if (blank($reference) && blank($description)) {
            throw ValidationException::withMessages([
                'reference' => 'Provide an evidence reference or description.',
            ]);
        }

        return FinanceAttachment::create([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'evidence_type' => $evidenceType,
            'reference' => $reference,
            'description' => $description,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    /** Record an uploaded file as evidence. */
    public function attachFile(
        string $sourceType,
        int $sourceId,
        UploadedFile $file,
        int $uploadedBy,
        ?string $evidenceType = null,
        ?string $description = null,
    ): FinanceAttachment {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => 'Finance evidence must be a PDF, JPEG, PNG or WebP file.',
            ]);
        }
        if (($file->getSize() ?: 0) > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Finance evidence must be 10 MB or smaller.',
            ]);
        }

        // The local/private disk is intentionally used. Laravel generates the
        // stored filename, so an uploaded executable name is never exposed or
        // served from public/storage.
        $path = $file->store('finance/attachments/'.sha1($sourceType), 'local');

        try {
            return FinanceAttachment::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'evidence_type' => $evidenceType,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $mime,
                'file_size' => $file->getSize(),
                'description' => $description,
                'uploaded_by' => $uploadedBy,
            ]);
        } catch (Throwable $failure) {
            Storage::disk('local')->delete($path);
            throw $failure;
        }
    }

    /** Every attachment recorded against one document. */
    public function forSource(string $sourceType, int $sourceId): Collection
    {
        return FinanceAttachment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByDesc('created_at')
            ->get();
    }
}
