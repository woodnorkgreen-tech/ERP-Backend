<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClientService\Http\Requests\ClientRequest;
use App\Modules\ClientService\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Schema(
 *     schema="Client",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="full_name", type="string", example="John Smith"),
 *     @OA\Property(property="contact_person", type="string", nullable=true, example="Jane Smith"),
 *     @OA\Property(property="email", type="string", format="email", example="john.smith@company.com"),
 *     @OA\Property(property="phone", type="string", example="+254712345678"),
 *     @OA\Property(property="alt_contact", type="string", nullable=true, example="+254798765432"),
 *     @OA\Property(property="address", type="string", example="123 Main Street"),
 *     @OA\Property(property="city", type="string", example="Nairobi"),
 *     @OA\Property(property="county", type="string", example="Nairobi County"),
 *     @OA\Property(property="postal_address", type="string", nullable=true, example="P.O. Box 12345"),
 *     @OA\Property(property="customer_type", type="string", enum={"individual","company","organization"}, example="company"),
 *     @OA\Property(property="lead_source", type="string", example="Website"),
 *     @OA\Property(property="preferred_contact", type="string", enum={"email","phone","sms"}, example="email"),
 *     @OA\Property(property="industry", type="string", nullable=true, example="Technology"),
 *     @OA\Property(property="company_name", type="string", nullable=true, example="Tech Solutions Ltd"),
 *     @OA\Property(property="registration_date", type="string", format="date", example="2024-01-15"),
 *     @OA\Property(property="status", type="string", enum={"active","inactive"}, example="active"),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ClientController extends Controller
{
    /**
     * A company or organisation is known by its trading name, and the form that
     * creates one never asks for a personal name. Copying it into full_name
     * here keeps that single rule in one place — the browser used to apply it
     * too — and keeps the non-null column populated. The individual's own name
     * stays in contact_person.
     */
    private function withResolvedName(array $data): array
    {
        if (($data['customer_type'] ?? 'individual') === 'individual') {
            $data['company_name'] = null;

            return $data;
        }

        $data['full_name'] = $data['company_name'];

        return $data;
    }

    /**
     * @OA\Post(
     *     path="/api/clientservice/clients",
     *     summary="Create a new client",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"full_name","email","phone","address","city","county","customer_type","lead_source","preferred_contact","registration_date"},
     *             @OA\Property(property="full_name", type="string", example="John Smith"),
     *             @OA\Property(property="contact_person", type="string", nullable=true, example="Jane Smith"),
     *             @OA\Property(property="email", type="string", format="email", example="john.smith@company.com"),
     *             @OA\Property(property="phone", type="string", example="+254712345678"),
     *             @OA\Property(property="alt_contact", type="string", nullable=true, example="+254798765432"),
     *             @OA\Property(property="address", type="string", example="123 Main Street"),
     *             @OA\Property(property="city", type="string", example="Nairobi"),
     *             @OA\Property(property="county", type="string", example="Nairobi County"),
     *             @OA\Property(property="postal_address", type="string", nullable=true, example="P.O. Box 12345"),
     *             @OA\Property(property="customer_type", type="string", enum={"individual","company","organization"}, example="company"),
     *             @OA\Property(property="lead_source", type="string", example="Website"),
     *             @OA\Property(property="preferred_contact", type="string", enum={"email","phone","sms"}, example="email"),
     *             @OA\Property(property="industry", type="string", nullable=true, example="Technology"),
     *             @OA\Property(property="company_name", type="string", nullable=true, example="Tech Solutions Ltd"),
     *             @OA\Property(property="registration_date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="status", type="string", enum={"active","inactive"}, example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Client created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/Client")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function store(ClientRequest $request): JsonResponse
    {
        $client = Client::create($this->withResolvedName($request->validated()));

        return response()->json([
            'message' => 'Client created successfully',
            'data' => $client
        ], 201);
    }
    /**
     * @OA\Put(
     *     path="/api/clientservice/clients/{id}",
     *     summary="Update client details",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Client ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="full_name", type="string", example="Updated John Smith"),
     *             @OA\Property(property="contact_person", type="string", example="Jane Smith"),
     *             @OA\Property(property="email", type="string", format="email", example="john.smith@company.com"),
     *             @OA\Property(property="phone", type="string", example="+254712345678"),
     *             @OA\Property(property="alt_contact", type="string", example="+254798765432"),
     *             @OA\Property(property="address", type="string", example="456 Updated Street"),
     *             @OA\Property(property="city", type="string", example="Nairobi"),
     *             @OA\Property(property="county", type="string", example="Nairobi County"),
     *             @OA\Property(property="postal_address", type="string", example="P.O. Box 67890"),
     *             @OA\Property(property="customer_type", type="string", enum={"individual","company","organization"}),
     *             @OA\Property(property="lead_source", type="string", example="Referral"),
     *             @OA\Property(property="preferred_contact", type="string", enum={"email","phone","sms"}),
     *             @OA\Property(property="industry", type="string", example="Technology"),
     *             @OA\Property(property="company_name", type="string", example="Tech Solutions Ltd"),
     *             @OA\Property(property="registration_date", type="string", format="date"),
     *             @OA\Property(property="status", type="string", enum={"active","inactive"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/Client")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function update(ClientRequest $request, $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->update($this->withResolvedName($request->validated()));

        return response()->json([
            'message' => 'Client updated successfully',
            'data' => $client
        ]);
    }
    /**
     * @OA\Get(
      *     path="/api/clientservice/clients",
      *     summary="Get all clients or paginated clients",
      *     tags={"Clients"},
      *     security={{"bearerAuth":{}}},
      *     @OA\Parameter(
      *         name="paginate",
      *         in="query",
      *         description="Enable pagination (default: false)",
      *         @OA\Schema(type="boolean", default=false)
      *     ),
      *     @OA\Parameter(
      *         name="per_page",
      *         in="query",
      *         description="Items per page when paginated",
      *         @OA\Schema(type="integer", default=15)
      *     ),
      *     @OA\Response(
      *         response=200,
      *         description="Clients retrieved successfully",
      *         @OA\JsonContent(
      *             oneOf={
      *                 @OA\Schema(
      *                     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Client"))
      *                 ),
      *                 @OA\Schema(
      *                     @OA\Property(property="data", type="object",
      *                         @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Client")),
      *                         @OA\Property(property="current_page", type="integer"),
      *                         @OA\Property(property="last_page", type="integer"),
      *                         @OA\Property(property="per_page", type="integer"),
      *                         @OA\Property(property="total", type="integer")
      *                     )
      *                 )
      *             }
      *         )
      *     ),
      *     @OA\Response(response=401, description="Unauthorized")
      * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = Client::query()
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = $request->string('search');
                $builder->where(fn ($nested) => $nested
                    ->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->status))
            ->when($request->filled('company'), fn ($builder) => $builder->where('company_name', 'like', "%{$request->company}%"))
            ->orderBy('full_name');

        // The unpaginated branch feeds client dropdowns on the enquiry screens,
        // which need every option present to be selectable.
        if (!$request->boolean('paginate')) {
            return response()->json(['data' => $query->get()]);
        }

        return response()->json([
            'data' => $query->paginate((int) $request->input('per_page', 15)),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/clientservice/clients/{id}",
     *     summary="Get client details",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Client ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Client")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function show($id): JsonResponse
    {
        $client = Client::findOrFail($id);

        return response()->json([
            'data' => $client
        ]);
    }

    /**
     * @OA\Patch(
     *     path="/api/clientservice/clients/{id}/toggle-status",
     *     summary="Toggle client active status",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Client ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client status toggled successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/Client")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function toggleStatus($id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->is_active = !$client->is_active;
        $client->status = $client->is_active ? 'active' : 'inactive';
        $client->save();

        return response()->json([
            'message' => 'Client status toggled successfully',
            'data' => $client
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/clientservice/clients/{id}",
     *     summary="Delete a client",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Client ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Client not found"),
     *     @OA\Response(response=401, description="Unauthorized"),
     *     @OA\Response(response=403, description="Forbidden - Insufficient permissions")
     * )
     */
    public function destroy($id): JsonResponse
    {
        $client = Client::findOrFail($id);

        // Without this the delete reaches the database and fails on the
        // enquiries foreign key, surfacing as a 500 the user cannot act on.
        $enquiryCount = $client->enquiries()->count();
        if ($enquiryCount > 0) {
            return response()->json([
                'message' => "This client has {$enquiryCount} enquiry record(s) and cannot be deleted. Set them to inactive instead.",
            ], 409);
        }

        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully'
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/clientservice/clients/lead-sources",
     *     summary="Get unique lead sources",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Unique lead sources retrieved successfully"
     *     )
     * )
     */
    public function getLeadSources(): JsonResponse
    {
        $leadSources = Client::select('lead_source')
            ->distinct()
            ->whereNotNull('lead_source')
            ->where('lead_source', '!=', '')
            ->pluck('lead_source');

        return response()->json([
            'data' => $leadSources
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/clientservice/clients/export",
     *     summary="Export clients to Excel",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="lead_source",
     *         in="query",
     *         description="Filter by lead source",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Excel file download"
     *     )
     * )
     */
    public function export(Request $request)
    {
        $leadSource = $request->input('lead_source');
        $filename = 'clients_export_' . date('Y_m_d_His') . '.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Modules\ClientService\Exports\ClientsExport($leadSource),
            $filename
        );
    }
}
