<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\RetailerConnection;
use App\Models\Company;

use Illuminate\Support\Facades\Validator;
use DB;
class RetailerConnectionController extends Controller
{

public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'retailer_id'     => 'required|integer|exists:companies,id',
        'created_by_id'   => 'required|integer|exists:company_employee,id',
        'created_by_name' => 'required|string',
        'note'              => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    DB::beginTransaction();

    try {

        // ✅ Create Retailer Connection
        $connection = RetailerConnection::create([
            'retailer_id'     => $request->retailer_id,
            'created_by_id'   => $request->created_by_id,
            'created_by_name' => $request->created_by_name,
            'note'            => $request->note
        ]);

        // ✅ Update Companies Table (Retailer)
        Company::where('id', $request->retailer_id)
            ->update([
                'last_connected_by_id'   => $request->created_by_id,
                'last_connected_name'    => $request->created_by_name,
                'last_connected_date'    => now()
            ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Retailer connection saved and company updated successfully',
            'data' => $connection
        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'message' => 'Something went wrong',
            'error' => $e->getMessage()
        ], 500);
    }
}

public function index(Request $request)
{
    $query = RetailerConnection::with([
        'employee:id,first_name,middle_name,last_name,official_email,photo_url',
        'company:id,business_name,logo,contact_phone'
    ]);

    // 🔹 Filter by Retailer ID
    if ($request->filled('retailer_id')) {
        $query->where('retailer_id', $request->retailer_id);
    }

    // 🔹 Filter by Employee ID (created_by_id)
    if ($request->filled('created_by_id')) {
        $query->where('created_by_id', $request->created_by_id);
    }

    $data = $query->orderBy('id', 'desc')->get();

    return response()->json([
        'status' => true,
        'data' => $data
    ]);
}

}