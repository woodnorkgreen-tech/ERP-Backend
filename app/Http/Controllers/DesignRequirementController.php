<?php

namespace App\Http\Controllers;

use App\Models\DesignRequirement;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DesignRequirementController extends Controller
{
    /**
     * List all requirements for a task
     */
    public function index(EnquiryTask $task)
    {
        try {
            $requirements = DesignRequirement::where('enquiry_task_id', $task->id)
                ->with(['asset.uploader:id,name', 'asset.approver:id,name'])
                ->orderBy('id')
                ->get();
                
            return response()->json($requirements);
        } catch (\Exception $e) {
            Log::error('Error fetching design requirements', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error fetching requirements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update/sync requirements for a task
     */
    public function update(Request $request, EnquiryTask $task)
    {
        try {
            $request->validate([
                'requirements' => 'required|array',
                'requirements.*.client_key' => 'nullable|string|max:100',
                'requirements.*.title' => 'nullable|string|max:255',
                'requirements.*.category' => 'required|string',
                'requirements.*.description' => 'nullable|string',
                'requirements.*.status' => 'required|string|in:pending,fulfilled,approved,rejected',
                'requirements.*.asset_id' => 'nullable|integer|exists:design_assets,id',
                'deleted_ids' => 'sometimes|array',
                'deleted_ids.*' => 'integer',
            ]);

            $incomingReqs = $request->requirements;
            $deletedIds = collect($request->input('deleted_ids', []))
                ->filter()
                ->unique()
                ->values()
                ->all();

            DB::transaction(function () use ($incomingReqs, $deletedIds, $task) {
                if (!empty($deletedIds)) {
                    DesignRequirement::where('enquiry_task_id', $task->id)
                        ->whereIn('id', $deletedIds)
                        ->delete();
                }

                foreach ($incomingReqs as $reqData) {
                    if (isset($reqData['id']) && is_numeric($reqData['id'])) {
                        if (in_array((int) $reqData['id'], $deletedIds, true)) {
                            continue;
                        }

                        DesignRequirement::updateOrCreate(
                            ['id' => $reqData['id'], 'enquiry_task_id' => $task->id],
                            [
                                'client_key'  => $reqData['client_key'] ?? null,
                                'title'       => $reqData['title'] ?? null,
                                'category'    => $reqData['category'],
                                'description' => $reqData['description'] ?? null,
                                'status'      => $reqData['status'],
                                'asset_id'    => $reqData['asset_id'] ?? null,
                            ]
                        );
                        continue;
                    }

                    $clientKey = $reqData['client_key'] ?? $reqData['id'] ?? null;

                    if ($clientKey) {
                        DesignRequirement::updateOrCreate(
                            ['enquiry_task_id' => $task->id, 'client_key' => $clientKey],
                            [
                                'title'       => $reqData['title'] ?? null,
                                'category'    => $reqData['category'],
                                'description' => $reqData['description'] ?? null,
                                'status'      => $reqData['status'],
                                'asset_id'    => $reqData['asset_id'] ?? null,
                            ]
                        );
                        continue;
                    }

                    DesignRequirement::create([
                        'enquiry_task_id' => $task->id,
                        'title'           => $reqData['title'] ?? null,
                        'category'        => $reqData['category'],
                        'description'     => $reqData['description'] ?? null,
                        'status'          => $reqData['status'],
                        'asset_id'        => $reqData['asset_id'] ?? null,
                    ]);
                }
            });

            return response()->json(
                DesignRequirement::where('enquiry_task_id', $task->id)
                    ->with(['asset.uploader:id,name', 'asset.approver:id,name'])
                    ->orderBy('id')
                    ->get()
            );
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Invalid design requirements payload',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error synchronizing design requirements', [
                'task_id' => $task->id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error synchronizing requirements',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
