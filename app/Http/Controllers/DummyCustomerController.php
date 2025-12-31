<?php

namespace App\Http\Controllers;

use App\Models\DummyCustomer;
use Illuminate\Http\Request;

class DummyCustomerController extends Controller
{
    // Store customer record
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'           => 'nullable|string|max:255',
            'address'        => 'nullable|string',
            'mobile'         => 'nullable|string|max:20',
            'imei1'          => 'nullable|string|max:50',
            'imei2'          => 'nullable|string|max:50',
            'brand'          => 'nullable|string|max:50',
            'model'          => 'nullable|string|max:255',
            'fcm_token'      => 'nullable|string',
            'is_mapped'      => 'nullable|boolean',
            'last_sync'      => 'nullable|date',
            'last_report'    => 'nullable|date',
            'lat'            => 'nullable|numeric',
            'lng'            => 'nullable|numeric',
            'retailer_id'    => 'nullable|integer',
            'retailer_code'  => 'nullable|string|max:255',
        ]);

        $customer = DummyCustomer::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => $customer
        ], 201);
    }

    // Get customers by searchValue
    public function index(Request $request)
    {
        $search = $request->get('searchValue');

        $customers = DummyCustomer::when($search, function ($query, $search) {
            $query->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('mobile', 'LIKE', "%{$search}%")
                  ->orWhere('imei1', 'LIKE', "%{$search}%")
                  ->orWhere('imei2', 'LIKE', "%{$search}%")
                  ->orWhere('address', 'LIKE', "%{$search}%");
        })
        ->orderBy('id', 'desc')
        ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $customers
        ]);
    }
    
    public function update(Request $request)
    {
    // Find customer by imei1
    $customer = DummyCustomer::where('imei1', $request->imei1)->first();

    if (!$customer) {
        return response()->json([
            'success' => false,
            'message' => 'Customer not found'
        ], 404);
    }

    // Validate request fields but do NOT require them
    $data = $request->validate([
        'name'           => 'nullable|string|max:255',
        'address'        => 'nullable|string',
        'mobile'         => 'nullable|string|max:20',
        'imei1'          => 'nullable|string|max:50',  // allowed but not used to find again
        'imei2'          => 'nullable|string|max:50',
        'brand'          => 'nullable|string|max:50',
        'model'          => 'nullable|string|max:255',
        'fcm_token'      => 'nullable|string',
        'is_mapped'      => 'nullable|boolean',
        'last_sync'      => 'nullable|date',
        'last_report'    => 'nullable|date',
        'lat'            => 'nullable|numeric',
        'lng'            => 'nullable|numeric',
        'retailer_id'    => 'nullable|integer',
        'retailer_code'  => 'nullable|string|max:255',
    ]);

    // Update only fields that were actually sent
    $customer->update($request->only(array_keys($data)));

    return response()->json([
        'success' => true,
        'message' => 'Customer updated successfully',
        'data' => $customer
    ], 200);
}
}