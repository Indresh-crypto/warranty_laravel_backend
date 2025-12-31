<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WarrantyClaim;
use App\Models\WarrantyClaimPhoto;
use App\Models\WarrantyClaimAssignment;
use App\Models\WDevice;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\WarrantyClaimUpload;


class WarrantyClaimController extends Controller
{
  
    public function raiseClaim(Request $request)
    {
       
      
      $validator = Validator::make($request->all(), [
            'w_customer_id'     => 'required|integer',
            'w_device_id'       => 'required|integer',
            'issue_description' => 'required|string',
            'claim_type'        => 'required|in:pickup,drop',
        
            // pickup
            'pickup_address_id' => 'required_if:claim_type,pickup|integer',
        
            // drop
            'drop_retailer_id'  => 'required_if:claim_type,drop|integer|exists:companies,id',
        
            'photo_ids'         => 'required|array|min:1',
            'photo_ids.*'       => 'integer|exists:warranty_claim_uploads,id'
        ]);


    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $device = WDevice::find($request->w_device_id);
    
        if (!$device) {
            return response()->json([
                'status' => false,
                'message' => 'Device not found'
            ], 404);
        }
    
        DB::beginTransaction();
    
        try {
    
            $otp = rand(100000, 999999);
    
            // 1️⃣ Create Claim
          $claim = WarrantyClaim::create([
            'w_customer_id'     => $request->w_customer_id,
            'w_device_id'       => $request->w_device_id,
            'company_id'        => $device->company_id,
            'claim_type'        => 'drop',
            'drop_retailer_id'  => $request->drop_retailer_id,
            'issue_description' => $request->issue_description,
            'otp'               => $otp,
            'status'            => 'otp_sent'
        ]);
            
            // 2️⃣ Generate Claim Code
            $claimCode = 'CLM-' . str_pad($claim->id, 6, '0', STR_PAD_LEFT);
            $claim->update(['claim_code' => $claimCode]);
    
            // 3️⃣ Attach uploaded photos
           
           $uploads = WarrantyClaimUpload::whereIn('id', $request->photo_ids)
                    ->where('w_customer_id', $request->w_customer_id)
                    ->get();
                
                if ($uploads->count() !== count($request->photo_ids)) {
                    throw new \Exception('Invalid photo ownership');
                }
                
                foreach ($uploads as $upload) {
                    WarrantyClaimPhoto::create([
                        'warranty_claim_id' => $claim->id,
                        'photo_type'        => $upload->photo_type,
                        'photo_path'        => $upload->photo_path
                    ]);
                }

    
            // (Optional) delete temp uploads
            WarrantyClaimUpload::whereIn('id', $request->photo_ids)->delete();
    
            DB::commit();
    
            return response()->json([
                'status'     => true,
                'claim_id'   => $claim->id,
                'claim_code' => $claimCode
            ]);
    
        } catch (\Exception $e) {
    
            DB::rollBack();
    
            return response()->json([
                'status'  => false,
                'message' => 'Failed to raise claim',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id' => 'required|integer',
            'otp'      => 'required|digits:6'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $claim = WarrantyClaim::find($request->claim_id);
    
        if (!$claim || $claim->otp != $request->otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }
    
        $claim->update([
            'otp_verified' => 1,
            'status'       => 'confirmed'
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Claim confirmed'
        ]);
    }
    
        
    public function assignEmployee(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id'    => 'required|integer',
            'employee_id' => 'required|integer'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        WarrantyClaimAssignment::create([
            'warranty_claim_id' => $request->claim_id,
            'employee_id'       => $request->employee_id,
            'pickup_otp'        => rand(100000, 999999),
            'delivery_otp'      => rand(100000, 999999)
        ]);
    
        WarrantyClaim::where('id', $request->claim_id)
            ->update(['status' => 'assigned']);
    
        return response()->json(['status' => true]);
    }
    
        
    public function verifyPickupOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id' => 'required|integer',
            'otp'      => 'required|digits:6'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $assign = WarrantyClaimAssignment::where(
            'warranty_claim_id',
            $request->claim_id
        )->first();
    
        if (!$assign || $assign->pickup_otp != $request->otp) {
            return response()->json(['status' => false, 'message' => 'Invalid OTP']);
        }
    
        $assign->update(['pickup_verified' => 1]);
    
        WarrantyClaim::where('id', $request->claim_id)
            ->update(['status' => 'picked_up']);
    
        return response()->json(['status' => true]);
    }
    
    
    public function inspectionReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id' => 'required|integer',
            'report'   => 'required|string',
            'amount'   => 'nullable|numeric'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        WarrantyClaim::where('id', $request->claim_id)->update([
            'inspection_report' => $request->report,
            'estimate_amount'   => $request->amount,
            'status'            => 'estimate_sent'
        ]);
    
        return response()->json(['status' => true]);
    }
    
    public function approveEstimate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id' => 'required|integer'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        WarrantyClaim::where('id', $request->claim_id)->update([
            'estimate_approved' => 1,
            'status'            => 'repair_in_progress'
        ]);
    
        return response()->json(['status' => true]);
    }
    
    
    public function verifyDeliveryOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id' => 'required|integer',
            'otp'      => 'required|digits:6'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $assign = WarrantyClaimAssignment::where(
            'warranty_claim_id',
            $request->claim_id
        )->first();
    
        if (!$assign || $assign->delivery_otp != $request->otp) {
            return response()->json(['status' => false, 'message' => 'Invalid OTP']);
        }
    
        $assign->update(['delivery_verified' => 1]);
    
        WarrantyClaim::where('id', $request->claim_id)
            ->update(['status' => 'completed']);
    
        return response()->json(['status' => true]);
    }


    public function uploadPhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'w_customer_id' => 'required|integer',
            'photo_type'    => 'required|in:front,back,top,bottom,other',
            'photo'         => 'required|image|max:2048'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $path = $request->file('photo')->store('claims/temp', 'public');
    
        $upload = WarrantyClaimUpload::create([
            'w_customer_id' => $request->w_customer_id,
            'photo_type'    => $request->photo_type,
            'photo_path'    => $path
        ]);
    
        return response()->json([
            'status'    => true,
            'photo_id'  => $upload->id,
            'photo_url' => asset('storage/' . $path)
        ]);
    }

    public function list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id'        => 'nullable|integer',
            'claim_code'      => 'nullable|string',
            'w_customer_id'   => 'nullable|integer',
            'w_device_id'     => 'nullable|integer',
            'company_id'      => 'nullable|integer',
            'retailer_id'     => 'nullable|integer', // role = 5
            'claim_type'      => 'nullable|in:pickup,drop',
            'status'          => 'nullable|string',
            'from_date'       => 'nullable|date',
            'to_date'         => 'nullable|date',
            'search'          => 'nullable|string',
            'per_page'        => 'nullable|integer|min:1|max:100'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $query = WarrantyClaim::query()
            ->with([
                'customer:id,name,mobile,email',
                'device:id,product_name,model,imei1',
                'dropRetailer:id,business_name,city,pincode'
            ]);
    
        /* ================== EXACT FILTERS ================== */
    
        if ($request->filled('claim_id')) {
            $query->where('id', $request->claim_id);
        }
    
        if ($request->filled('claim_code')) {
            $query->where('claim_code', $request->claim_code);
        }
    
        if ($request->filled('w_customer_id')) {
            $query->where('w_customer_id', $request->w_customer_id);
        }
    
        if ($request->filled('w_device_id')) {
            $query->where('w_device_id', $request->w_device_id);
        }
    
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
    
        if ($request->filled('retailer_id')) {
            $query->where('drop_retailer_id', $request->retailer_id);
        }
    
        if ($request->filled('claim_type')) {
            $query->where('claim_type', $request->claim_type);
        }
    
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
    
        /* ================== DATE FILTER ================== */
    
        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('created_at', [
                $request->from_date . ' 00:00:00',
                $request->to_date . ' 23:59:59'
            ]);
        }
    
        /* ================== GLOBAL SEARCH ================== */
    
        if ($request->filled('search')) {
            $search = $request->search;
    
            $query->where(function ($q) use ($search) {
                $q->where('claim_code', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($c) use ($search) {
                      $c->where('name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                  })
                  ->orWhereHas('device', function ($d) use ($search) {
                      $d->where('product_name', 'like', "%{$search}%")
                        ->orWhere('imei1', 'like', "%{$search}%");
                  });
            });
        }
    
        /* ================== PAGINATION ================== */
    
        $perPage = $request->per_page ?? 10;
    
        $claims = $query
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    
        return response()->json([
            'status' => true,
            'data'   => $claims
        ]);
    }
}