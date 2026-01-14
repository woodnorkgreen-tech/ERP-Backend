<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Supplier;
use App\Http\Resources\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $suppliers = Supplier::orderBy('created_at','desc')->paginate(20);

        return SupplierResource::collection($suppliers)->preserveQuery();
    }

    public function search(Request $request)
    {
        $searchTerm = $request->input('searchTerm');

        $supplier = Supplier::where(function ($query) use ($searchTerm) {
                $query->where('supplier_name', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('contact_person', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('email', 'LIKE', '%' . $searchTerm . '%');
            })
            ->orderBy('created_at', 'asc')
            ->paginate(20);

        return SupplierResource::collection($supplier)->preserveQuery();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $input = $request->all();
        $validator = Validator::make($input, [
            'supplier_name' => 'required',
            'contact_person' => 'required',
            'email' => 'required|email',
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        $input['user_id'] = auth()->id();

        $supplier = Supplier::create($input);
        
        return new SupplierResource($supplier);
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier)
    {
        return new SupplierResource($supplier);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Supplier $supplier)
    {
        $input = $request->all();
        $validator = Validator::make($input, [
            'email' => 'email',
        ]);
    
        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }
    
        $supplier->update($input);
    
        return new SupplierResource($supplier);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        
        return response(['message' => 'supplier was deleted successfully']);
    }
}