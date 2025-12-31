<?php

namespace App\Http\Controllers;

use App\Models\WarrantyProductCoverage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WProductCoverageController extends Controller
{
    /**
     * List coverages for a product
     * GET ?product_id=1
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:w_products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = WarrantyProductCoverage::where(
            'warranty_product_id',
            $request->product_id
        )->get();

        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }

    /**
     * Store new coverage
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'warranty_product_id' => 'required|exists:w_products,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $coverage = WarrantyProductCoverage::create([
            'warranty_product_id' => $request->warranty_product_id,
            'title' => $request->title,
            'description' => $request->description,
            'status' => $request->status ?? 1,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Coverage added successfully',
            'data' => $coverage
        ], 201);
    }

    /**
     * Update coverage
     */
    public function update(Request $request, $id)
    {
        $coverage = WarrantyProductCoverage::find($id);

        if (!$coverage) {
            return response()->json([
                'status' => false,
                'message' => 'Coverage not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $coverage->update([
            'title' => $request->title,
            'description' => $request->description,
            'status' => $request->status ?? $coverage->status,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Coverage updated successfully',
            'data' => $coverage
        ]);
    }

    /**
     * Delete coverage
     */
    public function destroy($id)
    {
        $coverage = WarrantyProductCoverage::find($id);

        if (!$coverage) {
            return response()->json([
                'status' => false,
                'message' => 'Coverage not found'
            ], 404);
        }

        $coverage->delete();

        return response()->json([
            'status' => true,
            'message' => 'Coverage deleted successfully'
        ]);
    }
}
