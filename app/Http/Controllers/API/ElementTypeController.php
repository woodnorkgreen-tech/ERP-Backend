<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ElementType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ElementTypeController extends Controller
{
    /**
     * Display a listing of element types.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $elementTypes = ElementType::orderBy('order')
                ->orderBy('display_name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $elementTypes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch element types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created element type.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:element_types,name',
                'category' => 'required|string|max:255',
            ]);

            // Generate display name from name if not provided
            $displayName = $request->input('display_name') ?? Str::title(str_replace('-', ' ', $validated['name']));

            $elementType = ElementType::create([
                'name' => Str::slug($validated['name']),
                'display_name' => $displayName,
                'category' => $validated['category'],
                'is_predefined' => false, // Custom types are never predefined
                'order' => $request->input('order', 100), // Default order for custom types
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Element type created successfully',
                'data' => $elementType
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create element type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified element type.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $elementType = ElementType::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $elementType
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Element type not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch element type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified element type.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $elementType = ElementType::findOrFail($id);

            // Prevent updating predefined types
            if ($elementType->is_predefined) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update predefined element types'
                ], 403);
            }

            $validated = $request->validate([
                'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('element_types')->ignore($id)],
                'display_name' => 'sometimes|required|string|max:255',
                'category' => 'sometimes|required|string|max:255',
                'order' => 'sometimes|integer',
            ]);

            $elementType->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Element type updated successfully',
                'data' => $elementType
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Element type not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update element type',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified element type.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $elementType = ElementType::findOrFail($id);

            // Prevent deleting predefined types
            if ($elementType->is_predefined) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete predefined element types'
                ], 403);
            }

            // Check if the element type is being used
            if ($elementType->isInUse()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete element type that is currently being used by project elements'
                ], 409);
            }

            $elementType->delete();

            return response()->json([
                'success' => true,
                'message' => 'Element type deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Element type not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete element type',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
