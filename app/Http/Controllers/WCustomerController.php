<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\WCustomer;
use App\Models\WDevice;

use DB;

use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Events\CustomerRegistered;

class WCustomerController extends Controller
{
    public function checkCustomerByMobile(Request $request)
    {
        // Validator
        $validator = Validator::make($request->all(), [
            'mobile' => 'required|digits_between:8,15',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check customer
        $customer = WCustomer::where('mobile', $request->mobile)->first();

        if ($customer) {
            return response()->json([
                'status' => true,
                'message' => 'Customer found',
                'data' => $customer
            ], 200);
        }

        return response()->json([
            'status' => false,
            'message' => 'No customer found'
        ], 404);
    }
    public function getCustomers(Request $request)
    {
        $query = WCustomer::with(['devices', 'retailer']);
    
        /* ================= GLOBAL SEARCH ================= */
        if ($request->filled('search')) {
            $search = $request->search;
    
            $query->where(function ($q) use ($search) {
    
                // Customer fields
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('mobile', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
    
                // Retailer name
                $q->orWhereHas('retailer', function ($r) use ($search) {
                    $r->where('business_name', 'LIKE', "%{$search}%");
                });
    
                // Device fields
                $q->orWhereHas('devices', function ($d) use ($search) {
                    $d->where('imei1', 'LIKE', "%{$search}%")
                      ->orWhere('imei2', 'LIKE', "%{$search}%")
                      ->orWhere('serial', 'LIKE', "%{$search}%");
                });
            });
        }
    
        /* ================= EXACT FILTERS ================= */
        if ($request->filled('retailer_id')) {
            $query->where('retailer_id', $request->retailer_id);
        }
        
       if ($request->filled('company_id')) {
            $query->whereHas('devices', function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });
        }

    
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }
        
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }
    
        /* ================= INVOICE STATUS FILTER ================= */
        if ($request->filled('invoice_status')) {
            $status = strtolower(trim($request->invoice_status));
    
            if ($status === 'invoiced') {
                $query->whereHas('devices', fn($q) => $q->whereNotNull('invoice_id'));
            }
    
            if ($status === 'uninvoiced') {
             
                $query->whereHas('devices', fn($q) => $q->whereNull('invoice_id'));
            }
        }
    
        /* ================= DATE RANGE FILTER (ON DEVICES) ================= */
        if ($request->filled('from_invoice_date') || $request->filled('to_invoice_date')) {
            $from = $request->from_invoice_date;
            $to   = $request->to_invoice_date;
    
            $query->whereHas('devices', function ($q) use ($from, $to) {
    
                if ($from && $to) {
                    $q->whereBetween('invoice_created_date', [$from, $to]);
                } elseif ($from) {
                    $q->whereDate('invoice_created_date', '>=', $from);
                } elseif ($to) {
                    $q->whereDate('invoice_created_date', '<=', $to);
                }
            });
        }
    
        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate(10),
            200
        );
    }
        
    public function createCustomerNew(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'mobile'      => 'required|digits:10',
            'email'       => 'required|email',
            'state'       => 'required|string',
            'city'        => 'required|string',
            'pincode'     => 'required|string',
            'location'    => 'required|string',
            'retailer_id' => 'nullable|integer',
            'company_id'  => 'nullable|integer',
            'agent_id'    => 'nullable|integer',
            'address1'    => 'nullable|string',
            'address2'    => 'nullable|string',
            'created_by'  => 'nullable|integer'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        return DB::transaction(function () use ($request) {
    
            // 🔍 Check existing customer
            $customer = WCustomer::where('mobile', $request->mobile)
                ->orWhere('email', $request->email)
                ->first();
    
            // 🆕 If customer does NOT exist
            if (!$customer) {
    
                // ✅ Step 1: Create customer
                $customer = WCustomer::create([
                    'name'        => $request->name,
                    'mobile'      => $request->mobile,
                    'email'       => $request->email,
                    'state'       => $request->state,
                    'city'        => $request->city,
                    'pincode'     => $request->pincode,
                    'location'    => $request->location,
                    'retailer_id' => $request->retailer_id,
                    'address1'    => $request->address1,
                    'address2'    => $request->address2,
                    'company_id'  => $request->company_id,
                    'agent_id'    => $request->agent_id,
                    'created_by'  => $request->created_by
                ]);
    
                // ✅ Step 2: Generate c_code using primary key
                $random = strtoupper(\Illuminate\Support\Str::random(6));
                $cCode = "CST-{$customer->id}-{$random}";
    
                // ✅ Step 3: Update customer with c_code
                $customer->update([
                    'c_code' => $cCode
                ]);
    
                event(new CustomerRegistered($customer));
                // First address
                $customer->addresses()->create([
                    'w_customer_id' => $customer->id,
                    'name' => $request->name,
                    'mobile' => $request->mobile,
                    'address1' => $request->address1,
                    'address2' => $request->address2,
                    'city' => $request->city,
                    'state' => $request->state,
                    'pincode' => $request->pincode,
                    'lat' => $request->lat,
                    'lng' => $request->lng
                ]);
    
                return response()->json([
                    'message' => 'Customer, c_code, and address created successfully',
                    'data'    => $customer->load('addresses')
                ], 201);
            }
    
            // 🔁 Customer exists → check address
            $addressExists = $customer->addresses()
                ->where('pincode', $request->pincode)
                ->exists();
    
            if (!$addressExists) {
                $customer->addresses()->create([
                    'w_customer_id' => $customer->id,
                    'name' => $request->name,
                    'mobile' => $request->mobile,
                    'address1' => $request->address1,
                    'address2' => $request->address2,
                    'city' => $request->city,
                    'state' => $request->state,
                    'pincode' => $request->pincode,
                    'lat' => $request->lat,
                    'lng' => $request->lng
                ]);
            }
    
            return response()->json([
                'message' => $addressExists
                    ? 'Customer already exists with same address'
                    : 'Customer exists, new address added',
                'data' => $customer->load('addresses')
            ], 200);
        });
    }

    
   public function updateWarrantyStatus(Request $request, $id)
   {
    $validator = Validator::make($request->all(), [
        'status'        => 'required|integer|max:50',
        'reject_remark' => 'nullable|string|max:500',
        'notes'      =>'required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    $device = WDevice::findOrFail($id);

    $device->update($validator->validated());

    return response()->json([
        'success' => true,
        'message' => 'Warranty updated successfully',
        'data'    => $device
    ], 200);
}
    
    public function deviceAnalytics(Request $request)
    {
        $query = WDevice::query();
    
        /* ================= APPLY FILTER ================= */
    
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }
    
        if ($request->filled('retailer_id')) {
            $query->where('retailer_id', $request->retailer_id);
        }
    
        if ($request->filled('company_id')) {
            // company_id maps to promoter_id
            $query->where('promoter_id', $request->company_id);
        }
    
        /* ================= AGGREGATES ================= */
    
        $data = $query->selectRaw('
            COUNT(*) as total_devices,
            SUM(product_price) as total_product_price,
            SUM(retailer_payout) as total_retailer_payout,
            SUM(employee_payout) as total_employee_payout,
            SUM(other_payout) as total_other_payout,
            SUM(company_payout) as total_company_payout
        ')->first();
    
        return response()->json([
            'success' => true,
            'filters' => $request->only(['agent_id', 'retailer_id', 'company_id']),
            'analytics' => [
                'total_devices'          => (int) $data->total_devices,
                'total_product_price'    => (float) $data->total_product_price,
                'retailer_payout'        => (float) $data->total_retailer_payout,
                'employee_payout'        => (float) $data->total_employee_payout,
                'other_payout'           => (float) $data->total_other_payout,
                'company_payout'         => (float) $data->total_company_payout,
            ]
        ], 200);
    }
    
    public function getDevices(Request $request)
    {
    $query = WDevice::with([
        'customer',
        'customer.retailer'
    ]);

    /* ================= GLOBAL SEARCH ================= */
    if ($request->filled('search')) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {

            // Device fields
            $q->where('imei1', 'LIKE', "%{$search}%")
              ->orWhere('imei2', 'LIKE', "%{$search}%")
              ->orWhere('serial', 'LIKE', "%{$search}%")
              ->orWhere('product_name', 'LIKE', "%{$search}%")
              ->orWhere('brand_name', 'LIKE', "%{$search}%");

            // Customer fields
            $q->orWhereHas('customer', function ($c) use ($search) {
                $c->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('mobile', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });

            // Retailer fields
            $q->orWhereHas('customer.retailer', function ($r) use ($search) {
                $r->where('business_name', 'LIKE', "%{$search}%");
            });
        });
    }

    /* ================= EXACT FILTERS ================= */
    if ($request->filled('id')) {
        $query->where('id', $request->id);
    }
     if ($request->filled('retailer_id')) {
        $query->where('retailer_id', $request->retailer_id);
    }

    if ($request->filled('agent_id')) {
        $query->where('agent_id', $request->agent_id);
    }

    if ($request->filled('created_by')) {
        $query->where('created_by', $request->created_by);
    }

    if ($request->filled('customer_id')) {
        $query->where('w_customer_id', $request->customer_id);
    }
    
     if ($request->filled('company_id')) {
        $query->where('company_id', $request->company_id);
    }

    /* ================= INVOICE STATUS ================= */
    if ($request->filled('invoice_status')) {
        $status = strtolower(trim($request->invoice_status));

        if ($status === 'invoiced') {
            $query->whereNotNull('invoice_id');
        }

        if ($status === 'uninvoiced') {
            $query->whereNull('invoice_id');
        }
    }

    if ($request->filled('credit_note')) {
        
        $credit_note = strtolower(trim($request->credit_note));

        if ($credit_note === 'credit_note') {
            $query->whereNotNull('credit_note');
        }

    }


    /* ================= DATE RANGE FILTER ================= */
    if ($request->filled('from_invoice_date') || $request->filled('to_invoice_date')) {
        $from = $request->from_invoice_date;
        $to   = $request->to_invoice_date;

        if ($from && $to) {
            $query->whereBetween('invoice_created_date', [$from, $to]);
        } elseif ($from) {
            $query->whereDate('invoice_created_date', '>=', $from);
        } elseif ($to) {
            $query->whereDate('invoice_created_date', '<=', $to);
        }
    }

    return response()->json(
        $query->orderBy('created_at', 'desc')->paginate(10),
        200
    );
}

public function sendCustomerEmailOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email'
    ]);

    $customer = WCustomer::where('email', $request->email)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not found'
        ], 404);
    }

    // 🔐 Generate 6-digit OTP
    $otp = random_int(100000, 999999);

    // ⏳ OTP expiry (5 minutes)
    $customer->update([
        'otp' => $otp,
        'otp_expires_at' => Carbon::now()->addMinutes(5),
        'is_email_verified' => 0
    ]);

    // 📧 Send email
    Mail::send('emails.customer_otp', [
        'name' => $customer->name,
        'otp'  => $otp
    ], function ($mail) use ($customer) {
        $mail->to($customer->email)
             ->subject('Your Login OTP');
    });

    return response()->json([
        'status' => true,
        'message' => 'OTP sent to email successfully'
    ], 200);
}

public function verifyCustomerEmailOtp(Request $request)
{
    $request->validate([
        'email' => 'required|email',
        'otp'   => 'required|digits:6'
    ]);

    $customer = WCustomer::where('email', $request->email)->first();

    if (!$customer) {
        return response()->json([
            'status' => false,
            'message' => 'Customer not found'
        ], 404);
    }

    if (
        $customer->otp !== $request->otp ||
        Carbon::now()->greaterThan($customer->otp_expires_at)
    ) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid or expired OTP'
        ], 400);
    }

    // ✅ OTP verified
    $customer->update([
        'otp' => null,
        'otp_expires_at' => null,
        'is_email_verified' => 1
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Login successful',
        'role'=>7,
        'data' => $customer->load([
            'addresses',
            'devices',
            'retailer'
        ])
    ], 200);
}

public function payouts(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id'  => 'nullable|integer|exists:companies,id',
        'retailer_id' => 'nullable|integer|exists:companies,id',
        'agent_id'    => 'nullable|integer|exists:companies,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $query = WDevice::query();

    /* ================= FILTERS ================= */
    $query->when($request->filled('company_id'), fn ($q) =>
        $q->where('company_id', $request->company_id)
    );

    $query->when($request->filled('retailer_id'), fn ($q) =>
        $q->where('retailer_id', $request->retailer_id)
    );

    $query->when($request->filled('agent_id'), fn ($q) =>
        $q->where('agent_id', $request->agent_id)
    );

    /* ================= PAYOUT COLUMN ================= */
    $earningColumn = $request->filled('agent_id')
        ? 'other_payout'
        : 'retailer_payout';

    /* ================= AGGREGATES ================= */
   $summary = $query->selectRaw("
    COUNT(*) AS warranty_submitted,

    COALESCE(SUM(product_price), 0) AS total_sales,

    COALESCE(
        SUM(CASE 
            WHEN invoice_status = 'paid' 
            THEN product_price 
            ELSE 0 
        END), 0
    ) AS total_sales_invoice_paid,

    COALESCE(
        SUM(CASE 
            WHEN invoice_status IS NOT NULL 
             AND invoice_status != '' 
            THEN product_price
            ELSE 0
        END), 0
    ) AS total_sales_invoiced,

    COALESCE(
        SUM(CASE 
            WHEN invoice_status IS NULL 
              OR invoice_status = '' 
            THEN product_price
            ELSE 0
        END), 0
    ) AS total_sales_uninvoiced,

    COALESCE(
        SUM(CASE 
            WHEN invoice_status != 'paid'
            THEN product_price 
            ELSE 0 
        END), 0
    ) AS payable_amount,

    COALESCE(
        SUM(CASE 
            WHEN invoice_id IS NULL 
              OR invoice_id = '' 
            THEN product_price 
            ELSE 0 
        END), 0
    ) AS pending_invoice_amount,

    COALESCE(
        SUM(CASE 
            WHEN invoice_id IS NULL 
              OR invoice_id = '' 
            THEN 1 
            ELSE 0 
        END), 0
    ) AS pending_invoice_count,

    COALESCE(
        SUM(CASE 
            WHEN credit_note IS NOT NULL 
            THEN product_price 
            ELSE 0 
        END), 0
    ) AS credit_note_amount,
    
       COALESCE(
        SUM(
            CASE 
                WHEN invoice_status = 'paid'
                THEN product_price
                ELSE 0
            END
        ), 0
    ) AS paid_amount,

    COALESCE(
        SUM(
            CASE 
                WHEN credit_note IS NOT NULL 
                THEN 1
                ELSE 0
            END
        ), 0
    ) AS credit_note_count,

    COALESCE(SUM($earningColumn), 0) AS my_earnings
")->first();

    /* ================= RESPONSE ================= */
    return response()->json([
        'status' => true,
        'data' => [
            'total_sales'              => (float) $summary->total_sales,
            'warranty_submitted'       => (int) $summary->warranty_submitted,

            'total_sales_invoice_paid'=> (float) $summary->total_sales_invoice_paid,
            'total_sales_invoiced'    => (float) $summary->total_sales_invoiced,
            'total_sales_uninvoiced'  => (float) $summary->total_sales_uninvoiced,

            'pending_invoices' => [
                'count'  => (int) $summary->pending_invoice_count,
                'amount' => (float) $summary->pending_invoice_amount,
            ],

            'credit_notes'   => (float) $summary->credit_note_amount,
            'payable_amount' => (float) $summary->payable_amount,
            'my_earnings'    => (float) $summary->my_earnings,
            'paid_amount'    => (float) $summary->paid_amount,
            'credit_note_count'    => (float) $summary->credit_note_count
        ]
    ], 200);
}


}