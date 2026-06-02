<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OrgUsersMaster;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use GuzzleHttp\Client;
use Carbon\Carbon;

class OrgUserMasterController extends Controller
{
    public function commonSignup(Request $request)
    {
        DB::beginTransaction();

        try {

            // Validation
           $validator = Validator::make($request->all(), [
                'business_name' => 'required|string',
                'owner_name' => 'required|string',
                'phone' => 'required|string|unique:org_users_master,phone',
                'email' => 'required|email|unique:org_users_master,email',
                'state' => 'required|string',
                'city' => 'required|string',
                'pincode' => 'required|string',
                'company_id' => 'nullable|integer',
                'products' => 'required',
                'role' => 'required|string',
                'catalog' => 'required'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Products handling (string or array)
            $products = is_array($request->products)
                ? $request->products
                : array_map('trim', explode(',', $request->products));

            // Generate org_code
            $orgCode = $this->generateOrgCode($request->role, $request->state);

            // 1. Insert into master table
            $master = OrgUsersMaster::create([
                'business_name' => $request->business_name,
                'org_code' => $orgCode,
                'phone' => $request->phone,
                'email' => $request->email,
                'state' => $request->state,
                'city' => $request->city,
                'pincode' => $request->pincode,
                'company_id' => $request->company_id,
                'products' => $products,
                'role' => $request->role,
                'catalog' => $request->catalog
            ]);

            // 2. EMI LOCKER → second DB
            if (in_array('emi_locker', $products)) {

                DB::connection('org_uat_mysql')->table('org_users')->insert([
                    'business_name' => $request->business_name,
                    'mobile' => $request->phone,
                    'email' => $request->email,
                    'owner_name' => $request->owner_name,
                    'pincode' => $request->pincode,
                    'org_code' => $orgCode,
                    'company_id' => $request->company_id,
                    'password' => $request->password ?? '123456',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 3. WARRANTY → companies table
            if (in_array('warranty', $products)) {

                Company::create([
                    'business_name' => $request->business_name,
                    'contact_person' => $request->owner_name,
                    'contact_phone' => $request->phone,
                    'contact_email' => $request->email,
                    'password' => $request->password ?? '123456',
                    'city' => $request->city,
                    'state' => $request->state,
                    'pincode' => $request->pincode,
                    'company_code' => $orgCode,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Signup successful',
                'data' => $master
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ============================
    // HELPER FUNCTIONS
    // ============================

    private function getRoleCode($role)
    {
        return match (strtolower($role)) {
            '5' => 'ARP',
            '4' => 'DIST',
            '2' => 'COMP',
            '6' => 'PRO',
            default => 'ARP',
        };
    }

    private function getStateCode($state)
    {
        $states = [
            'jammu and kashmir' => 'JK',
            'ladakh' => 'LA',
            'himachal pradesh' => 'HP',
            'punjab' => 'PB',
            'chandigarh' => 'CH',
            'uttarakhand' => 'UK',
            'haryana' => 'HR',
            'delhi' => 'DL',
            'rajasthan' => 'RJ',
            'uttar pradesh' => 'UP',
            'bihar' => 'BR',
            'sikkim' => 'SK',
            'arunachal pradesh' => 'AR',
            'nagaland' => 'NL',
            'manipur' => 'MN',
            'mizoram' => 'MZ',
            'tripura' => 'TR',
            'meghalaya' => 'ML',
            'assam' => 'AS',
            'west bengal' => 'WB',
            'jharkhand' => 'JH',
            'odisha' => 'OD',
            'chhattisgarh' => 'CG',
            'madhya pradesh' => 'MP',
            'gujarat' => 'GJ',
            'daman and diu' => 'DD',
            'dadra and nagar haveli' => 'DN',
            'maharashtra' => 'MH',
            'karnataka' => 'KA',
            'goa' => 'GA',
            'lakshadweep' => 'LD',
            'kerala' => 'KL',
            'tamil nadu' => 'TN',
            'puducherry' => 'PY',
            'andaman and nicobar islands' => 'AN',
            'telangana' => 'TS',
            'andhra pradesh' => 'AP',
        ];
    
        $key = strtolower(trim($state));
    
        return $states[$key] ?? strtoupper(substr($state, 0, 2));
    }

    public function generateOrgCode($role, $state)
    {
        $roleCode = $this->getRoleCode($role);
        $stateCode = $this->getStateCode($state);

        // Lock row for concurrency safety
        $last = OrgUsersMaster::where('role', $role)
            ->where('state', $state)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->org_code, $match)) {
            $number = (int)$match[1] + 1;
        } else {
            $number = 1001;
        }

        return "{$roleCode}-{$stateCode}-{$number}";
    }

public function createContact(Request $request)
{
    DB::beginTransaction();

    try {

        // =====================================================
        // VALIDATION
        // =====================================================

        $validator = Validator::make($request->all(), [

            'company_id' => 'required|exists:org_users_master,id',

            'business_name' => 'required|string|max:255',

            'mobile' => 'required|string|max:20',

            'email' => 'required|email|unique:org_users_master,email',

            'state' => 'required|string|max:255',

            'district' => 'nullable|string|max:255',

            'pincode' => 'required|string|max:10',

            'role' => 'required|integer',

            'owner_name' => 'required|string|max:255',

            'password' => 'required|string|min:6',

            // =====================================
            // ZOHO
            // =====================================

            'contact_name' => 'required|string|max:255',

            'company_name' => 'required|string|max:255',

            'contact_type' => 'required|in:customer,vendor',

            // =====================================
            // OPTIONAL
            // =====================================

            'address' => 'nullable|string',

            'gst_no' => 'nullable|string|max:30',

            'pan' => 'nullable|string|max:20',

            'dist_id' => 'nullable',

            'dist_name' => 'nullable',
        ]);

        if ($validator->fails()) {

            return response()->json([

                'status' => false,

                'errors' => $validator->errors()

            ], 422);
        }

        // =====================================================
        // COMPANY (ZOHO CREDS)
        // =====================================================

        $company = OrgUsersMaster::find($request->company_id);

        if (
            !$company ||
            !$company->zoho_access_token ||
            !$company->zoho_org_id
        ) {

            return response()->json([

                'status' => false,

                'error' => 'Zoho credentials not found.'

            ], 400);
        }

        // =====================================================
        // CREATE MASTER USER
        // =====================================================

        $orgCode = $this->generateOrgCode(
            $request->role,
            $request->state
        );

        $user = OrgUsersMaster::create([

            'business_name' => $request->business_name,

            'mobile' => $request->mobile,

            'email' => strtolower(trim($request->email)),

            'state' => $request->state,

            'district' => $request->district,

            'pincode' => $request->pincode,

            'role' => $request->role,

            'owner_name' => $request->owner_name,

            'company_id' => $request->company_id,

            'address' => $request->address,

            'password' => bcrypt($request->password),

            'dist_id' => $request->dist_id,

            'dist_name' => $request->dist_name,

            'org_code' => $orgCode,
        ]);

        // =====================================================
        // ZOHO PAYLOAD
        // =====================================================

        $payload = [

            "contact_name" => trim(
                $request->business_name .
                ' | ' .
                $user->org_code
            ),

            "company_name" => $request->company_name,

            "contact_type" => $request->contact_type,

            "has_transaction" => true,

            // =====================================
            // GST
            // =====================================

            "gst_no" => $request->gst_no ?? "",

            "gst_treatment" => "business_gst",

            // =====================================
            // PAN
            // =====================================

            "tax_reg_no" => $request->pan ?? null,

            // =====================================
            // BILLING ADDRESS
            // =====================================

            "billing_address" => [

                "attention" => $request->owner_name,

                "address" => $request->address ?? '',

                "city" => $request->district ?? '',

                "state" => $request->state ?? '',

                "zip" => (string) $request->pincode,

                "country" => "India",

                "phone" => $request->mobile
            ],

            // =====================================
            // SHIPPING ADDRESS
            // =====================================

            "shipping_address" => [

                "attention" => $request->owner_name,

                "address" => $request->address ?? '',

                "city" => $request->district ?? '',

                "state" => $request->state ?? '',

                "zip" => (string) $request->pincode,

                "country" => "India",

                "phone" => $request->mobile
            ],

            // =====================================
            // CONTACT PERSONS
            // =====================================

            "contact_persons" => [

                [

                    "first_name" => $request->owner_name,

                    "last_name" => "",

                    "email" => strtolower(trim($request->email)),

                    "mobile" => $request->mobile,

                    "phone" => $request->mobile,

                    "is_primary_contact" => true
                ]
            ]
        ];

        \Log::info('ZOHO PAYLOAD', $payload);

        // =====================================================
        // CREATE ZOHO CONTACT
        // =====================================================

        $client = new Client();

        $response = $client->post(
            "https://www.zohoapis.in/books/v3/contacts",
            [

                'headers' => [

                    'Authorization' =>
                        'Zoho-oauthtoken ' .
                        $company->zoho_access_token,

                    'Content-Type' => 'application/json',
                ],

                'query' => [

                    'organization_id' =>
                        $company->zoho_org_id,
                ],

                'json' => $payload,
            ]
        );

        $body = json_decode(
            (string) $response->getBody(),
            true
        );

        \Log::info('ZOHO RESPONSE', $body);

        $contactData = $body['contact'] ?? null;

        // =====================================================
        // UPDATE MASTER USER
        // =====================================================

        if ($contactData) {

            $user->update([

                'zoho_contact_id' =>
                    $contactData['contact_id'] ?? null,

                'zoho_contact_json' =>
                    $contactData
            ]);

            // =====================================
            // EMI LOCKER
            // =====================================

            $products = $user->products ?? [];

            if (in_array('emi_locker', $products)) {

                DB::connection('org_uat_mysql')
                    ->table('org_users')
                    ->where('org_code', $user->org_code)
                    ->update([

                        'zoho_contact_id' =>
                            $contactData['contact_id'] ?? null,

                        'zoho_org_id' =>
                            $company->zoho_org_id,

                        'updated_at' => now()
                    ]);
            }

            // =====================================
            // WARRANTY
            // =====================================

            if (in_array('warranty', $products)) {

                Company::where(
                    'company_code',
                    $user->org_code
                )->update([

                    'zoho_contact_id' =>
                        $contactData['contact_id'] ?? null,

                    'updated_at' => now()
                ]);
            }
        }

        DB::commit();

        return response()->json([

            'status' => true,

            'message' =>
                'Contact created successfully in Zoho.',

            'data' => [

                'master_user' => [

                    'id' => $user->id,

                    'org_code' => $user->org_code,

                    'zoho_contact_id' =>
                        $contactData['contact_id'] ?? null,

                    'zoho_org_id' =>
                        $company->zoho_org_id ?? null,
                ],

                'zoho_contact' => $contactData
            ]
        ]);

    } catch (\GuzzleHttp\Exception\ClientException $e) {

        DB::rollBack();

        $errorBody = json_decode(
            $e->getResponse()
                ->getBody()
                ->getContents(),
            true
        );

        \Log::error('ZOHO ERROR', [

            'response' => $errorBody
        ]);

        return response()->json([

            'status' => false,

            'error' =>
                $errorBody['message']
                ?? $e->getMessage(),

            'full_error' => $errorBody

        ], $e->getResponse()->getStatusCode());

    } catch (\Throwable $e) {

        DB::rollBack();

        \Log::error('ZOHO CONTACT CREATE FAILED', [

            'message' => $e->getMessage(),

            'line' => $e->getLine(),

            'file' => $e->getFile(),
        ]);

        return response()->json([

            'status' => false,

            'error' => $e->getMessage()

        ], 500);
    }
}
    
    //
    public function commonLogin(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'password' => 'nullable',
        'email'    => 'sometimes|email',
        'phone'    => 'sometimes|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    // =========================
    // FIND MASTER USER
    // =========================
    $master = OrgUsersMaster::where(function ($q) use ($request) {
        if ($request->email) {
            $q->where('email', $request->email);
        }
        if ($request->phone) {
            $q->orWhere('phone', $request->phone);
        }
    })->first();

    if (!$master) {
        return response()->json([
            'status' => false,
            'message' => 'User not found'
        ], 404);
    }

    $products = $master->products ?? [];

    // =========================
    // WARRANTY LOGIN (PASSWORD)
    // =========================
    if (in_array('warranty', $products)) {

        $company = Company::where('company_code', $master->org_code)->first();

        if ($company) {

            if ((int) $company->status === 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Account inactive'
                ], 403);
            }

            if (!$request->password) {
                return response()->json([
                    'status' => false,
                    'message' => 'Password required for warranty login'
                ], 400);
            }

        

            return response()->json([
                'status'  => true,
                'type'    => 'warranty',
                'message' => 'Login successful',
                'data'    => $company
            ]);
        }
    }

    // =========================
    // EMI LOCKER LOGIN (OTP)
    // =========================
    if (in_array('emi_locker', $products)) {

        return response()->json([
            'status' => true,
            'type'   => 'emi_locker',
            'message'=> 'OTP required',
            'next'   => 'verifyOtp'
        ]);
    }

    return response()->json([
        'status' => false,
        'message' => 'No valid product assigned'
    ]);
}

   public function store(Request $request)
    {
        $data = OrgUsersMaster::updateOrCreate(
            ['email' => $request->email],
            $request->all()
        );
    
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }
}
