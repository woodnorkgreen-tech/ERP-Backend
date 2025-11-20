<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Models\ProjectEnquiry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller;
use App\Services\EnquiryService;
use App\Handlers\CreateEnquiryHandler;
use App\Handlers\GetEnquiriesHandler;
use App\Commands\CreateEnquiryCommand;
use App\Queries\GetEnquiriesQuery;

class EnquiryController extends Controller
{
    protected $enquiryService;
    protected $createEnquiryHandler;
    protected $getEnquiriesHandler;

    public function __construct(
        EnquiryService $enquiryService,
        CreateEnquiryHandler $createEnquiryHandler,
        GetEnquiriesHandler $getEnquiriesHandler
    ) {
        $this->enquiryService = $enquiryService;
        $this->createEnquiryHandler = $createEnquiryHandler;
        $this->getEnquiriesHandler = $getEnquiriesHandler;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            \Log::info('EnquiryController index called', [
                'user_id' => Auth::id(),
                'search' => $request->search,
                'status' => $request->status,
                'client_id' => $request->client_id,
                'department_id' => $request->department_id
            ]);

            $query = new GetEnquiriesQuery(
                Auth::id(),
                $request->search,
                $request->status,
                $request->client_id,
                $request->department_id
            );

            \Log::info('GetEnquiriesQuery created');

            $enquiries = $this->getEnquiriesHandler->handle($query)->orderBy('created_at', 'desc')->paginate(15);

            \Log::info('Enquiries fetched', ['count' => $enquiries->count()]);

            return response()->json([
                'data' => $enquiries,
                'message' => 'Enquiries retrieved successfully'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in EnquiryController index', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        // Handle field name alias for enquiry_title
        if ($request->has('enquiry_title') && !$request->has('title')) {
            $request->merge(['title' => $request->enquiry_title]);
        }

        $validator = Validator::make($request->all(), [
            'date_received' => 'required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:date_received',
            'client_id' => 'required|integer|exists:clients,id',
            'title' => 'required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255', // Allow enquiry_title as alias
            'description' => 'nullable|string',
            'project_scope' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'contact_person' => 'required|string|max:255',
            'status' => 'required|string|in:client_registered,enquiry_logged,site_survey_completed,design_completed,design_approved,materials_specified,budget_created,quote_prepared,quote_approved,planning,in_progress,completed,cancelled',
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_department' => 'nullable|string|max:255',
            'project_deliverables' => 'nullable|string',
            'assigned_po' => 'nullable|integer|exists:users,id',
            'follow_up_notes' => 'nullable|string',
            'venue' => 'nullable|string|max:255',
            'site_survey_skipped' => 'nullable|boolean',
            'site_survey_skip_reason' => 'nullable|string|required_if:site_survey_skipped,true',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $command = new CreateEnquiryCommand($request->all());
        $enquiry = $this->createEnquiryHandler->handle($command);

        return response()->json([
            'message' => 'Enquiry created successfully',
            'data' => $enquiry->load('client', 'department', 'enquiryTasks'),
        ], 201);
    }

    public function show(ProjectEnquiry $enquiry): JsonResponse
    {
        return response()->json([
            'data' => $enquiry->load('client', 'department', 'enquiryTasks', 'project'),
            'message' => 'Enquiry retrieved successfully'
        ]);
    }

    public function update(Request $request, ProjectEnquiry $enquiry): JsonResponse
    {
        // Handle field name alias for enquiry_title
        if ($request->has('enquiry_title') && !$request->has('title')) {
            $request->merge(['title' => $request->enquiry_title]);
        }

        $validator = Validator::make($request->all(), [
            'date_received' => 'sometimes|required|date',
            'expected_delivery_date' => 'nullable|date|after_or_equal:date_received',
            'client_id' => 'sometimes|required|integer|exists:clients,id',
            'title' => 'sometimes|required|string|max:255',
            'enquiry_title' => 'nullable|string|max:255', // Allow enquiry_title as alias
            'description' => 'sometimes|nullable|string',
            'project_scope' => 'nullable|string',
            'priority' => 'nullable|string|in:low,medium,high,urgent',
            'contact_person' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|required|string|in:client_registered,enquiry_logged,site_survey_completed,design_completed,design_approved,materials_specified,budget_created,quote_prepared,quote_approved,planning,in_progress,completed,cancelled',
            'department_id' => 'nullable|integer|exists:departments,id',
            'assigned_department' => 'nullable|string|max:255',
            'project_deliverables' => 'nullable|string',
            'assigned_po' => 'nullable|integer|exists:users,id',
            'follow_up_notes' => 'nullable|string',
            'venue' => 'nullable|string|max:255',
            'site_survey_skipped' => 'boolean',
            'site_survey_skip_reason' => 'nullable|string|required_if:site_survey_skipped,true',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updatedEnquiry = $this->enquiryService->updateEnquiry($enquiry, $request->all());

        return response()->json([
            'message' => 'Enquiry updated successfully',
            'data' => $updatedEnquiry->load('client', 'department')
        ]);
    }

    public function destroy(ProjectEnquiry $enquiry): JsonResponse
    {
        $enquiry->delete();

        return response()->json([
            'message' => 'Enquiry deleted successfully'
        ]);
    }
}
