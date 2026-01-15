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
use App\Events\ClaimRaised;
use App\Events\ClaimStatusUpdated;
use Illuminate\Support\Facades\Log;
use App\Models\WarrantyProductCoverage;
use App\Models\WarrantyClaimCoverage;
use App\Models\ClaimReason;

class WarrantyClaimController extends Controller
{
  
 public function raiseClaim(Request $request)
{
    /* ================= VALIDATION ================= */
    $validator = Validator::make($request->all(), [
        'w_customer_id'     => 'required|integer',
        'w_device_id'       => 'required|integer',
        'issue_description' => 'required|string',
        'claim_type'        => 'required|in:pickup,drop',

        'pickup_address_id' => 'required_if:claim_type,pickup|integer',
        'drop_retailer_id'  => 'required_if:claim_type,drop|integer|exists:companies,id',

        'photo_ids'         => 'required|array|min:1',
        'photo_ids.*'       => 'integer|exists:warranty_claim_uploads,id',

        // ✅ COVERAGES
        'coverage_ids'      => 'required|array|min:1',
        'coverage_ids.*'    => 'integer|exists:w_product_coverages,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    /* ================= DEVICE ================= */
    $device = WDevice::find($request->w_device_id);
    if (!$device) {
        return response()->json([
            'status'  => false,
            'message' => 'Device not found'
        ], 404);
    }

    DB::beginTransaction();

    try {

        /* ================= CREATE CLAIM ================= */
        $claim = WarrantyClaim::create([
            'w_customer_id'     => $request->w_customer_id,
            'w_device_id'       => $request->w_device_id,
            'company_id'        => $device->company_id,
            'claim_type'        => $request->claim_type,
            'pickup_address_id' => $request->claim_type === 'pickup'
                                    ? $request->pickup_address_id
                                    : null,
            'drop_retailer_id'  => $request->claim_type === 'drop'
                                    ? $request->drop_retailer_id
                                    : null,
            'issue_description' => $request->issue_description,
            'status'            => 'pending', // ✅ FIRST STATE
            'otp'               => null
        ]);

        /* ================= CLAIM CODE ================= */
        $claimCode = 'CLM-' . str_pad($claim->id, 6, '0', STR_PAD_LEFT);
        $claim->update(['claim_code' => $claimCode]);

        /* ================= FETCH & VALIDATE PHOTOS ================= */
        $uploads = WarrantyClaimUpload::whereIn('id', $request->photo_ids)
            ->where('w_customer_id', $request->w_customer_id)
            ->get();

        if ($uploads->count() !== count($request->photo_ids)) {
            throw new \Exception('Invalid photo ownership');
        }

        /* ================= SAVE PHOTOS ================= */
        foreach ($uploads as $upload) {
            WarrantyClaimPhoto::create([
                'warranty_claim_id' => $claim->id,
                'photo_type'        => $upload->photo_type,
                'photo_path'        => $upload->photo_path
            ]);
        }

        // 🧹 Remove temp uploads
        WarrantyClaimUpload::whereIn('id', $request->photo_ids)->delete();

        /* ================= COVERAGE SNAPSHOT ================= */
        $coverages = WarrantyProductCoverage::whereIn(
            'id',
            $request->coverage_ids
        )->get();

        foreach ($coverages as $coverage) {
            WarrantyClaimCoverage::create([
                'warranty_claim_id' => $claim->id,
                'coverage_id'       => $coverage->id,
                'coverage_title'    => $coverage->title
            ]);
        }

        DB::commit();

        /* ================= EVENT ================= */
        $claim->load(['customer', 'device', 'photos', 'coverages']);
        event(new ClaimRaised($claim));

        return response()->json([
            'status'     => true,
            'message'    => 'Claim raised successfully',
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


public function approveClaim(Request $request)
{
    $validator = Validator::make($request->all(), [
        'claim_id'  => 'required|integer|exists:warranty_claims,id',
        'reason_id' => 'nullable|integer|exists:claim_reasons,id',
        'remark'    => 'nullable|string'
    ]);

    if ($validator->fails()) {
        return response()->json(['status'=>false,'errors'=>$validator->errors()],422);
    }

    $claim = WarrantyClaim::find($request->claim_id);

    $claim->update([
        'status'     => 'approved',
        'reason_id' => $request->reason_id,
        'remark'    => $request->remark
    ]);

    // ✅ ADD THIS
    event(new ClaimStatusUpdated($claim, 'approved'));

    return response()->json([
        'status' => true,
        'message' => 'Claim approved'
    ]);
}

   public function verifyOtp(Request $request)
   {
    $validator = Validator::make($request->all(), [
        'claim_id' => 'required|integer|exists:warranty_claims,id',
        'otp'      => 'required|digits:6'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $claim = WarrantyClaim::find($request->claim_id);

    // ✅ Ensure claim is in correct stage
    if ($claim->status !== 'otp_sent') {
        return response()->json([
            'status' => false,
            'message' => 'OTP verification not allowed in current status'
        ], 400);
    }

    // ✅ Validate OTP
    if ($claim->otp !== $request->otp) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid OTP'
        ], 400);
    }

    // ✅ Update claim
    $claim->update([
        'otp_verified' => 1,
        'status'       => 'confirmed',
        'otp'          => null // 🔒 prevent reuse
    ]);

    return response()->json([
        'status'  => true,
        'message' => 'Claim confirmed successfully'
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
    
        event(new ClaimStatusUpdated(
            WarrantyClaim::find($request->claim_id),
            'picked_up'
        ));

        return response()->json(['status' => true]);
    }
    
    
 public function inspectionReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'claim_id'        => 'required|integer|exists:warranty_claims,id',
        'report'          => 'required',

        // 💰 New fields
        'estimate_amount' => 'required|numeric|min:0',
        'payable_amount'  => 'required|numeric|min:0',
        'payment_link'    => 'nullable|url',
        'payment_status'  => 'nullable|in:pending,paid,failed',
        'inspection_remark' => 'nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $claim = WarrantyClaim::find($request->claim_id);

    // ✅ Update claim with inspection + payment data
    $claim->update([
        'inspection_report' => $request->report,
        'estimate_amount'   => $request->estimate_amount,
        'payable_amount'    => $request->payable_amount,
        'payment_link'      => $request->payment_link,
        'payment_status'    => $request->payment_status ?? 'pending',
        'status'            => 'estimate_sent',
        'inspection_remark' => $request->inspection_remark ?? ""
    ]);

    // 🔔 Notify customer & company
    event(new ClaimStatusUpdated($claim, 'estimate_sent'));

    return response()->json([
        'status'  => true,
        'message' => 'Inspection report & estimate sent successfully'
    ]);
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
        
        $claim = WarrantyClaim::find($request->claim_id);

        event(new ClaimStatusUpdated($claim, 'repair_in_progress'));
    
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
        try {
    
            /* ================= VALIDATION ================= */
            $validator = Validator::make($request->all(), [
                'w_customer_id' => 'required|integer',
                'photo_type'    => 'required|in:front,back,top,bottom,other',
                'photo'         => 'required|file|max:15048'
            ]);
    
            if ($validator->fails()) {
    
                // 🔴 LOG VALIDATION FAILURE
                Log::warning('Upload photo validation failed', [
                    'errors' => $validator->errors(),
                    'request' => $request->except('photo')
                ]);
    
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
    
            /* ================= FILE UPLOAD ================= */
            if (!$request->hasFile('photo')) {
    
                Log::error('Photo file missing in request', [
                    'w_customer_id' => $request->w_customer_id
                ]);
    
                return response()->json([
                    'status' => false,
                    'message' => 'Photo file not found'
                ], 400);
            }
    
            $path = $request->file('photo')->store('claims/temp', 'public');
    
            if (!$path) {
    
                Log::error('Photo upload failed', [
                    'w_customer_id' => $request->w_customer_id
                ]);
    
                return response()->json([
                    'status' => false,
                    'message' => 'File upload failed'
                ], 500);
            }
    
            /* ================= DATABASE ================= */
            $upload = WarrantyClaimUpload::create([
                'w_customer_id' => $request->w_customer_id,
                'photo_type'    => $request->photo_type,
                'photo_path'    => $path
            ]);
    
            /* ================= SUCCESS ================= */
            Log::info('Photo uploaded successfully', [
                'photo_id' => $upload->id,
                'w_customer_id' => $request->w_customer_id
            ]);
    
            return response()->json([
                'status'    => true,
                'photo_id'  => $upload->id,
                'photo_url' => asset('storage/' . $path)
            ]);
    
        } catch (\Throwable $e) {
    
            // 🔥 CATCH ANY UNEXPECTED ERROR
            Log::critical('Upload photo API crashed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'request' => $request->except('photo')
            ]);
    
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong'
            ], 500);
        }
    }

public function list(Request $request)
{
    $validator = Validator::make($request->all(), [
        'claim_id'        => 'nullable|integer',
        'claim_code'      => 'nullable|string',
        'w_customer_id'   => 'nullable|integer',
        'w_device_id'     => 'nullable|integer',
        'company_id'      => 'nullable|integer',
        'retailer_id'     => 'nullable|integer',
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

    // ✅ FIXED QUERY
     $query = WarrantyClaim::query()->with([
            'reason',
            'photos',
            'customer:id,name,mobile,email',
            'pickupAddress',
            'device:id,product_name,model,imei1',
            'dropRetailer:id,business_name,city,pincode',
            'coverages:id,warranty_claim_id,coverage_id,coverage_title',
        
            // ✅ ASSIGNMENT + EMPLOYEE
            'assignment.employee:id,first_name,middle_name,last_name,official_phone,official_email,photo_url'
        ]);

    /* ========= FILTERS ========= */

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

    if ($request->filled('from_date') && $request->filled('to_date')) {
        $query->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    /* ========= SEARCH ========= */

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

    $claims = $query
        ->orderBy('id', 'desc')
        ->paginate($request->per_page ?? 10);

    return response()->json([
        'status' => true,
        'data'   => $claims
    ]);
}
    public function employeeClaims(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'employee_id' => 'required|integer',
        'status'      => 'nullable|string',
        'from_date'   => 'nullable|date',
        'to_date'     => 'nullable|date',
        'per_page'    => 'nullable|integer|min:1|max:100'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $query = WarrantyClaimAssignment::with([
        'claim.customer:id,name,mobile',
        'claim.device:id,product_name,model,imei1',
        'claim.dropRetailer:id,business_name,city,pincode'
    ])->where('employee_id', $request->employee_id);

    /* ===== FILTER BY CLAIM STATUS ===== */
    if ($request->filled('status')) {
        $query->whereHas('claim', function ($q) use ($request) {
            $q->where('status', $request->status);
        });
    }

    /* ===== DATE FILTER ===== */
    if ($request->filled('from_date') && $request->filled('to_date')) {
        $query->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    $perPage = $request->per_page ?? 10;

    $assignments = $query
        ->orderBy('id', 'desc')
        ->paginate($perPage);

    return response()->json([
        'status' => true,
        'data'   => $assignments
    ]);
}

    public function assignmentList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id'    => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'company_id'  => 'nullable|integer'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $query = WarrantyClaimAssignment::with([
            'claim.customer:id,name',
            'claim.device:id,product_name',
            'claim.dropRetailer:id,business_name'
        ]);
    
        if ($request->filled('claim_id')) {
            $query->where('warranty_claim_id', $request->claim_id);
        }
    
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
    
        if ($request->filled('company_id')) {
            $query->whereHas('claim', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }
    
        $assignments = $query->orderBy('id', 'desc')->get();
    
        return response()->json([
            'status' => true,
            'data'   => $assignments
        ]);
    }
    public function claimReason(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason_type' => 'nullable|in:reject,hold,info,approve'
        ]);

        if ($validator->fails()) {
            return response()->json(['status'=>false,'errors'=>$validator->errors()],422);
        }

        $query = ClaimReason::where('status', 1);

        if ($request->filled('reason_type')) {
            $query->where('reason_type', $request->reason_type);
        }

        return response()->json([
            'status' => true,
            'data' => $query->orderBy('id')->get()
        ]);
    }
    public function rejectClaim(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'claim_id'  => 'required|integer|exists:warranty_claims,id',
            'reason_id' => 'required|integer|exists:claim_reasons,id',
            'remark'    => 'nullable|string'
        ]);
    
        if ($validator->fails()) {
            return response()->json(['status'=>false,'errors'=>$validator->errors()],422);
        }
    
        $claim = WarrantyClaim::find($request->claim_id);
    
        $claim->update([
            'status'     => 'rejected',
            'reason_id' => $request->reason_id,
            'remark'    => $request->remark
        ]);
    
        event(new ClaimStatusUpdated($claim, 'rejected'));
    
        return response()->json([
            'status' => true,
            'message' => 'Claim rejected successfully'
        ]);
    }
}