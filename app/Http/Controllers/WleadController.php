<?php

namespace App\Http\Controllers;

use App\Models\WLead;
use App\Models\IndiaPincode;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use App\Mail\WelcomeCompanyMail;
use App\Mail\LeadCreateMail;

use Illuminate\Support\Facades\Mail;


class WleadController extends Controller
{
    /**
     * Store a new lead/user
     */
public function store(Request $request)
{
    $validator = Validator::make($request->all(), [
        'name'        => 'required|string|max:255',
        'phone'       => 'required|string|max:20|unique:w_leads,phone',
        'email'       => 'required|email|unique:w_leads,email',
        'password'    => 'nullable',
        'created_by_id' => 'required',
        'created_by_name' => 'required',
        'owner_name'    => 'required',
        'lead_type'     => 'required',
        'manager_id'     => 'nullable',
        'agent_id'     => 'nullable',
        'pincode'       => 'required|digits:6'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    // Fetch state & district codes
    $pincodeData = IndiaPincode::where('pincode', $request->pincode)->first();

    if (!$pincodeData) {
        return response()->json([
            'status' => false,
            'message' => 'Invalid Pincode'
        ], 422);
    }

    $stateIn    = $pincodeData->state_in;
    $districtIn = $pincodeData->district_in;

    // Plain password (for email)
  
    $plainPassword = random_int(100000, 999999);
    // Create lead
    $lead = WLead::create([
        'name'            => $request->name,
        'phone'           => $request->phone,
        'state'           => $request->state,
        'district'        => $request->district,
        'pincode'         => $request->pincode,
        'email'           => $request->email,
        'address_full'    => $request->address_full,
        'status'          => $request->status ?? 1,
        'lead_amount'     => $request->lead_amount,
        'password'        => Hash::make($plainPassword), 
        'created_by_id'   => $request->created_by_id,
        'created_by_name' => $request->created_by_name,
        'owner_name'      => $request->owner_name,
        'lead_type'       => $request->lead_type,
        'package_id'      => $request->package_id,
        'package_name'    => $request->package_name,
        'badge_name'      => $request->badge_name,
        'badge_id'        => $request->badge_id,
        'benefits'        => $request->benefits,
        'eligibility'     => $request->eligibility,
        'company_id'      => $request->company_id,
        'manager_id'      => $request->manager_id,
        'agent_id'        => $request->agent_id,
        'state_in'        => $stateIn,
        'district_in'     => $districtIn,
        'formdata'        => $request->formdata,
        'form_ref'        => $request->form_ref,
        'pay_now'        =>  $request->pay_now,
        'pay_later'      =>  $request->pay_later
        
    ]);

    // Generate lead_code
    $leadCode = "{$stateIn}-{$districtIn}-{$request->pincode}-{$lead->id}";

    $lead->update([
        'lead_code' => $leadCode
    ]);

    // Send email
    $signinUrl = "https://goelectronix.com/signin?email=" . urlencode($lead->email);

    Mail::to($lead->email)
        ->send(new LeadCreateMail($lead, $signinUrl, $plainPassword));

    return response()->json([
        'status'  => true,
        'message' => 'User lead created successfully and email sent',
        'data'    => $lead
    ], 201);
}

    /**
     * Login user
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'    => 'required_without:email',
            'email'    => 'required_without:phone',
            'password' => 'required'
        ],[
            'phone.required_without'  => 'Phone or Email is required',
            'email.required_without'  => 'Email or Phone is required',
            'password.required'       => 'Password is required'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }

        $lead = WLead::where('phone', $request->phone)
                        ->orWhere('email', $request->email)
                        ->first();

        if (!$lead || !Hash::check($request->password, $lead->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid login credentials'
            ], 401);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Login successful',
            'data'    => $lead
        ]);
    }

    /**
     * Get list of all users
     */
   public function index(Request $request)
   {
    // ---------------------------------
    // BASE QUERY
    // ---------------------------------
    $baseQuery = WLead::query();

    // ---------------------------------
    // BASIC FILTERS
    // ---------------------------------
    if ($request->created_by_id) {
        $baseQuery->where('created_by_id', $request->created_by_id);
    }

    if ($request->company_id) {
        $baseQuery->where('company_id', $request->company_id);
    }

    if ($request->lead_type) {
        $baseQuery->where('lead_type', $request->lead_type);
    }


    if ($request->manager_id) {
        $baseQuery->where('manager_id', $request->manager_id);
    }
    
     if ($request->agent_id) {
        $baseQuery->where('agent_id', $request->agent_id);
    }

    // ---------------------------------
    // STATUS FILTER (single value)
    // ---------------------------------
    if ($request->status) {
        $baseQuery->where('status', $request->status);
    }
    
    if ($request->form_ref) {
        $baseQuery->where('form_ref', $request->form_ref);
    }

    // ---------------------------------
    // LOCATION FILTERS (single or comma-separated)
    // ---------------------------------
    if ($request->state) {
        $states = array_map('trim', explode(',', strtoupper($request->state)));
        $baseQuery->whereIn('state', $states);
    }

    if ($request->district) {
        $districts = array_map('trim', explode(',', strtoupper($request->district)));
        $baseQuery->whereIn('district', $districts);
    }

    if ($request->pincode) {
        $pincodes = array_map('trim', explode(',', $request->pincode));
        $baseQuery->whereIn('pincode', $pincodes);
    }

    // ---------------------------------
    // DATE FILTER
    // ---------------------------------
    if ($request->from_date && $request->to_date) {
        $baseQuery->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    // ---------------------------------
    // SUMMARY (FILTERED, NOT PAGINATED)
    // ---------------------------------
    $totalLeads = (clone $baseQuery)->count();

    $newCount       = (clone $baseQuery)->where('status', 'new')->count();
    $inProcessCount = (clone $baseQuery)->where('status', 'in process')->count();
    $wonCount       = (clone $baseQuery)->where('status', 'won')->count();
    $lostCount      = (clone $baseQuery)->where('status', 'lost')->count();

    $statusAmounts = [
        'new'        => (clone $baseQuery)->where('status', 'new')->sum('lead_amount'),
        'in_process' => (clone $baseQuery)->where('status', 'in process')->sum('lead_amount'),
        'won'        => (clone $baseQuery)->where('status', 'won')->sum('lead_amount'),
        'lost'       => (clone $baseQuery)->where('status', 'lost')->sum('lead_amount'),
    ];

    $leadTypeCounts = [
        'type_2' => (clone $baseQuery)->where('lead_type', 2)->count(),
        'type_4' => (clone $baseQuery)->where('lead_type', 4)->count(),
        'type_5' => (clone $baseQuery)->where('lead_type', 5)->count(),
    ];

    $totalLeadAmount = (clone $baseQuery)->sum('lead_amount');

    $conversionRate = $totalLeads > 0
        ? round(($wonCount / $totalLeads) * 100, 2)
        : 0;

    // ---------------------------------
    // PAGINATION
    // ---------------------------------
    $perPage = $request->per_page ?? 10;

    $leads = (clone $baseQuery)
        ->orderBy('id', 'desc')
        ->paginate($perPage);

    // ---------------------------------
    // RESPONSE
    // ---------------------------------
    return response()->json([
        'status'  => true,
        'message' => 'Lead list fetched successfully',
        'summary' => [
            'total_leads'       => $totalLeads,
            'status_counts'     => [
                'new'        => $newCount,
                'in_process' => $inProcessCount,
                'won'        => $wonCount,
                'lost'       => $lostCount,
            ],
            'status_amounts'    => $statusAmounts,
            'lead_type_counts'  => $leadTypeCounts,
            'total_lead_amount' => $totalLeadAmount,
            'conversion_rate'   => $conversionRate . '%'
        ],
        'pagination' => [
            'current_page' => $leads->currentPage(),
            'per_page'     => $leads->perPage(),
            'last_page'    => $leads->lastPage(),
            'total'        => $leads->total()
        ],
        'data' => $leads->items()
    ]);
}
    /**
     * Update status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required',
            'remark' => 'nullable|string',
            'updated_by_id' => 'nullable|integer',
            'updated_by_name' => 'nullable|string'
        ],[
            'status.required' => 'Status is required'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        $lead = WLead::find($id);
    
        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }
    
        // Update fields
        $lead->status = $request->status;
    
        if ($request->has('remark')) {
            $lead->remark = $request->remark;
        }
    
        // Optional update_by fields
        if ($request->has('updated_by_id')) {
            $lead->updated_by_id = $request->updated_by_id;
        }
    
        if ($request->has('updated_by_name')) {
            $lead->updated_by_name = $request->updated_by_name;
        }
    
        $lead->save();
    
        return response()->json([
            'status'  => true,
            'message' => 'Status updated successfully',
            'data'    => $lead
        ]);
    }

    
    public function sendWelcomeEmail($companyId)
    {
        $company = Company::findOrFail($companyId);
    
        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
    
        // Store OTP in DB
        $company->update([
            'otp' => $otp
        ]);
    
        $signinUrl = "https://goelectronix.com/signin?email=" . urlencode($company->contact_email);
    
        // Send Email with OTP
        Mail::to($company->contact_email)->send(new WelcomeCompanyMail($company, $signinUrl));
    
        return response()->json([
            "status" => true,
            "message" => "Welcome email & OTP sent successfully"
        ]);
    }

    
    public function verifyEmailOtp(Request $request)
    {
        $request->validate([
            'email'      => 'required|email',
            'otp'        => 'required|digits:6',
            'user_id'    => 'required|exists:companies,id',
            'company_id' => 'required|exists:companies,id',
        ]);
    
        $company = Company::where('contact_email', $request->email)
            ->where('id', $request->user_id)
            ->first();
    
        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found'
            ], 404);
        }
    
        if ($company->otp != $request->otp) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP'
            ], 400);
        }
    
        // ✅ Mark email verified
        $company->update([
            'otp' => null,
            'is_mail_verified' => 1
        ]);
    
        // ✅ Call Zoho creation after verification
        return $this->createContactFromCompany(
            new Request([
                'user_id'    => $request->user_id,
                'company_id' => $request->company_id
            ])
        );
    }
    public function createContactFromCompany(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'user_id'    => 'required|exists:companies,id',
        'company_id' => 'required|exists:companies,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    /**
     * 🔐 Zoho credential owner
     */
    $zohoCompany = Company::find($request->company_id);

    if (
        !$zohoCompany ||
        !$zohoCompany->zoho_access_token ||
        !$zohoCompany->zoho_org_id
    ) {
        return response()->json([
            'status' => false,
            'error'  => 'Zoho credentials not found.'
        ], 400);
    }

    /**
     * 🏢 Company whose contact will be created in Zoho
     */
    $company = Company::find($request->user_id);

    if (!$company) {
        return response()->json([
            'status' => false,
            'error'  => 'Company data not found.'
        ], 404);
    }

    // 🚫 Prevent duplicate Zoho contact
    if ($company->zoho_id) {
        return response()->json([
            'status'  => false,
            'message' => 'Zoho contact already exists'
        ], 409);
    }

    /**
     * 🧱 Build Zoho payload
     */
    $payload = [
        "contact_name"    => $company->business_name,
        "company_name"    => $company->trade_name ?? $company->business_name,
        "has_transaction" => true,
        "contact_type"    => "customer",

        "billing_address" => [
            "attention" => $company->contact_person,
            "address"   => $company->address_line1,
            "street2"   => $company->address_line2,
            "city"      => $company->city,
            "state"     => $company->state,
            "zip"       => $company->pincode,
            "country"   => "India",
            "phone"     => $company->contact_phone,
        ],

        "contact_persons" => [
            [
                "first_name" => $company->contact_person,
                "email"      => $company->contact_email,
                "phone"      => $company->contact_phone,
            ]
        ],
    ];

    if ($company->gst) {
        $payload['gst_no'] = $company->gst;
        $payload['gst_treatment'] = 'business_gst';
    }

    $client = new \GuzzleHttp\Client();

    try {
        $response = $client->post(
            "https://www.zohoapis.in/books/v3/contacts",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $zohoCompany->zoho_access_token,
                    'Content-Type'  => 'application/json',
                ],
                'query' => [
                    'organization_id' => $zohoCompany->zoho_org_id,
                ],
                'json' => $payload,
            ]
        );

        $body = json_decode($response->getBody(), true);

        if (!empty($body['contact']['contact_id'])) {
            $company->update([
                'zoho_id' => $body['contact']['contact_id'],
                'z_json'  => json_encode($body['contact']),
            ]);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Zoho contact created successfully',
            'zoho_id' => $body['contact']['contact_id'],
        ], 200);

    } catch (\GuzzleHttp\Exception\ClientException $e) {
        $errorBody = json_decode(
            $e->getResponse()->getBody()->getContents(),
            true
        );

        return response()->json([
            'status' => false,
            'error'  => $errorBody['message'] ?? $e->getMessage(),
        ], $e->getResponse()->getStatusCode());
    }
}
public function yearMonthReport(Request $request)
{
    $query = WLead::query();

    // ---------------------------------
    // FILTERS (same as index)
    // ---------------------------------
    if ($request->created_by_id) {
        $query->where('created_by_id', $request->created_by_id);
    }

    if ($request->company_id) {
        $query->where('company_id', $request->company_id);
    }
    
    if ($request->manager_id) {
        $query->where('manager_id', $request->manager_id);
    }

    if ($request->lead_type) {
        $query->where('lead_type', $request->lead_type);
    }

    if ($request->from_date && $request->to_date) {
        $query->whereBetween('created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ]);
    }

    // ---------------------------------
    // GROUP BY YEAR & MONTH
    // ---------------------------------
    $rawData = $query
        ->selectRaw("
            YEAR(created_at) as year,
            MONTH(created_at) as month,
            COUNT(*) as total_leads,
            SUM(lead_amount) as total_amount,
            SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_count,
            SUM(CASE WHEN status = 'in process' THEN 1 ELSE 0 END) as in_process_count,
            SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_count,
            SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_count
        ")
        ->groupByRaw('YEAR(created_at), MONTH(created_at)')
        ->orderByRaw('YEAR(created_at) DESC, MONTH(created_at) DESC')
        ->get();

    // ---------------------------------
    // FORMAT RESPONSE
    // ---------------------------------
    $result = [];

    foreach ($rawData as $row) {
        $conversionRate = $row->total_leads > 0
            ? round(($row->won_count / $row->total_leads) * 100, 2)
            : 0;

        $result[] = [
            'year'  => $row->year,
            'month' => $row->month,
            'month_name' => date('F', mktime(0, 0, 0, $row->month, 1)),

            'total_leads' => $row->total_leads,
            'total_lead_amount' => (float) $row->total_amount,

            'status_counts' => [
                'new'        => (int) $row->new_count,
                'in_process' => (int) $row->in_process_count,
                'won'        => (int) $row->won_count,
                'lost'       => (int) $row->lost_count,
            ],

            'conversion_rate' => $conversionRate . '%'
        ];
    }

    return response()->json([
        'status'  => true,
        'message' => 'Year month wise lead report',
        'data'    => $result
    ]);
}
}