<?php

namespace App\Http\Controllers;

use App\Models\DesignRequirement;
use App\Models\DesignAsset;
use App\Modules\Projects\Models\EnquiryTask;
use Illuminate\Http\Request;

class DesignRequirementController extends Controller
{
    /**
     * List all requirements for a task
     */
    public function index(EnquiryTask $task)
    {
        try {
            $requirements = DesignRequirement::where('enquiry_task_id', $task->id)
                ->with('asset')
                ->get();
                
            return response()->json($requirements);
        } catch (\Exception $e) {
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
                'requirements.*.category' => 'required|string',
                'requirements.*.description' => 'nullable|string',
                'requirements.*.status' => 'required|string|in:pending,fulfilled,approved,rejected',
                'requirements.*.asset_id' => 'nullable|integer|exists:design_assets,id',
            ]);

            $incomingReqs = $request->requirements;
            $existingReqIds = [];

            foreach ($incomingReqs as $reqData) {
                // If we have an ID, update that specific record
                if (isset($reqData['id']) && is_numeric($reqData['id'])) {
                    $req = DesignRequirement::updateOrCreate(
                        ['id' => $reqData['id'], 'enquiry_task_id' => $task->id],
                        [
                            'category' => $reqData['category'],
                            'description' => $reqData['description'] ?? null,
                            'status' => $reqData['status'],
                            'asset_id' => $reqData['asset_id'] ?? null,
                        ]
                    );
                } else {
                    // Fallback to searching by metadata for new/unsaved items
                    $req = DesignRequirement::create([
                        'enquiry_task_id' => $task->id,
                        'category' => $reqData['category'],
                        'description' => $reqData['description'] ?? null,
                        'status' => $reqData['status'],
                        'asset_id' => $reqData['asset_id'] ?? null,
                    ]);
                }
                $existingReqIds[] = $req->id;
            }

            // Optional: Remove requirements that were not in the sync list
            DesignRequirement::where('enquiry_task_id', $task->id)
                ->whereNotIn('id', $existingReqIds)
                ->delete();

            return response()->json([
                'message' => 'Requirements synchronized successfully',
                'data' => DesignRequirement::where('enquiry_task_id', $task->id)->get()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error synchronizing requirements',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
