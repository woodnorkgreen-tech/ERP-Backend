<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Models\PublicLead;
use App\Models\ProjectEnquiry;
use App\Modules\ClientService\Models\Client;
use App\Modules\HR\Models\Department;
use App\Constants\EnquiryConstants;
use App\Services\EnquiryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PublicLeadController extends Controller
{
    protected $enquiryService;

    public function __construct(EnquiryService $enquiryService)
    {
        $this->enquiryService = $enquiryService;
    }

    public function index(): JsonResponse
    {
        $leads = PublicLead::with('department')
            ->orderBy('created_at', 'desc')
            ->paginate(15);
            
        return response()->json($leads);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'service_interest' => 'required|string|max:255',
            'description' => 'nullable|string',
            'how_did_you_hear' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            \Log::warning('Public Lead Validation Failed:', [
                'errors' => $validator->errors()->toArray(),
                'input' => $request->all()
            ]);
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $lead = PublicLead::create([
                'full_name' => $request->full_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'company_name' => $request->company_name,
                'service_interest' => $request->service_interest,
                'description' => $request->description,
                'how_did_you_hear' => $request->how_did_you_hear,
                'source' => $request->source ?? 'Public Form',
                'status' => 'new',
            ]);

            return response()->json([
                'message' => 'Thank you! Your inquiry has been received. Our team will contact you shortly.',
                'data' => $lead
            ], 201);
            
        } catch (\Exception $e) {
            \Log::error('Public Lead Submission Error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'An error occurred while processing your request. Please try again later.'
            ], 500);
        }
    }

    public function convert(Request $request, PublicLead $lead): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $lead) {
                // 1. Create or Find Client
                $client = Client::where('email', $lead->email)->first();
                
                if (!$client) {
                    $client = Client::create([
                        'full_name' => $lead->full_name,
                        'company_name' => $lead->company_name,
                        'email' => $lead->email,
                        'phone' => $lead->phone,
                        'customer_type' => $lead->company_name ? 'Corporate' : 'Individual',
                        'lead_source' => $lead->how_did_you_hear ?? $lead->source,
                        'status' => 'Lead',
                        'is_active' => true,
                    ]);
                }

                // 2. Create Enquiry
                $enquiry = $this->enquiryService->createEnquiry([
                    'date_received' => now(),
                    'client_id' => $client->id,
                    'title' => 'Lead Conversion: ' . ($lead->company_name ?? $lead->full_name),
                    'description' => "Interested in: {$lead->service_interest}\n\nClient Notes: {$lead->description}",
                    'priority' => EnquiryConstants::PRIORITY_MEDIUM,
                    'status' => EnquiryConstants::STATUS_CLIENT_REGISTERED,
                    'department_id' => null, // Manual assignment required
                    'contact_person' => $lead->full_name,
                ]);

                // 3. Update Lead Status
                $lead->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'processed_by' => auth()->id(),
                    'converted_client_id' => $client->id,
                    'converted_enquiry_id' => $enquiry->id,
                ]);

                return response()->json([
                    'message' => 'Lead successfully converted to enquiry.',
                    'enquiry' => $enquiry
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Conversion failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(PublicLead $lead): JsonResponse
    {
        return response()->json($lead->load('department', 'processedBy'));
    }

    public function update(Request $request, PublicLead $lead): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:new,processed,archived,ignored',
        ]);

        $lead->update($request->only('status'));

        return response()->json([
            'message' => 'Lead updated successfully',
            'data' => $lead
        ]);
    }

    public function destroy(PublicLead $lead): JsonResponse
    {
        try {
            $lead->delete();
            return response()->json(['message' => 'Lead deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete lead: ' . $e->getMessage()], 500);
        }
    }

    public function getServices(): JsonResponse
    {
        $services = Department::select('id', 'name', 'description')->get();
        return response()->json($services);
    }
}
