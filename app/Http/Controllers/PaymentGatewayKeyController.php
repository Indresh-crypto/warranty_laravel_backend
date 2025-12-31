<?php

namespace App\Http\Controllers;

use App\Models\PaymentGatewayKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentGatewayKeyController extends Controller
{
    // Get all records
    public function index()
    {
        return response()->json(PaymentGatewayKey::all());
    }

    // Create new record
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key_id'     => 'required|string',
            'key_secret' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }

        $pg = PaymentGatewayKey::create($validator->validated());

        return response()->json([
            'status'  => true,
            'message' => 'Payment gateway key created successfully',
            'data'    => $pg
        ], 201);
    }

    // Show a single key
    public function show($id)
    {
        $pg = PaymentGatewayKey::find($id);

        if (!$pg) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        return response()->json($pg);
    }

    // Update a record
    public function update(Request $request, $id)
    {
        $pg = PaymentGatewayKey::find($id);

        if (!$pg) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'key_id'     => 'nullable|string',
            'key_secret' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $pg->update($validator->validated());

        return response()->json([
            'status'  => true,
            'message' => 'Payment key updated successfully',
            'data'    => $pg
        ]);
    }

    // Delete record
    public function destroy($id)
    {
        $pg = PaymentGatewayKey::find($id);

        if (!$pg) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $pg->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Payment key deleted successfully'
        ]);
    }
}