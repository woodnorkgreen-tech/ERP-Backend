<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Models\DisciplinaryCase;
use App\Modules\HR\Services\DisciplineService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DisciplineController extends Controller
{
    protected $disciplineService;

    public function __construct(DisciplineService $disciplineService)
    {
        $this->disciplineService = $disciplineService;
    }

    /**
     * Get all disciplinary cases with filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cases = $this->disciplineService->getDisciplinaryCases($request);

            return response()->json([
                'success' => true,
                'data' => $cases->items(),
                'meta' => [
                    'current_page' => $cases->currentPage(),
                    'last_page' => $cases->lastPage(),
                    'per_page' => $cases->perPage(),
                    'total' => $cases->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch disciplinary cases: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get discipline statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = $this->disciplineService->getDisciplineStatistics();

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new disciplinary case
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'employee_id' => 'required|exists:users,id',
                'allegations' => 'required|string',
                'offense_category' => 'required|in:minor,gross_misconduct',
                'witnesses' => 'nullable|string',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|max:2048|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->createDisciplinaryCase($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Disciplinary case reported successfully',
                'data' => $case,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create disciplinary case: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single disciplinary case
     */
    public function show(int $id): JsonResponse
    {
        try {
            $case = $this->disciplineService->getDisciplinaryCaseById($id);
            $user = auth()->user();

            // Check permissions - HR and Super Admin can view all cases.
            // The accused employee may also view their own disciplinary case.
            if (!$user->hasAnyRole(['Super Admin', 'HR']) && $case->employee_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this case',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch disciplinary case: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue show cause letter
     */
    public function issueShowCause(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check permission - only HR and Super Admin can issue show cause letters
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to issue show cause letters',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'letter' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->issueShowCause($case, $request->letter);

            return response()->json([
                'success' => true,
                'message' => 'Show cause letter issued successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue show cause letter: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit show cause response
     */
    public function submitShowCauseResponse(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check if user is the accused employee
            if ($case->employee_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only submit responses for your own cases',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'response' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->submitShowCauseResponse($case, $request->response);

            return response()->json([
                'success' => true,
                'message' => 'Show cause response submitted successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit response: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Schedule hearing
     */
    public function scheduleHearing(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to schedule hearings',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'date' => 'required|date|after:now',
                'panel' => 'required|array|min:3|max:5',
                'panel.*' => 'exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->scheduleHearing($case, $request->date, $request->panel);

            return response()->json([
                'success' => true,
                'message' => 'Hearing scheduled successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule hearing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit hearing minutes
     */
    public function submitHearingMinutes(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to submit hearing minutes',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'minutes' => 'required|string',
                'decision' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->submitHearingMinutes($case, $request->minutes, $request->decision);

            return response()->json([
                'success' => true,
                'message' => 'Hearing minutes submitted successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit hearing minutes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue warning
     */
    public function issueWarning(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to issue warnings',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:verbal,first_written,second_written,final,termination',
                'letter' => 'nullable|string',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if ($request->type === 'verbal' && $request->hasFile('attachments')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Verbal warnings cannot include attachments',
                ], 422);
            }

            $case = $this->disciplineService->issueWarning($case, $request->type, $request->letter);

            if ($request->hasFile('attachments') && $request->type !== 'verbal') {
                $uploadedAttachments = [];
                $storagePath = "disciplinary-cases/{$id}";

                foreach ($request->file('attachments') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $storedFilename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($storagePath, $storedFilename);
                    $uploadedAttachments[] = [
                        'id' => uniqid('att_', true),
                        'path' => $path,
                        'original_name' => $originalName,
                    ];
                }

                $currentAttachments = $case->attachments ?? [];
                $case->update([
                    'attachments' => array_merge($currentAttachments, $uploadedAttachments),
                ]);
                $case->refresh();
            }

            return response()->json([
                'success' => true,
                'message' => 'Warning issued successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to issue warning: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit appeal
     */
    public function submitAppeal(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check if user is the accused employee
            if ($case->employee_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only submit appeals for your own cases',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'details' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->submitAppeal($case, $request->details);

            return response()->json([
                'success' => true,
                'message' => 'Appeal submitted successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit appeal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Finalize case
     */
    public function finalizeCase(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $user = auth()->user();

            // Check permission
            if (!$user->hasAnyRole(['Super Admin', 'HR'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to finalize cases',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'decision' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $case = $this->disciplineService->finalizeCase($case, $request->decision);

            return response()->json([
                'success' => true,
                'message' => 'Case finalized successfully',
                'data' => $case,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to finalize case: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add comment to disciplinary case
     */
    public function addComment(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'comment' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $comment = $this->disciplineService->addComment($case, $request->comment);

            return response()->json([
                'success' => true,
                'message' => 'Comment added successfully',
                'data' => $comment,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add comment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload attachments for a disciplinary case
     */
    public function uploadAttachments(Request $request, int $id): JsonResponse
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'attachments' => 'required|array|max:10',
                'attachments.*' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $uploadedAttachments = [];
            $storagePath = "disciplinary-cases/{$id}";

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $storedFilename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                    $path = $file->storeAs($storagePath, $storedFilename);
                    $uploadedAttachments[] = [
                        'id' => uniqid('att_', true),
                        'path' => $path,
                        'original_name' => $originalName,
                    ];
                }
            }

            // Update case attachments
            $currentAttachments = $case->attachments ?? [];
            $case->update([
                'attachments' => array_merge($currentAttachments, $uploadedAttachments)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Attachments uploaded successfully',
                'data' => $uploadedPaths,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Disciplinary case not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload attachments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * View attachment inline.
     */
    public function viewAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $attachmentPaths = $case->attachments ?? [];

            $attachment = collect($attachmentPaths)->first(function ($item) use ($filename) {
                if (is_array($item)) {
                    return ($item['id'] ?? null) === $filename || ($item['original_name'] ?? null) === $filename;
                }

                return str_ends_with($item, $filename);
            });

            if (is_array($attachment)) {
                $path = $attachment['path'] ?? null;
                $downloadName = $attachment['original_name'] ?? $filename;
            } else {
                $path = $attachment;
                $downloadName = $filename;
            }

            if (!$path) {
                $path = "disciplinary-cases/{$id}/{$filename}";
            }

            if (!Storage::exists($path)) {
                $fallbackPath = "private/{$path}";
                if (!Storage::exists($fallbackPath)) {
                    abort(404, 'File not found on disk');
                }
                $path = $fallbackPath;
            }

            $content = Storage::get($path);
            $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';
            $size = Storage::size($path);

            return response($content, 200, [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Content-Disposition' => 'inline; filename="' . $downloadName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Exception $e) {
            abort(500, 'Failed to view file: ' . $e->getMessage());
        }
    }

    /**
     * Download attachment.
     */
    public function downloadAttachment(int $id, string $filename): \Illuminate\Http\Response
    {
        try {
            $case = DisciplinaryCase::findOrFail($id);
            $attachmentPaths = $case->attachments ?? [];

            $attachment = collect($attachmentPaths)->first(function ($item) use ($filename) {
                if (is_array($item)) {
                    return ($item['id'] ?? null) === $filename || ($item['original_name'] ?? null) === $filename;
                }

                return str_ends_with($item, $filename);
            });

            if (is_array($attachment)) {
                $path = $attachment['path'] ?? null;
                $downloadName = $attachment['original_name'] ?? $filename;
            } else {
                $path = $attachment;
                $downloadName = $filename;
            }

            if (!$path) {
                $path = "disciplinary-cases/{$id}/{$filename}";
            }

            if (!Storage::exists($path)) {
                $fallbackPath = "private/{$path}";
                if (!Storage::exists($fallbackPath)) {
                    abort(404, 'File not found on disk');
                }
                $path = $fallbackPath;
            }

            $content = Storage::get($path);
            $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';
            $size = Storage::size($path);

            return response($content, 200, [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        } catch (\Exception $e) {
            abort(500, 'Failed to download file: ' . $e->getMessage());
        }
    }
}
