<?php

namespace App\Modules\Design\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Design\Models\DesignChangeRequest;
use App\Modules\Design\Models\DesignDocument;
use App\Modules\Design\Models\DesignItem;
use App\Modules\Design\Models\DesignRevision;
use App\Modules\Design\Resources\DesignChangeRequestResource;
use App\Modules\Design\Resources\DesignRevisionResource;
use App\Modules\Design\Resources\DesignUpdateResource;
use App\Modules\Printing\Services\ClientArtworkChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DesignHistoryController extends Controller
{
    public function __construct(private readonly ClientArtworkChangeService $printingChanges)
    {
    }

    public function storeUpdate(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $update = $item->updates()->create($data + ['created_by' => auth()->id()]);

        return response()->json(['message' => 'Work update added', 'data' => new DesignUpdateResource($update->load('creator:id,name'))], 201);
    }

    public function storeChangeRequest(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'request_text' => ['required', 'string', 'max:5000'],
            'requested_by_name' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'against_revision_id' => [
                'nullable', 'integer',
                Rule::exists('design_revisions', 'id')->where('design_item_id', $item->id),
            ],
        ]);

        $change = DB::transaction(function () use ($data, $item) {
            $change = $item->changeRequests()->create([
                ...$data,
                'received_at' => $data['received_at'] ?? now(),
                'status' => 'open',
                'recorded_by' => auth()->id(),
            ]);
            $item->update([
                'status' => 'client_changes_requested',
                'print_ready_at' => null,
                'production_ready_at' => null,
                'updated_by' => auth()->id(),
            ]);
            $this->printingChanges->notify($item, $data['request_text']);

            return $change;
        });

        return response()->json([
            'message' => 'Client changes recorded and Printing notified',
            'data' => new DesignChangeRequestResource($change->load(['againstRevision', 'addressedByRevision', 'recorder:id,name'])),
        ], 201);
    }

    public function storeRevision(Request $request, DesignItem $item): JsonResponse
    {
        $data = $request->validate([
            'change_summary' => ['required', 'string', 'max:5000'],
            'artwork_url' => ['nullable', 'url', 'max:2048'],
            'artwork_name' => ['nullable', 'string', 'max:255'],
            'addresses_change_request_ids' => ['nullable', 'array'],
            'addresses_change_request_ids.*' => [
                'integer', Rule::exists('design_change_requests', 'id')->where('design_item_id', $item->id),
            ],
        ]);

        $revision = DB::transaction(function () use ($data, $item) {
            $version = ((int) $item->revisions()->lockForUpdate()->max('version_number')) + 1;
            $revision = $item->revisions()->create([
                'version_number' => $version,
                'change_summary' => $data['change_summary'],
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            if (!empty($data['artwork_url'])) {
                $name = $data['artwork_name'] ?? "Artwork v{$version}";
                DesignDocument::create([
                    'design_job_id' => $item->design_job_id,
                    'design_item_id' => $item->id,
                    'design_revision_id' => $revision->id,
                    'document_type' => 'artwork',
                    'name' => $name,
                    'original_name' => $name,
                    'source' => 'link',
                    'external_url' => $data['artwork_url'],
                    'file_path' => $data['artwork_url'],
                    'file_size' => 0,
                    'mime_type' => 'text/uri-list',
                    'version' => $version,
                    'status' => 'active',
                    'uploaded_by' => auth()->id(),
                ]);
            }

            $ids = $data['addresses_change_request_ids'] ?? [];
            if ($ids) {
                DesignChangeRequest::query()->where('design_item_id', $item->id)->whereIn('id', $ids)->update([
                    'addressed_by_revision_id' => $revision->id,
                    'status' => 'addressed',
                ]);
            }
            $item->update(['status' => 'in_design', 'updated_by' => auth()->id()]);

            return $revision;
        });

        return response()->json([
            'message' => "Artwork version {$revision->version_number} created",
            'data' => new DesignRevisionResource($revision->load(['documents', 'creator:id,name', 'approver:id,name'])),
        ], 201);
    }

    public function approveRevision(Request $request, DesignRevision $revision): JsonResponse
    {
        $data = $request->validate([
            'approval_evidence' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($data, $revision) {
            DesignRevision::query()
                ->where('design_item_id', $revision->design_item_id)
                ->where('id', '!=', $revision->id)
                ->where('status', 'approved')
                ->update(['status' => 'superseded']);
            $revision->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approval_evidence' => $data['approval_evidence'],
                'approved_at' => now(),
            ]);
        });

        return response()->json([
            'message' => "Artwork version {$revision->version_number} approved",
            'data' => new DesignRevisionResource($revision->fresh(['documents', 'creator:id,name', 'approver:id,name'])),
        ]);
    }

}
