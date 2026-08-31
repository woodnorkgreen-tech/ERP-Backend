<?php

namespace App\Modules\ProcurementStores\Controllers;

use App\Modules\ProcurementStores\Models\Supplier;
use App\Http\Resources\SupplierResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
                    ->orWhere('email', 'LIKE', '%' . $searchTerm . '%')
                    // Finance looks a vendor up by the name on the certificate or
                    // by PIN, neither of which is the trading name staff search by.
                    ->orWhere('legal_name', 'LIKE', '%' . $searchTerm . '%')
                    ->orWhere('kra_pin', 'LIKE', '%' . $searchTerm . '%');
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
            ...$this->taxRules(),
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
            ...$this->taxRules($supplier->id),
        ]);

        if ($validator->fails()) {
            return response(['error' => $validator->errors()], 422);
        }

        $supplier->update($input);

        return new SupplierResource($supplier);
    }

    /**
     * Tax identity is optional on capture and validated when supplied.
     *
     * Requiring a KRA PIN here would block a first-time vendor being saved from
     * the voucher screen, which is the flow the GL plan is trying to keep to two
     * taps. The format is still enforced when one is typed — a malformed PIN is
     * worse than a missing one, because it looks filed.
     *
     * @return array<string, mixed>
     */
    private function taxRules(?int $ignoreId = null): array
    {
        return [
            'legal_name' => 'sometimes|nullable|string|max:191',
            'kra_pin' => [
                'sometimes', 'nullable', 'string',
                'regex:' . Supplier::KRA_PIN_REGEX,
                Rule::unique('suppliers', 'kra_pin')->ignore($ignoreId),
            ],
            'vat_status' => 'sometimes|nullable|in:registered,not_registered,unknown',
            'etims_default' => 'sometimes|boolean',
            'residency' => 'sometimes|nullable|in:resident,non_resident',
            'default_vat_treatment_id' => 'sometimes|nullable|exists:vat_treatments,id',
            'wht_category_id' => 'sometimes|nullable|exists:wht_categories,id',
        ];
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