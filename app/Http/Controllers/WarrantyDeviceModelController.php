<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Company;
use App\Models\DeviceModel;
use App\Models\WarrantyProduct;
use App\Models\UploadFile;
use App\Models\CompanyProduct;
use App\Models\Companies;
use App\Models\WDevice;
use App\Models\Wclaim;
use App\Models\ZohoInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use App\Models\Variant;


class WarrantyDeviceModelController extends Controller
{
    public function storeDeviceModel(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'brand_id'    => 'required|exists:brands,id',
            'category_id' => 'required|exists:category,id',
            'name'        => 'required|string|max:255',
            'storage'     => 'nullable|string|max:100',
            'price'       => 'required|numeric|min:0'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }
    
        $model = DeviceModel::create($validator->validated());
    
        return response()->json([
            'message' => 'Device model added successfully',
            'data'    => $model
        ], 201);
    }
    public function updateDeviceModel(Request $request, $id)
    {
    $model = DeviceModel::find($id);

    if (!$model) {
        return response()->json([
            'message' => 'Device model not found'
        ], 404);
    }

    $model->update($request->only([
        'name',
        'storage',
        'price',
        'status'
    ]));

    return response()->json([
        'message' => 'Device model updated successfully',
        'data'    => $model
    ]);
}

    public function searchDeviceModels(Request $request)
    {
        $query = DeviceModel::with(['brand', 'category'])
            ->where('status', 1);
    
        if ($request->brand_id) {
            $query->where('brand_id', $request->brand_id);
        }
    
        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }
    
        if ($request->search) {
            $query->where('name', 'LIKE', "%{$request->search}%");
        }
    
        if ($request->min_price) {
            $query->where('price', '>=', $request->min_price);
        }
    
        if ($request->max_price) {
            $query->where('price', '<=', $request->max_price);
        }
    
        return response()->json([
            'data' => $query->orderBy('name')->paginate(20)
        ]);
    }
    
    public function getVariants(Request $request)
    {
        $query = Variant::query();

        // Search by name
        if ($request->filled('search')) {
            $query->where('name', 'LIKE', '%' . $request->search . '%');
        }

        $variants = $query
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Variants fetched successfully',
            'data' => $variants
        ], 200);
    }
}