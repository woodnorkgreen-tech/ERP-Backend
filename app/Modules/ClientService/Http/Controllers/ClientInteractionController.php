<?php

namespace App\Modules\ClientService\Http\Controllers;

use App\Modules\ClientService\Models\Client;
use App\Modules\ClientService\Models\ClientInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class ClientInteractionController extends Controller
{
    /**
     * Paginated interaction timeline for a client (newest first).
     */
    public function index(Request $request, $clientId): JsonResponse
    {
        Client::findOrFail($clientId);

        $query = ClientInteraction::with('user:id,name')
            ->where('client_id', $clientId);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $interactions = $query->orderByDesc('interaction_at')->paginate(20);

        return response()->json($interactions);
    }

    /**
     * Log a new interaction on a client's timeline.
     */
    public function store(Request $request, $clientId): JsonResponse
    {
        Client::findOrFail($clientId);

        $validated = $request->validate([
            'type' => ['required', Rule::in(ClientInteraction::TYPES)],
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string',
            'enquiry_id' => 'nullable|integer|exists:project_enquiries,id',
            'interaction_at' => 'nullable|date',
        ]);

        $interaction = ClientInteraction::create([
            'client_id' => $clientId,
            'enquiry_id' => $validated['enquiry_id'] ?? null,
            'user_id' => auth()->id(),
            'type' => $validated['type'],
            'subject' => $validated['subject'] ?? null,
            'body' => $validated['body'] ?? null,
            'interaction_at' => $validated['interaction_at'] ?? now(),
        ]);

        return response()->json([
            'message' => 'Interaction logged successfully',
            'data' => $interaction->load('user:id,name'),
        ], 201);
    }

    /**
     * Remove an interaction from a client's timeline.
     */
    public function destroy($clientId, $id): JsonResponse
    {
        $interaction = ClientInteraction::where('client_id', $clientId)->findOrFail($id);
        $interaction->delete();

        return response()->json(['message' => 'Interaction deleted successfully']);
    }
}
