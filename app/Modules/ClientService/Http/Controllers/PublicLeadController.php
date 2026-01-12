<?php

namespace App\Modules\ClientService\Http\Controllers;

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

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:20',
            'company_name' => 'nullable|string|max:255',
            'service_interest' => 'required|integer|exists:departments,id',
            'description' => 'nullable|string',
            'source' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            return DB::transaction(function () use ($request) {
                // 1. Find or create client
                $client = Client::where('email', $request->email)
                    ->orWhere('phone', $request->phone)
                    ->first();

                if (!$client) {
                    $client = Client::create([
                        'full_name' => $request->full_name,
                        'company_name' => $request->company_name,
                        'contact_person' => $request->full_name,
                        'email' => $request->email,
                        'phone' => $request->phone,
                        'customer_type' => $request->company_name ? 'Corporate' : 'Individual',
                        'lead_source' => $request->source ?? 'Public Form',
                        'status' => 'Lead',
                        'is_active' => true,
                    ]);
                }

                // 2. Create Project Enquiry using the service to trigger workflows
                $enquiry = $this->enquiryService->createEnquiry([
                    'date_received' => now(),
                    'client_id' => $client->id,
                    'title' => 'New Service Interest: ' . ($request->company_name ?? $request->full_name),
                    'description' => $request->description,
                    'priority' => EnquiryConstants::PRIORITY_MEDIUM,
                    'status' => EnquiryConstants::STATUS_CLIENT_REGISTERED,
                    'department_id' => $request->service_interest,
                    'contact_person' => $request->full_name,
                ]);

                return response()->json([
                    'message' => 'Thank you! Your inquiry has been received. Our team will contact you shortly.',
                    'data' => $enquiry
                ], 201);
            });
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

    public function getServices(): JsonResponse
    {
        $services = Department::select('id', 'name', 'description')->get();
        return response()->json($services);
    }
}
