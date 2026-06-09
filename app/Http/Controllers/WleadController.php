<?php

namespace App\Http\Controllers;

use App\Models\WLead;

use App\Models\CompanyEmployee;

use App\Models\IndiaPincode;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use App\Mail\WelcomeCompanyMail;
use App\Mail\LeadCreateMail;
use App\Mail\LeadCreateSystemMail;
use App\Mail\LeadInProcessMail;

use App\Models\OrgUsersMaster;
use App\Services\OrgCodeService;
use Illuminate\Support\Facades\Mail;

use App\Jobs\SendCompanyCreatedWhatsapp;
use App\Jobs\SendAgentPendingWhatsapp;
use DB;

use Carbon\Carbon;

class WleadController extends Controller
{


    public function store(Request $request)
    {
        // ================= NORMALIZE =================
    
        $email   = strtolower(trim($request->email));
    
        $phone   = trim($request->phone);
    
        $product = strtolower(trim($request->products));
    
        // ================= VALIDATION =================
    
        $validator = Validator::make($request->all(), [
    
            'name'             => 'required|string|max:255',
    
            'phone'            => 'required|string|max:20',
    
            'email'            => 'required|email',
    
            'created_by_id'    => 'required',
    
            'created_by_name'  => 'required',
    
            'owner_name'       => 'required',
    
            'lead_type'        => 'required',
    
            'pincode'          => 'required|digits:6',
    
            'products'         => 'required|string|in:emi_locker,warranty',
    
            'is_existing_user' => 'nullable|in:0,1'
    
        ]);
    
        if ($validator->fails()) {
    
            return response()->json([
    
                'status'  => false,
    
                'message' => 'Validation error',
    
                'errors'  => $validator->errors()
    
            ], 422);
        }
    
        $isForceFlow = (
    
            $request->is_existing_user == 1
            &&
            $product == 'warranty'
    
        );
    
        DB::beginTransaction();
    
        try {
    
            // =====================================================
            // DUPLICATE LEAD CHECK
            // =====================================================
    
            $existingLead = DB::table('w_leads')
    
                ->where(function ($q) use ($email, $phone) {
    
                    $q->where('email', $email)
    
                      ->orWhere('phone', $phone);
    
                })
    
                ->where('products', $product)
    
                ->lockForUpdate()
    
                ->first();
    
            if ($existingLead) {
    
                DB::rollBack();
    
                return response()->json([
    
                    'status'  => false,
    
                    'message' => 'Lead already exists for this product',
    
                    'source'  => 'lead',
    
                    'data' => [
    
                        'id'       => $existingLead->id ?? null,
    
                        'name'     => $existingLead->name ?? null,
    
                        'email'    => $existingLead->email ?? null,
    
                        'phone'    => $existingLead->phone ?? null,
    
                        'products' => $existingLead->products ?? null
                    ]
    
                ], 200);
            }
    
            // =====================================================
            // ORG USER CHECK
            // =====================================================
    
            $orgUser = DB::table('org_users_master')
    
                ->where('email', $email)
    
                ->orWhere('phone', $phone)
    
                ->first();
    
            if ($orgUser && !$isForceFlow) {
    
                DB::rollBack();
    
                return response()->json([
    
                    'status'  => false,
    
                    'message' => 'User already exists (Org User)',
    
                    'source'  => 'org_user',
    
                    'data'    => $orgUser
    
                ], 200);
            }
    
            // =====================================================
            // COMPANY CHECK
            // =====================================================
    
            if (!$isForceFlow) {
    
                $companyUser = DB::table('companies')
    
                    ->where('contact_email', $email)
    
                    ->orWhere('contact_phone', $phone)
    
                    ->first();
    
                if ($companyUser) {
    
                    DB::rollBack();
    
                    return response()->json([
    
                        'status'  => false,
    
                        'message' => 'User already exists (Company)',
    
                        'source'  => 'company',
    
                        'data'    => $companyUser
    
                    ], 200);
                }
            }
    
            // =====================================================
            // PINCODE
            // =====================================================
    
            $pincodeData = IndiaPincode::where(
    
                'pincode',
                $request->pincode
    
            )->first();
    
            if (!$pincodeData) {
    
                DB::rollBack();
    
                return response()->json([
    
                    'status'  => false,
    
                    'message' => 'Invalid Pincode'
    
                ], 422);
            }
    
            $stateIn    = $pincodeData->state_in;
    
            $districtIn = $pincodeData->district_in;
    
            $plainPassword = random_int(100000, 999999);
    
            // =====================================================
            // OWNER SPLIT
            // =====================================================
    
            $ownerFullName = trim($request->owner_name ?? '');
    
            $parts = preg_split('/\s+/', $ownerFullName);
    
            $ownerFirstName  = $parts[0] ?? null;
    
            $ownerMiddleName = null;
    
            $ownerLastName   = null;
    
            if (count($parts) == 2) {
    
                $ownerLastName = $parts[1];
    
            } elseif (count($parts) > 2) {
    
                $ownerLastName   = array_pop($parts);
    
                $ownerMiddleName = implode(
                    ' ',
                    array_slice($parts, 1)
                );
            }
    
            // =====================================================
            // CREATE LEAD
            // =====================================================
    
            $lead = WLead::create([
    
                'name'               => $request->name,
    
                'phone'              => $phone,
    
                'state'              => $request->state,
    
                'district'           => $request->district,
    
                'pincode'            => $request->pincode,
    
                'email'              => $email,
    
                'address1'           => $request->address1,
    
                'address2'           => $request->address2,
    
                'status'             => $request->status ?? 1,
    
                'lead_amount'        => $request->lead_amount,
    
                'password'           => Hash::make($plainPassword),
    
                'created_by_id'      => $request->created_by_id,
    
                'created_by_name'    => $request->created_by_name,
    
                'lead_type'          => $request->lead_type,
    
                'package_id'         => $request->package_id,
    
                'package_name'       => $request->package_name,
    
                'badge_name'         => $request->badge_name,
    
                'badge_id'           => $request->badge_id,
    
                'benefits'           => $request->benefits,
    
                'eligibility'        => $request->eligibility,
    
                'company_id'         => $request->company_id,
    
                'manager_id'         => $request->manager_id,
    
                'agent_id'           => $request->agent_id,
    
                'state_in'           => $stateIn,
    
                'district_in'        => $districtIn,
    
                'formdata'           => $request->formdata,
    
                'form_ref'           => $request->form_ref,
    
                'pay_now'            => $request->pay_now,
    
                'pay_later'          => $request->pay_later,
    
                'owner_name'         => $ownerFullName,
    
                'owner_first_name'   => $ownerFirstName,
    
                'owner_middle_name'  => $ownerMiddleName,
    
                'owner_last_name'    => $ownerLastName,
    
                'products'           => $product
    
            ]);
    
            $leadCode =
    
                "{$districtIn}-{$request->pincode}-{$lead->id}";
    
            $lead->update([
    
                'lead_code' => $leadCode
    
            ]);
            
            
            
              // =====================================================
                // SEND INTERNAL MAIL
                // =====================================================
    
                try {
    
                    $companyEmployee = CompanyEmployee::find(
                        $request->manager_id
                    );
                    
                 
    
                    $companyId =
    
                        $company->id
                        ??
                        $request->company_id
                        ??
                        null;
    
                
    
                    if (
    
                        $companyEmployee
                        &&
                        !empty($companyEmployee->official_email)
                        &&
                        $companyId
    
                    ) {
    
                        Mail::to(
    
                            $companyEmployee->official_email
    
                        )
    
                        ->cc(
                            'indresh@goelectronix.com'
                        )
    
                        ->queue(
    
                            (
    
                                new LeadCreateSystemMail(
    
                                    $lead->id,
    
                                    $companyId
    
                                )
    
                            )->afterCommit()
    
                        );
    
                    
    
                    } else {
    
                      
                    }
    
                } catch (\Throwable $mailError) {
    
                  
                }
    
            // =====================================================
            // FORCE FLOW
            // =====================================================
    
            if ($isForceFlow) {
    
                $agent = !empty($request->agent_id)
    
                    ? Company::find($request->agent_id)
    
                    : null;
    
                $company = Company::create([
    
                    'business_name'     => $lead->name,
    
                    'contact_email'     => $lead->email,
    
                    'contact_person'    => $lead->owner_name,
    
                    'contact_phone'     => $lead->phone,
    
                    'password'          => Hash::make($plainPassword),
    
                    'address_line1'     => $lead->address1,
    
                    'address_line2'     => $lead->address2,
    
                    'city'              => $lead->district,
    
                    'district'          => $lead->district,
    
                    'state'             => $lead->state,
    
                    'pincode'           => $lead->pincode,
    
                    'status'            => 1,
    
                    'role'              => $lead->lead_type,
    
                    'company_id'        => $lead->company_id,
    
                    'senior_id'         => $lead->agent_id,
    
                    'agent_code'        => $agent->company_code ?? "",
    
                    'owner_first_name'  => $lead->owner_first_name,
    
                    'owner_middle_name' => $lead->owner_middle_name,
    
                    'owner_last_name'   => $lead->owner_last_name,
    
                    'pay_now'           => $lead->pay_now,
    
                    'pay_later'         => $lead->pay_later,
                    
                    'created_by_id'     => $lead->created_by_id,
                    
                    'created_by_name'   => $lead->created_by_name,
                    
                    'address_line1'     => $lead->address1,
                
                    'address_line2'   => $lead->address2
    
                ]);
    
                // =====================================================
                // FIND EXISTING ORG USER
                // =====================================================
    
                $orgUser = OrgUsersMaster::get()
    
                    ->first(function ($item) use ($lead) {
    
                        $dbEmail = strtolower(
                            trim($item->email ?? '')
                        );
    
                        $dbPhone = preg_replace(
                            '/[^0-9]/',
                            '',
                            $item->phone ?? ''
                        );
    
                        $dbPhone = substr($dbPhone, -10);
    
                        $leadEmail = strtolower(
                            trim($lead->email ?? '')
                        );
    
                        $leadPhone = preg_replace(
                            '/[^0-9]/',
                            '',
                            $lead->phone ?? ''
                        );
    
                        $leadPhone = substr($leadPhone, -10);
    
                        return (
    
                            (!empty($leadEmail)
                            &&
                            $dbEmail === $leadEmail)
    
                            ||
    
                            (!empty($leadPhone)
                            &&
                            $dbPhone === $leadPhone)
                        );
                    });
    
                // =====================================================
                // REUSE OR GENERATE ORG CODE
                // =====================================================
    
                if ($orgUser && !empty($orgUser->org_code)) {
    
                    $orgCode = $orgUser->org_code;
    
                } else {
    
                    $orgCode = (new OrgCodeService())->generate(
    
                        $lead->lead_type,
    
                        $lead->state
    
                    );
                }
    
                // =====================================================
                // FIX: PREVENT DUPLICATE ORG USER
                // =====================================================
    
                $orgUser = OrgUsersMaster::where(function ($q) use ($lead) {
    
                    $q->where('email', $lead->email)
    
                      ->orWhere('phone', $lead->phone);
    
                })
    
                ->lockForUpdate()
    
                ->first();
    
                if ($orgUser) {
    
                    $orgUser->update([
    
                        'business_name' => $lead->name,
    
                        'state'         => $lead->state,
    
                        'city'          => $lead->district,
    
                        'pincode'       => $lead->pincode,
    
                        'products'      => ['warranty', 'emi_locker'],
    
                        'role'          => $lead->lead_type,
    
                    ]);
    
                } else {
    
                    $orgUser = OrgUsersMaster::create([
    
                        'business_name' => $lead->name,
    
                        'org_code'      => $orgCode,
    
                        'phone'         => $lead->phone,
    
                        'email'         => $lead->email,
    
                        'state'         => $lead->state,
    
                        'city'          => $lead->district,
    
                        'pincode'       => $lead->pincode,
    
                        'company_id'    => $lead->company_id ?? 0,
    
                        'products'      => ['warranty'],
    
                        'role'          => $lead->lead_type,
    
                    ]);
                }
    
                // =====================================================
                // UPDATE COMPANY WITH ZOHO ID
                // =====================================================
    
                $company->update([
    
                    'company_code' => $orgCode,
    
                    'zoho_id'      => $orgUser->zoho_contact_id ?? null
    
                ]);
    
                $lead->update([
    
                    'status' => 'in progress'
    
                ]);
    
                // =====================================================
                // SEND LOGIN MAIL
                // =====================================================
    
                if (!empty($lead->email)) {
    
                    $signinUrl =
    
                        rtrim(
                            config('app.retailer_panel_url'),
                            '/'
                        )
    
                        . '/signin?email='
    
                        . urlencode($lead->email);
    
                    Mail::to($lead->email)
    
                        ->send(
    
                            new LeadCreateMail(
    
                                $lead,
    
                                $signinUrl,
    
                                $plainPassword
    
                            )
    
                        );
                }
    
              
    
                DB::commit();
    
                SendCompanyCreatedWhatsapp::dispatch(
                    $company->id
                );
    
                return response()->json([
    
                    'status'  => true,
    
                    'message' => 'Lead created successfully',
    
                    'data'    => $lead
    
                ], 201);
            }
    
            DB::commit();
    
            return response()->json([
    
                'status'  => true,
    
                'message' => 'Lead created successfully',
    
                'data'    => $lead
    
            ], 201);
    
        } catch (\Throwable $e) {
    
            DB::rollBack();
    
            Log::error(
    
                'LEAD CREATION FAILED',
    
                [
    
                    'message' =>
    
                        $e->getMessage(),
    
                    'line' =>
    
                        $e->getLine(),
    
                    'file' =>
    
                        $e->getFile(),
    
                    'trace' =>
    
                        substr(
                            $e->getTraceAsString(),
                            0,
                            3000
                        ),
    
                    'request' =>
    
                        $request->all()
                ]
            );
    
            return response()->json([
    
                'status'  => false,
    
                'message' => 'Failed to create lead',
    
                'error'   => $e->getMessage()
    
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $lead = WLead::find($id);
    
        if (!$lead) {
            return response()->json([
                'status' => false,
                'message' => 'Lead not found'
            ], 404);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Dynamic Validation Rules
        |--------------------------------------------------------------------------
        */
    
        $rules = [];
    
        if ($request->filled('name')) {
            $rules['name'] = 'string|max:255';
        }
    
        if ($request->filled('phone')) {
            $rules['phone'] = 'string|max:20|unique:w_leads,phone,' . $id;
        }
    
        if ($request->filled('email')) {
            $rules['email'] = 'email|unique:w_leads,email,' . $id;
        }
    
        if ($request->filled('pincode')) {
            $rules['pincode'] = 'digits:6';
        }
    
        $validator = Validator::make($request->all(), $rules);
    
        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation error',
                'errors'  => $validator->errors()
            ], 422);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Allowed Fields
        |--------------------------------------------------------------------------
        */
    
        $allowedFields = [
            'name', 'owner_name', 'phone','email','state','district','pincode',
            'address1','address2','status','lead_amount',
            'created_by_id','created_by_name','owner_name','lead_type',
            'package_id','package_name','badge_name','badge_id',
            'benefits','eligibility','company_id','manager_id',
            'agent_id','formdata','form_ref','pay_now','pay_later',
            'updated_by_id','updated_by_name', 'logo', 'domain', 'title', 'owner_first_name',
            'owner_middle_name','owner_last_name'
        ];
    
        $updateData = [];
    
        foreach ($allowedFields as $field) {
    
            // Only update if value is NOT null
            if ($request->has($field) && !is_null($request->$field)) {
                $updateData[$field] = $request->$field;
            }
        }
    
        /*
        |--------------------------------------------------------------------------
        | Password Handling
        |--------------------------------------------------------------------------
        */
    
        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Pincode Handling
        |--------------------------------------------------------------------------
        */
    
        if ($request->filled('pincode')) {
    
            $pincodeData = IndiaPincode::where('pincode', $request->pincode)->first();
    
            if (!$pincodeData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid Pincode'
                ], 422);
            }
    
            $updateData['state_in']    = $pincodeData->state_in;
            $updateData['district_in'] = $pincodeData->district_in;
    
            $updateData['lead_code'] =
                $pincodeData->state_in . '-' .
                $pincodeData->district_in . '-' .
                $request->pincode . '-' .
                $lead->id;
        }
    
        /*
        |--------------------------------------------------------------------------
        | If Nothing To Update
        |--------------------------------------------------------------------------
        */
    
        if (empty($updateData)) {
            return response()->json([
                'status' => false,
                'message' => 'No valid data provided for update'
            ], 400);
        }
    
        /*
        |--------------------------------------------------------------------------
        | Update Lead
        |--------------------------------------------------------------------------
        */
    
        $lead->update($updateData);
    
        return response()->json([
            'status'  => true,
            'message' => 'Lead updated successfully.',
            'data'    => $lead->fresh()
        ]);
    }
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

        $baseQuery = WLead::with([
        
            'remarks.followupUser:id,first_name,last_name,official_phone,official_email,photo_url,employee_id,position',
        
            'remarks.createdUser:id,first_name,last_name'
        
        ]);

    
    
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
        // STATUS FILTER
        // ---------------------------------
    
        if ($request->status) {
            $baseQuery->where('status', $request->status);
        }
    
        // ---------------------------------
        // FORM REF FILTER
        // ---------------------------------
    
        if ($request->form_ref) {
            $baseQuery->where('form_ref', $request->form_ref);
        }
    
        // ---------------------------------
        // FOLLOWUP FILTERS
        // ---------------------------------
    
        // Filter by follow up user
        if ($request->follow_up_by) {
            $baseQuery->where('follow_up_by', $request->follow_up_by);
        }
    
        // Exact followup date
        if ($request->followup_date) {
            $baseQuery->whereDate('followup_date', $request->followup_date);
        }
    
        // Followup date range
        if ($request->followup_from_date && $request->followup_to_date) {
    
            $baseQuery->whereBetween('followup_date', [
                $request->followup_from_date . ' 00:00:00',
                $request->followup_to_date . ' 23:59:59'
            ]);
        }
    
        // Today followups
        if ($request->today_followup == 1) {
            $baseQuery->whereDate('followup_date', now()->toDateString());
        }
    
        // Pending followups
        if ($request->pending_followup == 1) {
    
            $baseQuery->whereDate(
                'followup_date',
                '<',
                now()->toDateString()
            );
        }
    
        // Upcoming followups
        if ($request->upcoming_followup == 1) {
    
            $baseQuery->whereDate(
                'followup_date',
                '>',
                now()->toDateString()
            );
        }
    
        // ---------------------------------
        // SEARCH FILTER
        // ---------------------------------
    
        if ($request->search) {
    
            $search = $request->search;
    
            $baseQuery->where(function ($q) use ($search) {
    
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('owner_name', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('lead_code', 'LIKE', "%{$search}%");
            });
        }
    
        // ---------------------------------
        // LOCATION FILTERS
        // ---------------------------------
    
        if ($request->state) {
    
            $states = array_map(
                'trim',
                explode(',', strtoupper($request->state))
            );
    
            $baseQuery->whereIn('state', $states);
        }
    
        if ($request->district) {
    
            $districts = array_map(
                'trim',
                explode(',', strtoupper($request->district))
            );
    
            $baseQuery->whereIn('district', $districts);
        }
    
        if ($request->pincode) {
    
            $pincodes = array_map(
                'trim',
                explode(',', $request->pincode)
            );
    
            $baseQuery->whereIn('pincode', $pincodes);
        }
    
        // ---------------------------------
        // CREATED DATE FILTER
        // ---------------------------------
    
        if ($request->from_date && $request->to_date) {
    
            $baseQuery->whereBetween('created_at', [
                $request->from_date . ' 00:00:00',
                $request->to_date . ' 23:59:59'
            ]);
        }
        
      if ($request->tomorrow_followup == 1) {

        $baseQuery->whereDate(
    
            'followup_date',
    
            Carbon::tomorrow()->toDateString()
    
        );
    
    }

    
        // ---------------------------------
        // SUMMARY
        // ---------------------------------
    
        $totalLeads = (clone $baseQuery)->count();
    
        $newCount = (clone $baseQuery)
            ->where('status', 'new')
            ->count();
    
        $inProcessCount = (clone $baseQuery)
            ->where('status', 'in process')
            ->count();
    
        $wonCount = (clone $baseQuery)
            ->where('status', 'won')
            ->count();
    
        $lostCount = (clone $baseQuery)
            ->where('status', 'lost')
            ->count();
    
        $statusAmounts = [
    
            'new' => (clone $baseQuery)
                ->where('status', 'new')
                ->sum('lead_amount'),
    
            'in_process' => (clone $baseQuery)
                ->where('status', 'in process')
                ->sum('lead_amount'),
    
            'won' => (clone $baseQuery)
                ->where('status', 'won')
                ->sum('lead_amount'),
    
            'lost' => (clone $baseQuery)
                ->where('status', 'lost')
                ->sum('lead_amount'),
        ];
    
        $leadTypeCounts = [
    
            'type_2' => (clone $baseQuery)
                ->where('lead_type', 2)
                ->count(),
    
            'type_4' => (clone $baseQuery)
                ->where('lead_type', 4)
                ->count(),
    
            'type_5' => (clone $baseQuery)
                ->where('lead_type', 5)
                ->count(),
        ];
    
        $todayFollowups = (clone $baseQuery)
            ->whereDate('followup_date', now()->toDateString())
            ->count();
    
        $pendingFollowups = (clone $baseQuery)
            ->whereDate('followup_date', '<', now()->toDateString())
            ->count();
    
        $upcomingFollowups = (clone $baseQuery)
            ->whereDate('followup_date', '>', now()->toDateString())
            ->count();
            
        $tomorrowFollowups = (clone $baseQuery)
        
            ->whereDate(
        
                'followup_date',
        
                now()->addDay()->toDateString()
        
            )
        
            ->count();
    
        $totalLeadAmount = (clone $baseQuery)
            ->sum('lead_amount');
    
        $conversionRate = $totalLeads > 0
            ? round(($wonCount / $totalLeads) * 100, 2)
            : 0;
    
        // ---------------------------------
        // SORTING
        // ---------------------------------
    
        $sortBy = $request->sort_by ?? 'id';
    
        $sortOrder = $request->sort_order ?? 'desc';
    
        $allowedSorts = [
            'id',
            'created_at',
            'followup_date',
            'lead_amount',
            'name',
            'status'
        ];
    
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }
    
        // ---------------------------------
        // PAGINATION
        // ---------------------------------
    
        $perPage = $request->per_page ?? 10;
    
        $leads = (clone $baseQuery)
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);
    
        // ---------------------------------
        // RESPONSE
        // ---------------------------------
    
        return response()->json([
    
            'status' => true,
    
            'message' => 'Lead list fetched successfully',
    
            'summary' => [
    
                'total_leads' => $totalLeads,
    
                'status_counts' => [
                    'new' => $newCount,
                    'in_process' => $inProcessCount,
                    'won' => $wonCount,
                    'lost' => $lostCount,
                ],
    
               'followup_counts' => [

                    'today' => $todayFollowups,
                
                    'tomorrow' => $tomorrowFollowups,
                
                    'pending' => $pendingFollowups,
                
                    'upcoming' => $upcomingFollowups,
                
                ],
                'status_amounts' => $statusAmounts,
    
                'lead_type_counts' => $leadTypeCounts,
    
                'total_lead_amount' => $totalLeadAmount,
    
                'conversion_rate' => $conversionRate . '%'
            ],
    
            'pagination' => [
    
                'current_page' => $leads->currentPage(),
    
                'per_page' => $leads->perPage(),
    
                'last_page' => $leads->lastPage(),
    
                'total' => $leads->total()
            ],
    
            'data' => $leads->items()
        ]);
    }


public function updateStatus(Request $request, $id)
{
    $validator = Validator::make($request->all(), [
        'status'          => 'required',
        'remark'          => 'nullable|string',
        'updated_by_id'   => 'nullable|integer',
        'updated_by_name' => 'nullable|string',
        'agent_id'        => 'nullable|integer',
    ]);

    if ($validator->fails()) {

        \Log::warning('Lead Status Validation Failed', [
            'errors'  => $validator->errors(),
            'request' => $request->all()
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    $lead = WLead::find($id);

    if (!$lead) {

        \Log::warning('Lead Not Found', [
            'lead_id' => $id
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'User not found'
        ], 404);
    }

    DB::beginTransaction();

    try {

        \Log::info('Lead Status Update Started', [
            'lead_id' => $lead->id,
            'status'  => $request->status
        ]);

        // =========================
        // UPDATE LEAD
        // =========================

        $lead->status = $request->status;
        $lead->remark = $request->remark ?? $lead->remark;
        $lead->updated_by_id = $request->updated_by_id ?? $lead->updated_by_id;
        $lead->updated_by_name = $request->updated_by_name ?? $lead->updated_by_name;
        $lead->save();

        \Log::info('Lead Updated Successfully', [
            'lead_id'     => $lead->id,
            'lead_status' => $lead->status
        ]);

        // =========================
        // IN PROGRESS FLOW
        // =========================

        if (strtolower(trim($request->status)) === "in progress") {

            \Log::info('Lead Conversion Started', [
                'lead_id' => $lead->id
            ]);

            // =========================
            // VALIDATE PINCODE
            // =========================

            $pincodeData = IndiaPincode::where('pincode', $lead->pincode)->first();

            if (!$pincodeData) {

                DB::rollBack();

                \Log::warning('Invalid Pincode', [
                    'lead_id' => $lead->id,
                    'pincode' => $lead->pincode
                ]);

                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid pincode'
                ], 422);
            }

            // =========================
            // GET AGENT
            // =========================

            $agent = !empty($request->agent_id)
                ? Company::find($request->agent_id)
                : null;

            // =========================
            // GENERATE PASSWORD
            // =========================

            $plainPassword = random_int(100000, 999999);

            // =========================
            // COMPANY DATA
            // =========================

            $companyData = [
                'business_name'     => $lead->name,
                'contact_email'     => $lead->email,
                'contact_person'    => $lead->owner_name,
                'contact_phone'     => $lead->phone,
                'password'          => Hash::make($plainPassword),
                'address_line1'     => $lead->address1,
                'address_line2'     => $lead->address2,
                'city'              => $lead->district,
                'district'          => $lead->district,
                'state'             => $lead->state,
                'pincode'           => $lead->pincode,
                'status'            => 1,
                'role'              => $lead->lead_type,
                'created_by_id'     => $lead->created_by_id ?? 0,
                'created_by_name'   => $lead->created_by_name ?? '',
                'company_id'        => $lead->company_id,
                'senior_id'         => $lead->agent_id,
                'agent_id'          => $lead->agent_id,
                'agent_code'        => $agent->company_code ?? "",
                'pay_now'           => $lead->pay_now,
                'logo'              => $lead->logo,
                'domain'            => $lead->domain,
                'title'             => $lead->title,
                'owner_first_name'  => $lead->owner_first_name,
                'owner_middle_name' => $lead->owner_middle_name,
                'owner_last_name'   => $lead->owner_last_name,
            ];

            // =========================
            // CHECK EXISTING COMPANY
            // =========================

            $company = Company::where(function ($q) use ($lead) {
                    $q->where('contact_email', $lead->email)
                      ->orWhere('contact_phone', $lead->phone);
                })
                ->first();
            if (!$company) {

                $company = Company::create($companyData);

                \Log::info('New Company Created', [
                    'company_id' => $company->id,
                    'email'      => $company->contact_email,
                    'phone'      => $company->contact_phone
                ]);

            } else {

                $company->update([
                    'business_name'    => $lead->name,
                    'contact_person'   => $lead->owner_name,
                    'contact_phone'    => $lead->phone,
                    'address_line1'    => $lead->address1,
                    'address_line2'    => $lead->address2,
                    'city'             => $lead->district,
                    'district'         => $lead->district,
                    'state'            => $lead->state,
                    'pincode'          => $lead->pincode,
                    'role'             => $lead->lead_type,
                    'agent_id'         => $lead->agent_id,
                    'agent_code'       => $agent->company_code ?? "",
                    'updated_at'       => now(),
                ]);

                \Log::info('Existing Company Updated', [
                    'company_id' => $company->id
                ]);
            }

            // =========================
            // CLEAN EMAIL & PHONE
            // =========================

            $leadEmail = strtolower(trim($lead->email));

            $leadPhone = preg_replace('/[^0-9]/', '', $lead->phone);

            $leadPhone = substr($leadPhone, -10);


            \Log::info('Lead Clean Data', [
                'email' => $leadEmail,
                'phone' => $leadPhone
            ]);

            // =========================
            // FIND MASTER BY EMAIL
            // =========================

            $existingMaster = null;

            if (!empty($leadEmail)) {

                $existingMaster = OrgUsersMaster::whereRaw(
                    'LOWER(TRIM(email)) = ?',
                    [$leadEmail]
                )->first();

                \Log::info('Master Search By Email', [
                    'email'     => $leadEmail,
                    'found'     => !empty($existingMaster),
                    'master_id' => $existingMaster->id ?? null,
                    'org_code'  => $existingMaster->org_code ?? null
                ]);
            }

            // =========================
            // FIND MASTER BY PHONE
            // =========================

            if (!$existingMaster && !empty($leadPhone)) {

                $existingMaster = OrgUsersMaster::where(function ($q) use ($leadPhone) {

                    $q->where('phone', $leadPhone)
                      ->orWhere('phone', '91' . $leadPhone)
                      ->orWhere('phone', '+91' . $leadPhone);

                })->first();

                \Log::info('Master Search By Phone', [
                    'phone'     => $leadPhone,
                    'found'     => !empty($existingMaster),
                    'master_id' => $existingMaster->id ?? null,
                    'org_code'  => $existingMaster->org_code ?? null
                ]);
            }

            // =========================
            // FINAL MASTER LOG
            // =========================


            // =========================
            // REUSE / GENERATE ORG CODE
            // =========================

            if ($existingMaster && !empty($existingMaster->org_code)) {

                $orgCode = $existingMaster->org_code;

               
            } else {

                $orgService = new OrgCodeService();

                $orgCode = $orgService->generate(
                    $lead->lead_type,
                    $lead->state
                );

            }

            // =========================
            // UPDATE COMPANY CODE
            // =========================

            $company->update([
                'company_code' => $orgCode
            ]);

            \Log::info('Company Code Updated', [
                'company_id'   => $company->id,
                'company_code' => $orgCode
            ]);

            // =========================
            // CREATE / UPDATE MASTER
            // =========================

            if (!$existingMaster) {

                $master = OrgUsersMaster::create([
                    'business_name' => $lead->name,
                    'org_code'      => $orgCode,
                    'phone'         => $lead->phone,
                    'email'         => $lead->email,
                    'state'         => $lead->state,
                    'city'          => $lead->district,
                    'pincode'       => $lead->pincode,
                    'company_id'    => $lead->company_id ?? 0,
                    'products'      => $lead->products ?? ['warranty'],
                    'role'          => $lead->lead_type,
                ]);

             

            } else {

                $existingMaster->update([
                    'business_name' => $lead->name,
                    'state'         => $lead->state,
                    'city'          => $lead->district,
                    'pincode'       => $lead->pincode,
                    'products'      => $lead->products ?? $existingMaster->products,
                    'role'          => $lead->lead_type,
                ]);

                $master = $existingMaster;

                \Log::info('Existing Master Updated', [
                    'master_id' => $master->id,
                    'org_code'  => $master->org_code
                ]);
            }

            // =========================
            // SEND LOGIN MAIL
            // =========================

            if (!empty($lead->email)) {

                $signinUrl = rtrim(config('app.retailer_panel_url'), '/')
                    . '/signin?email=' . urlencode($lead->email);

                Mail::to($lead->email)
                    ->send(new LeadCreateMail(
                        $lead,
                        $signinUrl,
                        $plainPassword
                    ));

            }

            // =========================
            // SEND INTERNAL MAIL
            // =========================

            $companyEmployee = CompanyEmployee::find($lead->created_by_id);

            $parentCompany = Company::find($lead->company_id);

            if ($companyEmployee?->official_email) {

                Mail::to($companyEmployee->official_email)
                    ->cc(optional($parentCompany)->contact_email)
                    ->queue(new LeadInProcessMail(
                        $lead->id,
                        $parentCompany->id
                    ));

            
            }

            DB::commit();


            SendCompanyCreatedWhatsapp::dispatch($company->id);

            return response()->json([
                'status'  => true,
                'message' => 'Company and master created successfully',
                'data'    => [
                    'lead'       => $lead,
                    'company'    => $company,
                    'master'     => $master,
                    'org_code'   => $orgCode,
                    'company_id' => $company->id
                ]
            ], 201);
        }

        DB::commit();

       

        return response()->json([
            'status'  => true,
            'message' => 'Status updated successfully',
            'data'    => $lead
        ]);

    } catch (\Exception $e) {

        DB::rollBack();


        return response()->json([
            'status'  => false,
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ], 500);
    }
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
    
        $signinUrl = rtrim(config('app.retailer_panel_url'), '/') 
            . '/signin?email=' 
            . urlencode($company->contact_email);
            
        // Send Email with OTP
        Mail::to($company->contact_email)->send(new WelcomeCompanyMail($company, $signinUrl));
    
        return response()->json([
            "status" => true,
            "message" => "Welcome email & OTP sent successfully"
        ]);
    }

    
public function verifyEmailOtp(Request $request)
{
    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    $validator = Validator::make($request->all(), [

        'email' => 'required|email',

        'otp' => 'required|digits:6',

        'user_id' => 'required|exists:companies,id',

        'company_id' => 'required',
    ]);

    if ($validator->fails()) {

        return response()->json([

            'status' => false,

            'message' => $validator->errors()->first(),

            'errors' => $validator->errors()

        ], 422);
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | FIND COMPANY
        |--------------------------------------------------------------------------
        */

        $company = Company::where(

                'id',
                $request->user_id

            )
            ->where(
                'contact_email',
                $request->email
            )
            ->first();

        if (!$company) {

            return response()->json([

                'status'  => false,

                'message' => 'Company not found'

            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | CHECK OTP
        |--------------------------------------------------------------------------
        */

        if (
            (string) $company->otp !==
            (string) $request->otp
        ) {

            return response()->json([

                'status'  => false,

                'message' => 'Invalid OTP'

            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | DEFAULT UPDATE DATA
        |--------------------------------------------------------------------------
        */

        $companyUpdateData = [

            'is_mail_verified' => 1,

            'otp' => null,
        ];

        /*
        |--------------------------------------------------------------------------
        | CHECK ORG USER
        |--------------------------------------------------------------------------
        */

        $orguser = OrgUsersMaster::where(
                'email',
                $company->contact_email
            )
            ->first();

        if ($orguser) {

            /*
            |--------------------------------------------------------------------------
            | UPDATE ORG USER
            |--------------------------------------------------------------------------
            */

            $orguser->update([

                'is_mail_verified' => 1
            ]);

            /*
            |--------------------------------------------------------------------------
            | IF ZOHO CONTACT EXISTS
            |--------------------------------------------------------------------------
            */

            if (!empty($orguser->zoho_contact_id)) {

                $companyUpdateData['zoho_id'] =
                    $orguser->zoho_contact_id;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE COMPANY
        |--------------------------------------------------------------------------
        */

        $company->update(
            $companyUpdateData
        );

        $company->refresh();

        /*
        |--------------------------------------------------------------------------
        | CREATE ZOHO CONTACT
        |--------------------------------------------------------------------------
        | CONDITION:
        | IF org_code IS NULL
        |--------------------------------------------------------------------------
        */

        if (
         
            empty($company->zoho_id)
        ) {

            try {

                /*
                |--------------------------------------------------------------------------
                | DEFAULT COMPANY ID = 1
                |--------------------------------------------------------------------------
                */

                $defaultCompanyId = 1;

                $zohoRequest = new Request([

                    'user_id'    => $company->id,

                    'company_id' => $defaultCompanyId,

                    'role'       => $company->role
                ]);

                /*
                |--------------------------------------------------------------------------
                | CALL CREATE CONTACT METHOD
                |--------------------------------------------------------------------------
                */

                $zohoResponse =
                    $this->createContactFromCompany(
                        $zohoRequest
                    );

                $zohoData =
                    json_decode(
                        $zohoResponse->getContent(),
                        true
                    );

                /*
                |--------------------------------------------------------------------------
                | REFRESH COMPANY AGAIN
                |--------------------------------------------------------------------------
                */

                $company->refresh();

                \Log::info(

                    'ZOHO CONTACT AUTO CREATED AFTER EMAIL VERIFY',

                    [

                        'company_id' =>
                            $company->id,

                        'zoho_id' =>
                            $company->zoho_id,

                        'response' =>
                            $zohoData
                    ]
                );

            } catch (\Throwable $e) {

                \Log::error(

                    'ZOHO AUTO CONTACT CREATE FAILED',

                    [

                        'company_id' =>
                            $company->id,

                        'message' =>
                            $e->getMessage(),

                        'line' =>
                            $e->getLine(),

                        'file' =>
                            $e->getFile()
                    ]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | FINAL RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'status'     => true,

            'role'       => $company->role,

            'user_id'    => $company->id,

            'company_id' => $company->id,

            'zoho_id'    => $company->zoho_id,

            'message'    => 'Email verified successfully'

        ], 200);

    } catch (\Throwable $e) {

        \Log::error(

            'VERIFY EMAIL OTP FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile(),

                'trace' =>
                    substr(
                        $e->getTraceAsString(),
                        0,
                        3000
                    )
            ]
        );

        return response()->json([

            'status'  => false,

            'message' => $e->getMessage()

        ], 500);
    }
}
public function createContactFromCompany(Request $request)
{
    $validator = Validator::make($request->all(), [
        'user_id'    => 'required|exists:companies,id',
        'company_id' => 'required|exists:companies,id',
        'role'       => 'nullable'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    // ==========================================
    // Zoho credential owner
    // ==========================================
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

    // ==========================================
    // TARGET COMPANY
    // ==========================================
    $company = Company::find($request->user_id);

    if (!$company) {
        return response()->json([
            'status' => false,
            'error'  => 'Company data not found.'
        ], 404);
    }

    // ==========================================
    // PREVENT DUPLICATE
    // ==========================================
    if ($company->zoho_id) {
        return response()->json([
            'status'  => false,
            'message' => 'Zoho contact already exists'
        ], 409);
    }

    // ==========================================
    // PAYLOAD
    // ==========================================
    $payload = [
        "contact_name"     => $company->business_name . ' ' . $company->company_code,
        "company_name"     => $company->trade_name ?? $company->business_name,
        "has_transaction"  => true,
        "contact_type"     => $request->role != 5 ? 'vendor' : 'customer',

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

            $updateData = [
                'zoho_id' => $body['contact']['contact_id'],
                'z_json'  => json_encode($body['contact']),
            ];

            // ==========================================
            // ROLE 2 → COPY ZOHO CREDENTIALS
            // ==========================================
            if ($request->role == 2) {
                $updateData = array_merge($updateData, [
                    'zoho_access_token'  => $zohoCompany->zoho_access_token,
                    'zoho_org_id'        => $zohoCompany->zoho_org_id,
                    'zoho_client_id'     => $zohoCompany->zoho_client_id,
                    'zoho_client_secret' => $zohoCompany->zoho_client_secret,
                    'zoho_redirect_uri'  => $zohoCompany->zoho_redirect_uri,
                    'zoho_refresh_token' => $zohoCompany->zoho_refresh_token,
                ]);
            }

            $company->update($updateData);

            // ==========================================
            //  SYNC WITH ORG_USERS_MASTER
            // ==========================================
            $orgUser = OrgUsersMaster::where('email', $company->contact_email)
                ->orWhere('phone', $company->contact_phone)
                ->first();

            if ($orgUser) {
                $orgUser->update([
                    'zoho_contact_id' => $company->zoho_id,
                    'is_mail_verified'=> 1
                ]);
            }
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