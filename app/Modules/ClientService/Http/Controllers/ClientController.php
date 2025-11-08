<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Modules\ClientService\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

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
class ClientController
{
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
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'phone' => 'required|string|max:20',
            'alt_contact' => 'nullable|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'county' => 'required|string|max:255',
            'postal_address' => 'nullable|string|max:255',
            'customer_type' => 'required|in:individual,company,organization',
            'lead_source' => 'required|string|max:255',
            'preferred_contact' => 'required|in:email,phone,sms',
            'industry' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'registration_date' => 'required|date',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $client = Client::create($request->all());

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
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'required|email|unique:clients,email,' . $id,
            'phone' => 'required|string|max:20',
            'alt_contact' => 'nullable|string|max:20',
            'address' => 'required|string',
            'city' => 'required|string|max:255',
            'county' => 'required|string|max:255',
            'postal_address' => 'nullable|string|max:255',
            'customer_type' => 'required|in:individual,company,organization',
            'lead_source' => 'required|string|max:255',
            'preferred_contact' => 'required|in:email,phone,sms',
            'industry' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'registration_date' => 'required|date',
            'status' => 'sometimes|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $client = Client::findOrFail($id);
        $client->update($request->all());

        return response()->json([
            'message' => 'Client updated successfully',
            'data' => $client
        ]);
    }
    /**
     * @OA\Get(
     *     path="/api/clientservice/clients",
     *     summary="Get all clients with pagination",
     *     tags={"Clients"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Clients retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Client")),
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="last_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function index(): JsonResponse
    {
        $clients = Client::paginate(15);

        return response()->json([
            'data' => $clients
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
}
