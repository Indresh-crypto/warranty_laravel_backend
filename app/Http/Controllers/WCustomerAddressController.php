<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\WCustomerAddress;

class WCustomerAddressController extends Controller
{
    /**
     * 📄 List all addresses of a customer
     */
    public function list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'w_customer_id' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $addresses = WCustomerAddress::where('w_customer_id', $request->w_customer_id)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $addresses
        ]);
    }

    /**
     * ➕ Create new address
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'w_customer_id' => 'required|integer',
            'name'          => 'required|string|max:100',
            'mobile'        => 'required|string|max:15',
            'address1'      => 'required|string',
            'address2'      => 'nullable|string',
            'city'          => 'required|string',
            'state'         => 'required|string',
            'pincode'       => 'required|digits:6',
            'lat'           => 'nullable|numeric',
            'lng'           => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $address = WCustomerAddress::create([
            'w_customer_id' => $request->w_customer_id,
            'name'          => $request->name,
            'mobile'        => $request->mobile,
            'address1'      => $request->address1,
            'address2'      => $request->address2,
            'city'          => $request->city,
            'state'         => $request->state,
            'pincode'       => $request->pincode,
            'lat'           => $request->lat,
            'lng'           => $request->lng
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Address added successfully',
            'data'    => $address
        ]);
    }

    /**
     * ✏️ Update address
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id' => 'required|integer|exists:w_customer_addresses,id',
            'name'       => 'required|string|max:100',
            'mobile'     => 'required|string|max:15',
            'address1'   => 'required|string',
            'address2'   => 'nullable|string',
            'city'       => 'required|string',
            'state'      => 'required|string',
            'pincode'    => 'required|digits:6',
            'lat'        => 'nullable|numeric',
            'lng'        => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $address = WCustomerAddress::find($request->address_id);

        $address->update([
            'name'     => $request->name,
            'mobile'   => $request->mobile,
            'address1' => $request->address1,
            'address2' => $request->address2,
            'city'     => $request->city,
            'state'    => $request->state,
            'pincode'  => $request->pincode,
            'lat'      => $request->lat,
            'lng'      => $request->lng
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Address updated successfully',
            'data'    => $address
        ]);
    }

    /**
     * 🗑 Delete address
     */
    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id' => 'required|integer|exists:w_customer_addresses,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        WCustomerAddress::where('id', $request->address_id)->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Address deleted successfully'
        ]);
    }
}