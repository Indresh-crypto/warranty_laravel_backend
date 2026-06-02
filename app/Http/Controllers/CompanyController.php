<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyEmployee;
use App\Models\TemplateImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Password;
use Carbon\Carbon;
use App\Models\WDevice;
use DB;
use App\Jobs\AdvancePaymentJob;
class CompanyController extends Controller
{
    /**
     * Add a company
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'company_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|unique:companies,contact_phone',
            'contact_email' => 'required|email|unique:companies,contact_email',
            'password' => 'required|min:6',
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::create([
            'company_name' => $request->company_name,
            'contact_person' => $request->contact_person,
            'contact_phone' => $request->contact_phone,
            'contact_email' => $request->contact_email,
            'password' => Hash::make($request->password),
            'address_line1' => $request->address_line1,
            'address_line2' => $request->address_line2,
            'city'          => $request->city,
            'state'         => $request->state,
            'district'      => $request->district,
            'pincode'       => $request->pincode,
            'pan'           => $request->pan,
            'gst'           => $request->gst,
            'business_type' => $request->business_type,
            'color'         => $request->color,
            'favicon'       => $request->favicon,
            'title'         => $request->title,
            'status' => 1
        ]);

        return response()->json([
            'status'=>true,
            'message'=>'Company created successfully',
            'data'=>$company
        ],201);
    }


    public function login(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'contact_email' => 'required_without:contact_phone',
            'contact_phone' => 'required_without:contact_email',
            'password'=>'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::where('contact_email',$request->contact_email)
                   ->orWhere('contact_phone',$request->contact_phone)
                   ->first();

        if(!$company || !Hash::check($request->password,$company->password)){
            return response()->json([
                'status'=>false,
                'message'=>'Invalid credentials'
            ],401);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Login successful',
            'data'=>$company
        ]);
    }

    /**
     * Get single company details
     */
    public function getCompany($id)
    {
        $company = Company::find($id);

        if(!$company){
            return response()->json([
                'status'=>false,
                'message'=>'Company not found'
            ],404);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Company details',
            'data'=>$company
        ]);
    }

    /**
     * List all companies
     */
     public function list(Request $request)
    {
        $perPage = $request->per_page ?? 10;
    
        $companies = Company::query()
    
            ->when($request->business_name, function ($q) use ($request) {
                $q->where('business_name', 'like', '%' . $request->business_name . '%');
            })
    
            ->when($request->id, function ($q) use ($request) {
                $q->where('id', $request->id);
            })
    
            // STATE (comma separated)
            ->when($request->state, function ($q) use ($request) {
                $states = array_map('trim', explode(',', $request->state));
                $q->whereIn('state', $states);
            })
    
            // DISTRICT (comma separated)
            ->when($request->district, function ($q) use ($request) {
                $districts = array_map('trim', explode(',', $request->district));
                $q->whereIn('district', $districts);
            })
    
            // PINCODE (comma separated)
            ->when($request->pincode, function ($q) use ($request) {
                $pincodes = array_map('trim', explode(',', $request->pincode));
                $q->whereIn('pincode', $pincodes);
            })
    
            ->when($request->city, function ($q) use ($request) {
                $q->where('city', $request->city);
            })
    
            ->when($request->role, function ($q) use ($request) {
                $q->where('role', $request->role);
            })
    
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
          
          ->when($request->domain, function ($q) use ($request) {
            
                $q->where('domain', $request->domain)
            
                  ->where('role', 1);
            
            })

    
            ->when($request->is_verified, function ($q) use ($request) {
                $q->where('is_verified', $request->is_verified);
            })
    
            ->when($request->company_code, function ($q) use ($request) {
                $q->where('company_code', $request->company_code);
            })
    
            ->when($request->user_type, function ($q) use ($request) {
                $q->where('user_type', $request->user_type);
            })
    
            ->when($request->flag, function ($q) use ($request) {
                $q->where('flag', $request->flag);
            })
            
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    
        return response()->json([
            'status'  => true,
            'message' => 'Companies list',
            'pagination' => [
                'current_page' => $companies->currentPage(),
                'per_page'     => $companies->perPage(),
                'last_page'    => $companies->lastPage(),
                'total'        => $companies->total(),
            ],
            'data' => $companies->items()
        ]);
    }
    /**
     * Activate / Deactivate Company
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(),[
            'status'=>'required|in:0,1'
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $company = Company::find($id);

        if(!$company){
            return response()->json([
                'status'=>false,
                'message'=>'Company not found'
            ],404);
        }

        $company->status = $request->status;
        $company->save();

        return response()->json([
            'status'=>true,
            'message'=>'Status updated successfully',
            'data'=>$company
        ]);
    }
    
    public function byPincode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pincode' => 'required|digits:6'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $retailers = Company::where('role', 5)
            ->where('pincode', $request->pincode)
            ->where('status', 1)
            ->select(
                'id',
                'business_name',
                'address_line1',
                'address_line2',
                'city',
                'state',
                'district',
                'pincode',
                'contact_phone'
            )
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $retailers
        ]);
    }
   public function update(Request $request, $id)
   {
    $company = Company::find($id);

    if (!$company) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'company_name'   => 'sometimes|required|string|max:255',
        'contact_phone'  => 'sometimes|required|string|unique:companies,contact_phone,' . $company->id,
        'contact_email'  => 'sometimes|required|email|unique:companies,contact_email,' . $company->id,
        'password'       => 'sometimes|required|min:6',

        'contact_person' => 'sometimes|nullable|string|max:255',
        'address_line1'  => 'sometimes|nullable|string',
        'address_line2'  => 'sometimes|nullable|string',
        'city'           => 'sometimes|nullable|string',
        'state'          => 'sometimes|nullable|string',
        'district'       => 'sometimes|nullable|string',
        'pincode'        => 'sometimes|nullable|string',
        'pan'            => 'sometimes|nullable|string',
        'gst'            => 'sometimes|nullable|string',
        'business_type'  => 'sometimes|nullable|string',
        'color'          => 'sometimes|nullable|string',
        'favicon'        => 'sometimes|nullable|string',
        'title'          => 'sometimes|nullable|string',
        'status'         => 'sometimes|in:0,1',
        'is_password_changed' => 'sometimes|nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors()
        ], 422);
    }

    /**
     * Update only non-null request values
     */
    $data = $request->only([
        'company_name',
        'contact_person',
        'contact_phone',
        'contact_email',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'district',
        'pincode',
        'pan',
        'gst',
        'business_type',
        'color',
        'favicon',
        'title',
        'status',
        'is_password_changed'
    ]);

    // Remove null values
    $data = array_filter($data, fn ($value) => !is_null($value));

    // Handle password separately
    if ($request->filled('password')) {
        $data['password'] = Hash::make($request->password);
    }

    $company->update($data);

    return response()->json([
        'status' => true,
        'message' => 'Company updated successfully',
        'data' => $company->fresh()
    ], 200);
}

public function sendResetLink(Request $request)
{
    $request->validate([
        'email' => 'required|email|exists:companies,contact_email'
    ]);

    $status = Password::broker('companies')->sendResetLink([
        'contact_email' => $request->email   // MUST MATCH COLUMN
    ]);

    return response()->json([
        'success' => $status === Password::RESET_LINK_SENT,
        'message' => __($status)
    ]);
}
    // 🔑 Reset password
  public function resetPassword(Request $request)
{
    $request->validate([
        'contact_email' => 'required|email|exists:companies,contact_email',
        'token'         => 'required',
        'password'      => 'required|min:8|confirmed',
    ]);

    $status = Password::broker('companies')->reset(
        $request->only('contact_email', 'password', 'password_confirmation', 'token'),
        function ($company, $password) {
            $company->password = Hash::make($password);
            $company->is_password_changed = 1;
            $company->save();
        }
    );

    return response()->json([
        'success' => $status === Password::PASSWORD_RESET,
        'message' => __($status)
    ]);
}

public function dashboardCounts(Request $request)
{
    $companyId = $request->get('company_id');

    /* ----------------------------
     | VALIDATE COMPANY (OPTIONAL)
     -----------------------------*/
    if ($companyId && !Company::where('id', $companyId)->exists()) {
        return response()->json([
            'status' => false,
            'message' => 'Company not found'
        ], 404);
    }

    /* ----------------------------
     | SHOPS / STATES / DISTRICTS
     | role = 5 (Retailer / Shop)
     -----------------------------*/
    $shopQuery = Company::where('role', 5);

    $totalShops = (clone $shopQuery)->count('id');

    $stateCount = (clone $shopQuery)
        ->whereNotNull('state')
        ->distinct('state')
        ->count('state');

    $districtCount = (clone $shopQuery)
        ->whereNotNull('district')
        ->distinct('district')
        ->count('district');

    /* ----------------------------
     | EMPLOYEE COUNTS
     -----------------------------*/
    $employeeCounts = CompanyEmployee::query()
        ->when($companyId, function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        })
        ->selectRaw("
            COUNT(*) as total_employees,
            COUNT(CASE WHEN type_of_user = 'Sales' THEN 1 END) as sales_count,
            COUNT(CASE WHEN type_of_user = 'Operation' THEN 1 END) as operation_count
        ")
        ->first();

    /* ----------------------------
     | FINAL RESPONSE
     -----------------------------*/
    return response()->json([
        'status' => true,
        'data' => [
            'total_shops'     => (int) $totalShops,
            'states'          => (int) $stateCount,
            'districts'       => (int) $districtCount,

            'employees' => [
                'total'      => (int) $employeeCounts->total_employees,
                'sales'      => (int) $employeeCounts->sales_count,
                'operation'  => (int) $employeeCounts->operation_count,
            ]
        ]
    ]);
}

public function syncZohoWalletBalance(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required', // Parent
        'user_id'    => 'required|exists:companies,id', // Child (wallet owner)
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    /**
     * 🔹 Parent company (Zoho credentials holder)
     */
    $request->company_id = 1;
    $parentCompany = Company::find($request->company_id);

    if (
        !$parentCompany ||
        !$parentCompany->zoho_access_token ||
        !$parentCompany->zoho_org_id
    ) {
        return response()->json([
            'status' => false,
            'error' => 'Parent Zoho credentials not found.'
        ], 400);
    }

    /**
     * 🔹 Child company (wallet to update)
     */
    $userCompany = Company::find($request->user_id);

    if (!$userCompany || !$userCompany->zoho_id) {
        return response()->json([
            'status' => false,
            'error' => 'User company Zoho contact ID missing.'
        ], 400);
    }

    $client = new \GuzzleHttp\Client();

    try {

        $response = $client->get(
            "https://www.zohoapis.in/books/v3/contacts/{$userCompany->zoho_id}",
            [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken ' . $parentCompany->zoho_access_token,
                ],
                'query' => [
                    'organization_id' => $parentCompany->zoho_org_id,
                 
                ],
            ]
        );

        $body = json_decode($response->getBody(), true);
        $zohoContact = $body['contact'] ?? null;

        if (!$zohoContact) {
            return response()->json([
                'status' => false,
                'error' => 'Contact not found in Zoho.'
            ], 404);
        }

        //  Get unused credits
        $walletBalance = $zohoContact['unused_credits_receivable_amount'] ?? 0;

        // Update USER company wallet (NOT parent)
        $userCompany->update([
            'wallet_balance' => $walletBalance,
            'last_update_balance_at' => now()
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Wallet balance synced successfully.',
            'user_id' => $userCompany->id,
            'wallet_balance' => $walletBalance
        ]);

    } catch (\GuzzleHttp\Exception\ClientException $e) {

        $errorBody = json_decode(
            $e->getResponse()->getBody()->getContents(),
            true
        );

        return response()->json([
            'status' => false,
            'error' => $errorBody['message'] ?? $e->getMessage(),
        ], $e->getResponse()->getStatusCode());
    }
}

     public function syncCompanyBalances()
    {
        $client = new Client();

        $companies = Company::whereNotNull('zoho_access_token')
            ->whereNotNull('zoho_org_id')
            ->get();

        foreach ($companies as $company) {

            try {

                /*
                |--------------------------------------------------------------------------
                | FETCH RECEIVABLES SUMMARY
                |--------------------------------------------------------------------------
                */

                $response = $client->get(
                    "https://www.zohoapis.in/books/v3/reports/receivablesummary",
                    [
                        'headers' => [
                            'Authorization' =>
                                'Zoho-oauthtoken ' . $company->zoho_access_token
                        ],
                        'query' => [
                            'organization_id' => $company->zoho_org_id
                        ]
                    ]
                );

                $body = json_decode($response->getBody(), true);

                $totalReceivable = $body['report']['total'] ?? 0;

                /*
                |--------------------------------------------------------------------------
                | FETCH UNUSED CREDIT NOTES
                |--------------------------------------------------------------------------
                */

                $creditResponse = $client->get(
                    "https://www.zohoapis.in/books/v3/creditnotes",
                    [
                        'headers' => [
                            'Authorization' =>
                                'Zoho-oauthtoken ' . $company->zoho_access_token
                        ],
                        'query' => [
                            'organization_id' => $company->zoho_org_id,
                            'filter_by' => 'Status.Unused'
                        ]
                    ]
                );

                $creditBody = json_decode($creditResponse->getBody(), true);

                $unusedCredit = collect($creditBody['creditnotes'] ?? [])
                    ->sum('balance');

                /*
                |--------------------------------------------------------------------------
                | UPDATE COMPANY TABLE
                |--------------------------------------------------------------------------
                */

                $company->update([
                    'zoho_receivable_balance'     => $totalReceivable,
                    'zoho_unused_credit_balance'  => $unusedCredit,
                    'zoho_last_sync_at'           => now()
                ]);

            } catch (\Exception $e) {

                Log::error('Zoho Balance Sync Failed', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage()
                ]);

                continue; // move to next company
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Zoho balances synced successfully'
        ]);
    }
    
     // Add Template Image
    public function storeTemplateImage(Request $request)
    {
        $request->validate([
            'link' => 'required|string',
            'tag' => 'nullable|string',
            'company_id' => 'required|integer'
        ]);
    
        $image = TemplateImage::create([
            'link' => $request->link,
            'tag' => $request->tag,
            'company_id' => $request->company_id,
            'status' => 1
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Template Image Added Successfully',
            'data' => $image
        ]);
    }


    public function updateTemplateImageStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:0,1'
        ]);
    
        $image = TemplateImage::find($id);
    
        if (!$image) {
            return response()->json([
                'status' => false,
                'message' => 'Template image not found'
            ], 404);
        }
    
        $image->update([
            'status' => $request->status
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Template image status updated successfully',
            'data' => $image
        ]);
}

    // Get All Images by Company
    public function getTemplateImage($company_id)
    {
        $images = TemplateImage::where('company_id', $company_id)->get();

        return response()->json([
            'status' => true,
            'data' => $images
        ]);
    }
    
    public function deleteTemplateImage($id)
    {
        $image = TemplateImage::find($id);
    
        if (!$image) {
            return response()->json([
                'status' => false,
                'message' => 'Template image not found'
            ], 404);
        }
    
        $image->delete(); // Permanent delete
    
        return response()->json([
            'status' => true,
            'message' => 'Template image deleted permanently'
        ]);
    }
public function retailerSalesReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'nullable',
        'from_date'  => 'required|date',
        'to_date'    => 'required|date',
        'state'      => 'nullable|string',
        'district'   => 'nullable|string',
        'pincode'    => 'nullable|string',
        'search'     => 'nullable|string',
        'per_page'   => 'nullable|integer'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $fromDate = Carbon::parse($request->from_date)->startOfDay();
    $toDate   = Carbon::parse($request->to_date)->endOfDay();
    $perPage  = $request->per_page ?? 20;

    // 1. Fetch Retailers (Optimized selection)
    $retailers = Company::query()
        ->where('role', 5)
       // ->where('company_id', $request->company_id)
        ->when($request->state, fn($q) => $q->where('state', $request->state))
        ->when($request->district, fn($q) => $q->where('district', $request->district))
        ->when($request->pincode, fn($q) => $q->where('pincode', $request->pincode))
        ->when($request->search, function($q) use($request) {
            $q->where(function($sub) use($request) {
                $sub->where('business_name', 'like', '%' . $request->search . '%')
                    ->orWhere('company_code', 'like', '%' . $request->search . '%');
            });
        })
        ->select('id', 'business_name', 'company_code', 'state', 'district', 'pincode', 'created_at', 'contact_phone')
        ->paginate($perPage);

    // 2. Optimized Sales Query with Keying
    $retailerIds = $retailers->pluck('id');

    $sales = WDevice::query()
        ->whereIn('retailer_id', $retailerIds)
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->select(
            'retailer_id',
            DB::raw('DATE(created_at) as sale_date'),
            DB::raw('COUNT(id) as sale_count'),
            DB::raw('SUM(product_price) as sale_amount')
        )
        ->groupBy('retailer_id', 'sale_date')
        ->get()
        // Here is the magic: Group by retailer, THEN key each day by the date
        ->groupBy('retailer_id')
        ->map(fn($item) => $item->keyBy('sale_date'));

    // 3. Generate Date Range once
    $dates = [];
    for ($date = $fromDate->copy(); $date->lte($toDate); $date->addDay()) {
        $dates[] = $date->format('Y-m-d');
    }

    // 4. Transform Data efficiently
    $data = $retailers->getCollection()->map(function($retailer) use ($sales, $dates) {
        $retailerSales = $sales->get($retailer->id, collect());
        
        $activity = [];
        foreach ($dates as $date) {
            // Instant lookup instead of search
            $sale = $retailerSales->get($date);
            
            $activity[$date] = [
                'is_sale'     => (bool)$sale,
                'sale_amount' => $sale->sale_amount ?? 0,
                'sale_count'  => (int)($sale->sale_count ?? 0)
            ];
        }

        return [
            'retailer_id'   => $retailer->id,
            'retailer_code' => $retailer->company_code,
            'retailer_name' => $retailer->business_name,
            'state'         => $retailer->state,
            'district'      => $retailer->district,
            'pincode'       => $retailer->pincode,
            'onboard_date'  => $retailer->created_at,
            'contact_phone' => $retailer->contact_phone,
            'activity'      => $activity
        ];
    });

    return response()->json([
        'status'  => true,
        'filters' => [
            'from_date' => $fromDate->toDateString(),
            'to_date'   => $toDate->toDateString()
        ],
        'pagination' => [
            'current_page' => $retailers->currentPage(),
            'last_page'    => $retailers->lastPage(),
            'per_page'     => $retailers->perPage(),
            'total'        => $retailers->total()
        ],
        'data' => $data
    ]);
}

public function promoterSalesReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer|exists:companies,id',
        'from_date'  => 'required|date',
        'to_date'    => 'required|date',
        'state'      => 'nullable|string',
        'district'   => 'nullable|string',
        'pincode'    => 'nullable|string',
        'search'     => 'nullable|string',
        'per_page'   => 'nullable|integer'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $fromDate = Carbon::parse($request->from_date)->startOfDay();
    $toDate   = Carbon::parse($request->to_date)->endOfDay();
    $perPage  = $request->per_page ?? 20;

    // 1. Fetch Retailers (Optimized selection)
    $retailers = Company::query()
        ->where('role', 6)
        ->where('created_by_id', $request->retailer_id)
        ->where('company_id', $request->company_id)
        ->when($request->state, fn($q) => $q->where('state', $request->state))
        ->when($request->district, fn($q) => $q->where('district', $request->district))
        ->when($request->pincode, fn($q) => $q->where('pincode', $request->pincode))
        ->when($request->search, function($q) use($request) {
            $q->where(function($sub) use($request) {
                $sub->where('business_name', 'like', '%' . $request->search . '%')
                    ->orWhere('company_code', 'like', '%' . $request->search . '%');
            });
        })
        ->select('id', 'business_name', 'company_code', 'state', 'district', 'pincode', 'created_at', 'contact_phone')
        ->paginate($perPage);

    // 2. Optimized Sales Query with Keying
    $retailerIds = $retailers->pluck('id');

    $sales = WDevice::query()
        ->whereIn('promoter_id', $retailerIds)
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->select(
            'promoter_id',
            DB::raw('DATE(created_at) as sale_date'),
            DB::raw('COUNT(id) as sale_count'),
            DB::raw('SUM(product_price) as sale_amount')
        )
        ->groupBy('promoter_id', 'sale_date')
        ->get()
        // Here is the magic: Group by retailer, THEN key each day by the date
        ->groupBy('retailer_id')
        ->map(fn($item) => $item->keyBy('sale_date'));

    // 3. Generate Date Range once
    $dates = [];
    for ($date = $fromDate->copy(); $date->lte($toDate); $date->addDay()) {
        $dates[] = $date->format('Y-m-d');
    }

    // 4. Transform Data efficiently
    $data = $retailers->getCollection()->map(function($retailer) use ($sales, $dates) {
        $retailerSales = $sales->get($retailer->id, collect());
        
        $activity = [];
        foreach ($dates as $date) {
            // Instant lookup instead of search
            $sale = $retailerSales->get($date);
            
            $activity[$date] = [
                'is_sale'     => (bool)$sale,
                'sale_amount' => $sale->sale_amount ?? 0,
                'sale_count'  => (int)($sale->sale_count ?? 0)
            ];
        }

        return [
            'promoter_id'   => $retailer->id,
            'retailer_code' => $retailer->company_code,
            'retailer_name' => $retailer->business_name,
            'state'         => $retailer->state,
            'district'      => $retailer->district,
            'pincode'       => $retailer->pincode,
            'onboard_date'  => $retailer->created_at,
            'contact_phone' => $retailer->contact_phone,
            'activity'      => $activity
        ];
    });

    return response()->json([
        'status'  => true,
        'filters' => [
            'from_date' => $fromDate->toDateString(),
            'to_date'   => $toDate->toDateString()
        ],
        'pagination' => [
            'current_page' => $retailers->currentPage(),
            'last_page'    => $retailers->lastPage(),
            'per_page'     => $retailers->perPage(),
            'total'        => $retailers->total()
        ],
        'data' => $data
    ]);
}

public function distributorSalesReport(Request $request)
{
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer|exists:companies,id',
        'from_date'  => 'required|date',
        'to_date'    => 'required|date',
        'state'      => 'nullable|string',
        'district'   => 'nullable|string',
        'pincode'    => 'nullable|string',
        'search'     => 'nullable|string',
        'per_page'   => 'nullable|integer'
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $fromDate = Carbon::parse($request->from_date)->startOfDay();
    $toDate   = Carbon::parse($request->to_date)->endOfDay();
    $perPage  = $request->per_page ?? 20;

    // 1. Fetch Retailers (Optimized selection)
    $retailers = Company::query()
        ->where('role', 4)
        ->where('company_id', $request->company_id)
        ->when($request->state, fn($q) => $q->where('state', $request->state))
        ->when($request->district, fn($q) => $q->where('district', $request->district))
        ->when($request->pincode, fn($q) => $q->where('pincode', $request->pincode))
        ->when($request->search, function($q) use($request) {
            $q->where(function($sub) use($request) {
                $sub->where('business_name', 'like', '%' . $request->search . '%')
                    ->orWhere('company_code', 'like', '%' . $request->search . '%');
            });
        })
        ->select('id', 'business_name', 'company_code', 'state', 'district', 'pincode', 'created_at', 'contact_phone')
        ->paginate($perPage);
        

    // 2. Optimized Sales Query with Keying
    $retailerIds = $retailers->pluck('id');

    $sales = WDevice::query()
        ->whereIn('company_id', $retailerIds)
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->select(
            'company_id',
            DB::raw('DATE(created_at) as sale_date'),
            DB::raw('COUNT(id) as sale_count'),
            DB::raw('SUM(product_price) as sale_amount')
        )
        ->groupBy('company_id', 'sale_date')
        ->get()
        // Here is the magic: Group by retailer, THEN key each day by the date
        ->groupBy('retailer_id')
        ->map(fn($item) => $item->keyBy('sale_date'));

    // 3. Generate Date Range once
    $dates = [];
    for ($date = $fromDate->copy(); $date->lte($toDate); $date->addDay()) {
        $dates[] = $date->format('Y-m-d');
    }

    // 4. Transform Data efficiently
    $data = $retailers->getCollection()->map(function($retailer) use ($sales, $dates) {
        $retailerSales = $sales->get($retailer->id, collect());
        
        $activity = [];
        foreach ($dates as $date) {
            // Instant lookup instead of search
            $sale = $retailerSales->get($date);
            
            $activity[$date] = [
                'is_sale'     => (bool)$sale,
                'sale_amount' => $sale->sale_amount ?? 0,
                'sale_count'  => (int)($sale->sale_count ?? 0)
            ];
        }

        return [
            'company_id'    => $retailer->id,
            'retailer_code' => $retailer->company_code,
            'retailer_name' => $retailer->business_name,
            'state'         => $retailer->state,
            'district'      => $retailer->district,
            'pincode'       => $retailer->pincode,
            'onboard_date'  => $retailer->created_at,
            'contact_phone' => $retailer->contact_phone,
            'activity'      => $activity
        ];
    });

    return response()->json([
        'status'  => true,
        'filters' => [
            'from_date' => $fromDate->toDateString(),
            'to_date'   => $toDate->toDateString()
        ],
        'pagination' => [
            'current_page' => $retailers->currentPage(),
            'last_page'    => $retailers->lastPage(),
            'per_page'     => $retailers->perPage(),
            'total'        => $retailers->total()
        ],
        'data' => $data
    ]);
}
public function getAgentsUnderCompany($companyId)
{
    return Company::where('company_id', $companyId)
        ->where('role', 4)
        ->pluck('id');
}

    public function getRetailersUnderCompany($companyId)
    {
        $agents = $this->getAgentsUnderCompany($companyId);
    
        return Company::where('role', 5)
            ->where(function ($q) use ($companyId, $agents) {
                $q->where('company_id', $companyId)
                  ->orWhereIn('created_by_id', $agents);
            })
            ->pluck('id');
    }

    public function getPromotersUnderRetailers($retailerIds)
    {
        return Company::where('role', 6)
            ->whereIn('senior_id', $retailerIds)
            ->pluck('id');
    }


 public function getGeoData(Request $request)
{
    $query = Company::query()
        ->whereNotNull('state')
        ->whereNotNull('district')
        ->whereNotNull('pincode');

    //  Filters
    if ($request->filled('agent_id')) {
        $query->where('agent_id', $request->agent_id);
    }

    if ($request->filled('company_id')) {
        $query->where('id', $request->company_id);
    }

    if ($request->filled('created_by_id')) {
        $query->where('created_by_id', $request->created_by_id);
    }

    // Date filters
    if ($request->filled('start_date')) {
        $query->whereDate('created_at', '>=', $request->start_date);
    }

    if ($request->filled('end_date')) {
        $query->whereDate('created_at', '<=', $request->end_date);
    }

    // 🔥 GROUPING (important change)
    $rows = $query->selectRaw("
            state,
            district,
            pincode,
            city,
            COUNT(*) as customers,
            MAX(DATE(created_at)) as date
        ")
        ->groupBy('state', 'district', 'pincode', 'city')
        ->orderBy('state')
        ->get();

    $result = [];

    foreach ($rows as $row) {

        $state = $row->state;
        $district = $row->district;

        if (!isset($result[$state])) {
            $result[$state] = ['districts' => []];
        }

        if (!isset($result[$state]['districts'][$district])) {
            $result[$state]['districts'][$district] = ['pincodes' => []];
        }

        $result[$state]['districts'][$district]['pincodes'][] = [
            'code' => (string) $row->pincode,
            'customers' => (int) $row->customers,
            'label' => $row->city ?? 'Area',
            'date' => $row->date // ✅ single date (latest)
        ];
    }

    return response()->json($result);
}

    public function getOnboardingStats(Request $request)
    {
    
        $query = Company::query();
    
        // Filters
    
        if ($request->filled('agent_id')) {
    
            $query->where('agent_id', $request->agent_id);
    
        }
    
        if ($request->filled('company_id')) {
    
            $query->where('id', $request->company_id);
    
        }
    
        if ($request->filled('created_by_id')) {
    
            $query->where('created_by_id', $request->created_by_id);
    
        }
    
        if ($request->filled('role')) {
    
            $query->where('role', $request->role);
    
        }
    
        // Optional date range filter
    
        if ($request->filled('start_date')) {
    
            $query->whereDate('created_at', '>=', $request->start_date);
    
        }
    
        if ($request->filled('end_date')) {
    
            $query->whereDate('created_at', '<=', $request->end_date);
    
        }
    
        // 📊 Group by date
    
        $data = $query->selectRaw('DATE(created_at) as date, COUNT(*) as count')
    
            ->groupBy('date')
    
            ->orderBy('date')
    
            ->get();
    
        //  Format like dummy JSON
    
        $result = $data->map(function ($item) {
    
            return [
    
                'date' => $item->date,
    
                'count' => (int) $item->count,
    
            ];
    
        });
    
        return response()->json($result);
    
    }

    public function paymentWalletBalance(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
    
            'company_id'   => 'required|exists:companies,id',
            'retailer_id'  => 'required|exists:companies,id',
            'payment_id'   => 'required|string',
            'payment_mode' => 'required|string',
            'amount'       => 'required|numeric|min:1',
            "url"          => 'nullable'
    
        ]);
    
        if ($validator->fails()) {
    
            return response()->json([
    
                'status'  => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors()
    
            ], 422);
    
        }
    
        $exists = \App\Models\AdvancePayment::where(
            'payment_id',
            $request->payment_id
    
        )->exists();
    
        if ($exists) {
    
            return response()->json([
                'status'  => false,
                'message' => 'Payment already processed'
    
            ], 409);
        }
    
    
        $payload = [
    
            'company_id'   => $request->company_id,
            'retailer_id'  => $request->retailer_id,
            'payment_id'   => $request->payment_id,
            'payment_mode' => $request->payment_mode,
            'amount'       => $request->amount
    
        ];
    
       
        AdvancePaymentJob::dispatch($payload);
      
      
       DB::table('payments_master')->insert([
            'payment_id' => $request->payment_id,
            'order_id' => "",
            'project' => "warranty",
            'service' => "advance_payment",
            'amount' => $request->amount,
            'currency' => "inr",
            'status' => 'captured',
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'gateway' => $request->payment_mode,
            'company_id' => $request->company_id,
            'url'       => $request->url
        ]);
        
        return response()->json([
    
            'status'  => true,
            'message' => 'Advance payment job queued successfully',
            'data' => [
                'payment_id' => $request->payment_id,
                'amount' => $request->amount
            ]
        ]);
    
    }
}