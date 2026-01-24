<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Agent;
use App\Models\Retailer;
use App\Models\WLead;
use App\Models\IndiaPincode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\UserFile;
use GuzzleHttp\Client;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Jobs\SendCompanyCreatedWhatsapp;
use App\Jobs\SendAgentPendingWhatsapp;
use App\Models\CompanyApiLog;

class CommonUpdateController extends Controller
{
  
    public function updateOrCreate(Request $request)
   {

    $validator = Validator::make($request->all(), [
        'contact_email' => 'required|email',
        'pincode'       => 'required|digits:6',
        'role'          => 'required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    // =========================
    // FIND COMPANY BY EMAIL
    // =========================
    $user = Company::where('contact_email', $request->contact_email)->first();
    //SendAgentPendingWhatsapp::dispatch($user->id);
    // =========================
    // COMMON DATA
    // =========================
    $data = $request->only([
        'business_name', 'contact_person', 'contact_phone', 'contact_email',
        'address_line1', 'address_line2', 'city', 'state', 'district', 'pincode',
        'status', 'pan', 'gst', 'business_type', 'is_verified', 'is_payment_success',
        'trade_name', 'account_no', 'ifsc_code', 'bank_name', 'branch_name',
        'role', 'esign_verified', 'company_id', 'account_type',
        'pan_verified', 'pan_json',
        'zoho_access_token', 'zoho_org_id', 'zoho_client_id',
        'zoho_client_secret', 'zoho_redirect_uri',
        'owner_first_name',
        'owner_middle_name',
        'owner_last_name',
        'owner_email',
        'owner_contact', "password", "gst_json", "bank_json",
        "bank_verified",
        "gst_verified", "agent_code", "zoho_id", "agent_id", 
        "pay_now", "pay_later", "logo", "domain",
        "created_by_name",
        "created_by_id"
    ]);

    // =========================
    // UPDATE EXISTING COMPANY
    // =========================
    if ($user) {
        $user->update($data);

                // Update lead package details if provided
        $updateData = [];
        
        /* ================= Package update ================= */
        if ($request->filled('package_id')) {
            $updateData = [
                'package_id'   => $request->package_id,
                'package_name' => $request->package_name,
                'badge_name'   => $request->badge_name,
                'badge_id'     => $request->badge_id,
                'benefits'     => $request->benefits,
                'eligibility'  => $request->eligibility,
                'lead_amount'  => $request->lead_amount,
            ];
        }
        
        /* ================= Status update ================= */
        if ((int) $request->is_verified === 7) {
            $updateData['status'] = 'won';
        }
        
        /* ================= Apply update ================= */
        if (!empty($updateData)) {
            WLead::where('email', $request->contact_email)
                ->update($updateData);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Company updated successfully',
            'data'    => $user->refresh()
        ], 200);
    }

    // =========================
    // CREATE NEW COMPANY
    // =========================

    // Fetch state_in & district_in from pincode
    $pincodeData = IndiaPincode::where('pincode', $request->pincode)->first();

    if (!$pincodeData) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid pincode'
        ], 422);
    }

    $stateIn    = $pincodeData->state_in;
    $districtIn = $pincodeData->district_in;



    $leaddata = WLead::where('email', $request->contact_email)->first();
    // Set defaults
   
    if($request->role==6)
    {
           $data['password']    = Hash::make($request->password);
    }
    else
    {
        $data['password']    = $leaddata->password ??  Hash::make("123456");
    }
    
    $data['status']      = $request->status ?? 1;
    $data['is_verified'] = $request->is_verified ?? 0;
    $data['senior_id'] =   $request->senior_id ?? 0;
    $data['agent_code'] =  $request->agent_code ?? 0;

    // Create company
    $user = Company::create($data);

    // =========================
    // GENERATE USER CODE (ONLY ON CREATE)
    // =========================
    switch ($user->role) {
        case 5: // Retailer
            $userCode = "RET-{$user->id}-{$stateIn}-{$districtIn}";
             
            break;

        case 4: // Agent
            $userCode = "AGT-{$user->id}-{$stateIn}-{$districtIn}";
               SendAgentPendingWhatsapp::dispatch($user->id);
            break;

        case 3: // CPE
            $userCode = "CPE-{$user->id}-{$stateIn}-{$districtIn}";
            break;

        case 2: // Company
            $userCode = "COMP{$user->id}-{$stateIn}-{$districtIn}";
             SendCompanyCreatedWhatsapp::dispatch($user->id);
            break;

        default:
            $userCode = "USR-{$user->id}-{$stateIn}-{$districtIn}";
            break;
    }

    $user->update([
        'company_code' => $userCode
    ]);


    // =========================
    // UPDATE WLEAD PACKAGE (IF EXISTS)
    // =========================
    if ($request->filled('package_id')) {
        WLead::where('email', $request->contact_email)->update([
            'package_id'   => $request->package_id,
            'package_name' => $request->package_name,
            'badge_name'   => $request->badge_name,
            'badge_id'     => $request->badge_id,
            'benefits'     => $request->benefits,
            'eligibility'  => $request->eligibility,
            'lead_amount'  => $request->lead_amount
        ]);
    }

    return response()->json([
        'status'  => true,
        'message' => 'Company created successfully and credentials emailed',
        'data'    => $user->refresh()
    ], 201);
}
    private function generateCode($prefix, $model, $column)
    {
        $last = $model::orderBy('id','desc')->first();
        if (!$last) return $prefix . "0001";

        $num = intval(substr($last->$column, strlen($prefix))) + 1;
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
        
    public function getCompanies(Request $request)
    {
        $query = Company::query();
    
        // ---------------------------------
        // EXACT FILTERS
        // ---------------------------------
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }
    
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
         if ($request->filled('id')) {
            // If company_id is PRIMARY KEY
            $query->where('id', $request->id);
        }
        
         if ($request->filled('agent_code')) {
            $query->where('agent_code', $request->agent_code);

        }
        
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }
        
         if ($request->filled('senior_id')) {
            $query->where('senior_id', $request->senior_id);
        }
        
        if ($request->filled('created_by_id')) {
            $query->where('created_by_id', $request->created_by_id);
        }
    
        if ($request->filled('created_by_name')) {
            $query->where('created_by_name', $request->created_by_name);
        }
    
        // ---------------------------------
        // GLOBAL SEARCH
        // ---------------------------------
        if ($request->filled('search_value')) {
            $search = $request->search_value;
    
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%")
                  ->orWhere('contact_email', 'LIKE', "%{$search}%")
                  ->orWhere('company_code', 'LIKE', "%{$search}%");
            });
        }
    
        // ---------------------------------
        // SORTING (SAFE)
        // ---------------------------------
        $sortBy = $request->sort_by ?? 'id';
        $sortOrder = strtoupper($request->sort_order ?? 'DESC');
    
        $allowedSorts = [
            'id',
            'company_name',
            'business_name',
            'created_at',
            'company_id'
        ];
    
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }
    
        $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';
    
        $query->orderBy($sortBy, $sortOrder);
    
        // ---------------------------------
        // PAGINATION
        // ---------------------------------
        $perPage = $request->per_page ?? 10;
        $results = $query->paginate($perPage);
    
        return response()->json([
            'status'  => true,
            'message' => 'Filtered company list fetched successfully',
            'pagination' => [
                'current_page' => $results->currentPage(),
                'per_page'     => $results->perPage(),
                'last_page'    => $results->lastPage(),
                'total'        => $results->total()
            ],
            'data' => $results->items()
        ]);
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'file'  => 'required|file|mimes:jpg,jpeg,png,pdf,doc,docx,webp|max:2048',
            'flag'  => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // store the file
        $path = $request->file('file')->store('userfiles', 'public');

        // insert record
        $fileRecord = UserFile::create([
            'email' => $request->email,
            'file_url' => $path,
            'flag' => $request->flag,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'File uploaded successfully',
            'data' => [
                'id' => $fileRecord->id,
                'email' => $request->email,
                'file_url' => asset('storage/' . $path)
            ]
        ], 201);
    }

    // Get files by email
    public function getFilesByEmail($email)
    {
        $files = UserFile::where('email', $email)->get();

        if ($files->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No files found for this email'
            ], 404);
        }

        // Map urls
        $files->transform(function ($item) {
            $item->full_url = asset('storage/' . $item->file_url);
            return $item;
        });

        return response()->json([
            'status' => true,
            'message' => 'Files fetched successfully',
            'data' => $files
        ]);
    }
    
    
    public function createZohoAccount(Request $request)
    {
          
        $zohoUser = Company::where('company_id', $request->company_id)
            ->whereNotNull('zoho_access_token')
            ->first();

        if (!$zohoUser || !$zohoUser->zoho_access_token || !$zohoUser->zoho_org_id) {
            return response()->json([
                'status' => false,
                'error' => 'Zoho credentials not found for this organization.',
                'user' => $user
            ], 400);
        }

        $billingAddress = [
            "attention" => $request->contact_person,
            "address"   => $request->billing_address['address'] ?? $request->address ?? '',
            "city"      => $request->billing_address['city'] ?? $request->city ?? '',
            "state"     => $request->billing_address['state'] ?? $request->state ?? '',
            "zip"       => $request->billing_address['zip'] ?? $request->pincode ?? '',
            "country"   => "India",
            "phone"     => $user->mobile,
        ];
        
        $shippingAddress = [
            "attention" => $request->contact_person,
            "address"   => $request->shipping_address['address'] ?? $request->address ?? '',
            "city"      => $request->shipping_address['city'] ?? $request->city ?? '',
            "state"     => $request->shipping_address['state'] ?? $request->state ?? '',
            "zip"       => $request->shipping_address['zip'] ?? $request->pincode ?? '',
            "country"   => "India",
            "phone"     => $user->mobile,
        ];

        $payload = [
            "contact_name"     => $user->business_name . ' ' . $user->org_code,
            "company_name"     => $user->business_name,
            "has_transaction"  => true,
            "contact_type"     => "customer",
            "contact_persons"  => [
                [
                    "first_name" => $user->owner_name ?? $user->business_name,
                    "email"      => $user->email,
                    "mobile"     => $user->mobile,
                    "is_primary_contact" => true
                ]
            ],
            "billing_address"  => $billingAddress,
            "shipping_address" => $shippingAddress,
        ];

        if ($request->filled('gst_no')) $payload['gst_no'] = $request->gst_no;

        try {
            $client = new Client();

            $response = $client->post("https://www.zohoapis.in/books/v3/contacts", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $zohoUser->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => ['organization_id' => $zohoUser->zoho_org_id],
                'json' => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $contactData = $body['contact'] ?? null;

            if ($contactData) {
                ZohoUser::create([
                    'z_json'     => json_encode($contactData),
                    'contact_id' => $contactData['contact_id'],
                    'org_id'     => $zohoUser->zoho_org_id,
                ]);
            }

            $fetchResponse = $client->get("https://www.zohoapis.in/books/v3/contacts", [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $zohoUser->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $zohoUser->zoho_org_id,
                    'email' => strtolower(trim($user->email)),
                ],
            ]);

            $fetchBody = json_decode((string) $fetchResponse->getBody(), true);

            if (isset($fetchBody['code']) && $fetchBody['code'] == 0 && !empty($fetchBody['contacts'])) {
                $contact = $fetchBody['contacts'][0];
                $user->update([
                    'zoho_contact_id' => $contact['contact_id'],
                    'zoho_org_id'     => $zohoUser->zoho_org_id,
                ]);
            }

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = json_decode($e->getResponse()->getBody()->getContents(), true);
            return response()->json([
                'status' => false,
                'error' => $errorBody['message'] ?? $e->getMessage(),
                'user' => $user 
            ], $e->getResponse()->getStatusCode());
        }
    
    }
    
  
    public function generateUserCode(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'email'   => 'required|email|exists:companies,contact_email',
        'pincode' => 'required|digits:6'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation failed',
            'errors'  => $validator->errors()
        ], 422);
    }

    // Get company
    $company = Company::where('contact_email', $request->email)->first();

    if (!$company) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    // Get pincode → fetch state_in + district_in
    $pincodeData = IndiaPincode::where('pincode', $request->pincode)->first();

    if (!$pincodeData) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid pincode'
        ], 404);
    }

    $stateIn = $pincodeData->state_in;
    $districtIn = $pincodeData->district_in;

    // Generate user code
    switch ($company->role) {
        case 5: // Retailer
            $userCode = "RET-" . $company->company_id . "-" . $stateIn . "-" . $districtIn;
            break;

        case 4: // Agent
            $userCode = "AGT-" . $company->company_id . "-" . $stateIn . "-" . $districtIn;
            break;

        case 3: // CPE
            $userCode = "CPE-" . $company->company_id . "-" . $stateIn . "-" . $districtIn;
            break;

        case 2: // Company
            $userCode = "COMP" . $company->id . "-" . $stateIn . "-" . $districtIn;
            break;

        default:
            $userCode = "USR-" . $company->id . "-" . $stateIn . "-" . $districtIn;
            break;
    }

    // Save
    $company->update([
        'user_code' => $userCode
    ]);

    return response()->json([
        'status'     => true,
        'message'    => 'User code generated successfully',
        'user_code'  => $userCode
    ]);
}

public function updateDynamicFieldsCompany(Request $request, $id)
{
    $company = Company::findOrFail($id);

    // Only allow fillable fields
    $data = $request->only($company->getFillable());

    // Update company
    $company->update($data);

    // 🔹 LOG API CALL
    CompanyApiLog::create([
        'company_id' => $company->id,
        'api_name'   => 'updateDynamicFieldsCompany',
        'method'     => $request->method(),
        'url'        => $request->fullUrl(),
        'payload'    => $request->all(),
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json([
        'status'  => true,
        'message' => 'Company updated successfully',
        'data'    => $company
    ]);
}
public function getCompanyApiLogs(Request $request, $companyId)
{
    $perPage = $request->get('per_page', 10);

    $logs = CompanyApiLog::where('company_id', $companyId)
        ->orderBy('id', 'desc')
        ->paginate($perPage);

    return response()->json([
        'status' => true,
        'data'   => $logs
    ]);
}
}