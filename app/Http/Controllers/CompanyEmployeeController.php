<?php

namespace App\Http\Controllers;

use App\Models\CompanyEmployee;
use App\Models\WLead;
use App\Models\Company;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use DB;
use App\Events\EmployeeCreated;
use App\Mail\EmployeeResetPasswordMail;
use Illuminate\Support\Facades\Mail;

use App\Models\WDevice;
use Carbon\Carbon;

class CompanyEmployeeController extends Controller
{
    /**
     * Store Employee
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
        'company_id'       => 'required|integer',
        'first_name'       => 'required|string',
        'personal_phone'   => 'required|string|unique:company_employee,personal_phone',
        'official_email'   => 'nullable|email|unique:company_employee,official_email',
        'password'         => 'nullable',
        'employee_type'      => 'required',
        'logo'              => 'nullable',
        'domain'            => 'nullable',
        'title'             => 'nullable'
        ],[
        'company_id.required'     => 'Company ID is required',
        'first_name.required'     => 'First name is required',
        'personal_phone.required' => 'Phone number is required'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. CREATE EMPLOYEE (WITHOUT employee_id)
    |--------------------------------------------------------------------------
    */
    $emp = CompanyEmployee::create([
        'company_id'        => $request->company_id,
        'first_name'        => $request->first_name,
        'middle_name'       => $request->middle_name,
        'last_name'         => $request->last_name,

        'personal_phone'    => $request->personal_phone,
        'official_phone'    => $request->official_phone,
        'official_email'    => $request->official_email,

        'type_of_user'      => $request->type_of_user,
        'position'          => $request->position,
        'reports_to'        => $request->reports_to,

        'categories'        => $request->categories,
        'handle'            => $request->handle,
        'pincodes'          => $request->pincodes,
        'photo_url'         => $request->photo_url,
        'location_mode'     => $request->location_mode,

        'state'             => $request->state,
        'district'          => $request->district,
        'employee_type'     => $request->employee_type,
        'logo'             => $request->logo,
        'domain'           => $request->domain,
        'title'            => $request->title
    ]);

    /*
    |--------------------------------------------------------------------------
    | 2. UPDATE employee_id AS EMP-{ID}
    |--------------------------------------------------------------------------
    */
   
    $plainPassword = (string) random_int(100000, 999999);
    
    $emp->password = Hash::make($plainPassword);

    $emp->employee_id = 'EMP-' . $emp->id;
    $emp->save();

    event(new EmployeeCreated($emp, $plainPassword));
    
    return response()->json([
        'status'  => true,
        'message' => 'Employee created successfully',
        'data'    => $emp->fresh() // return updated record
    ], 201);
}

    /**
     * Login Employee (email or phone)
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'personal_phone' => 'required_without:official_email',
            'official_email' => 'required_without:personal_phone',
            'password'=>'required'
        ]);

        if($validator->fails()){
            return response()->json([
                'status'=>false,
                'message'=>'Validation error',
                'errors'=>$validator->errors()
            ],422);
        }

        $emp = CompanyEmployee::where('personal_phone',$request->personal_phone)
                ->orWhere('official_email',$request->official_email)
                ->first();

        if(!$emp || !Hash::check($request->password,$emp->password)){
            return response()->json([
                'status'=>false,
                'message'=>'Invalid login credentials'
            ],401);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Login successful',
            'data'=>$emp
        ]);
    }

    /**
     * All Employees by company_id
     */
    public function allEmployees(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'company_id' => 'required|integer',
        'per_page'   => 'sometimes|integer|min:1|max:100'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => 'Validation error',
            'errors'  => $validator->errors()
        ], 422);
    }

    $perPage = $request->per_page ?? 10;

    $employees = CompanyEmployee::query()
        ->where('company_id', $request->company_id)

        // 🔍 Name search
        ->when($request->name, function ($q) use ($request) {
            $q->where(function ($sub) use ($request) {
                $sub->where('first_name', 'like', "%{$request->name}%")
                    ->orWhere('middle_name', 'like', "%{$request->name}%")
                    ->orWhere('last_name', 'like', "%{$request->name}%");
            });
        })

        ->when($request->position, fn ($q) =>
            $q->whereIn('position', array_map('trim', explode(',', $request->position)))
        )

        ->when($request->type_of_user, fn ($q) => $q->where('type_of_user', $request->type_of_user))
        ->when($request->employee_type, fn ($q) => $q->where('employee_type', $request->employee_type))
        ->when($request->reports_to, fn ($q) => $q->where('reports_to', $request->reports_to))

        ->when($request->state, fn ($q) =>
            $q->whereIn('state', array_map('trim', explode(',', $request->state)))
        )

        ->when($request->district, fn ($q) =>
            $q->whereIn('district', array_map('trim', explode(',', $request->district)))
        )

        ->when($request->phone, function ($q) use ($request) {
            $q->where(function ($sub) use ($request) {
                $sub->where('personal_phone', $request->phone)
                    ->orWhere('official_phone', $request->phone);
            });
        })

        ->when($request->email, fn ($q) => $q->where('official_email', $request->email))
        ->when($request->location_mode, fn ($q) => $q->where('location_mode', $request->location_mode))


->when($request->search_value, function ($q) use ($request) {
    $search = trim($request->search_value);

    $q->where(function ($sub) use ($search) {
        $sub->where('first_name', 'like', "%{$search}%")
            ->orWhere('middle_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('official_email', 'like', "%{$search}%")
            ->orWhere('personal_phone', 'like', "%{$search}%")
            ->orWhere('official_phone', 'like', "%{$search}%")
            ->orWhere('position', 'like', "%{$search}%")
            ->orWhere('state', 'like', "%{$search}%")
            ->orWhere('district', 'like', "%{$search}%")
            ->orWhere('employee_type', 'like', "%{$search}%")
            ->orWhere('type_of_user', 'like', "%{$search}%");
    });
})

        ->orderBy('id', 'desc')
        ->paginate($perPage)

        // 🔥 Attach lead summary per employee (pagination-safe)
        ->through(function ($emp) {

            $leadQuery = WLead::where('created_by_id', $emp->id);

            $totalLeads = $leadQuery->count();

            $statusCounts = [
                'new'        => (clone $leadQuery)->where('status', 'new')->count(),
                'in_process' => (clone $leadQuery)->where('status', 'in process')->count(),
                'won'        => (clone $leadQuery)->where('status', 'won')->count(),
                'lost'       => (clone $leadQuery)->where('status', 'lost')->count(),
            ];

            $statusAmounts = [
                'new'        => (clone $leadQuery)->where('status', 'new')->sum('lead_amount'),
                'in_process' => (clone $leadQuery)->where('status', 'in process')->sum('lead_amount'),
                'won'        => (clone $leadQuery)->where('status', 'won')->sum('lead_amount'),
                'lost'       => (clone $leadQuery)->where('status', 'lost')->sum('lead_amount'),
            ];

            $leadTypeCounts = [
                'type_2' => (clone $leadQuery)->where('lead_type', 2)->count(),
                'type_4' => (clone $leadQuery)->where('lead_type', 4)->count(),
                'type_5' => (clone $leadQuery)->where('lead_type', 5)->count(),
            ];

            $totalLeadAmount = (clone $leadQuery)->sum('lead_amount');

            $conversionRate = $totalLeads > 0
                ? round(($statusCounts['won'] / $totalLeads) * 100, 2)
                : 0;

            $emp->summary = [
                'total_leads'       => $totalLeads,
                'status_counts'     => $statusCounts,
                'status_amounts'    => $statusAmounts,
                'lead_type_counts'  => $leadTypeCounts,
                'total_lead_amount' => $totalLeadAmount,
                'conversion_rate'   => $conversionRate . '%'
            ];

            return $emp;
        });

    return response()->json([
        'status'  => true,
        'message' => 'Employees list with lead summary',
        'data'    => $employees
    ]);
} 
        /**
         * Dynamic Filter Search
         */
    public function search(Request $request)
    {
        $query = CompanyEmployee::with([
            'company:id,business_name,trade_name,contact_email,contact_phone'
        ]);
    
        // 🔹 Filter by company
        if ($request->company_id) {
            $query->where('company_id', $request->company_id);
        }
    
        // 🔹 Search employee fields + company name
        if ($request->search) {
            $search = $request->search;
    
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('personal_phone', 'LIKE', "%{$search}%")
                  ->orWhere('official_email', 'LIKE', "%{$search}%")
                  ->orWhere('employee_id', 'LIKE', "%{$search}%")
                  ->orWhere('position', 'LIKE', "%{$search}%")
                  ->orWhere('type_of_user', 'LIKE', "%{$search}%")
                  ->orWhereHas('company', function ($c) use ($search) {
                      $c->where('business_name', 'LIKE', "%{$search}%")
                        ->orWhere('trade_name', 'LIKE', "%{$search}%");
                  });
            });
        }
    
        $employees = $query->get()->map(function ($employee) {
            return [
                'id'              => $employee->id,
                'employee_id'     => $employee->employee_id,
                'full_name'       => $employee->full_name,
                'position'        => $employee->position,
                'type_of_user'    => $employee->type_of_user,
                'personal_phone'  => $employee->personal_phone,
                'official_email'  => $employee->official_email,
    
                'company' => $employee->company ? [
                    'id'            => $employee->company->id,
                    'business_name' => $employee->company->business_name,
                    'trade_name'    => $employee->company->trade_name,
                    'email'         => $employee->company->contact_email,
                    'phone'         => $employee->company->contact_phone,
                ] : null,
            ];
        });
    
        return response()->json([
            'status'  => true,
            'message' => 'Filtered employee list with company details',
            'data'    => $employees
        ]);
    }
    
    public function update(Request $request, $id)
    {
    $emp = CompanyEmployee::find($id);

    if (!$emp) {
        return response()->json([
            'status' => false,
            'message' => 'Employee not found'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'first_name' => 'sometimes|string',
        'personal_phone' => 'sometimes|string|unique:company_employee,personal_phone,' . $id,
        'official_email' => 'sometimes|email|unique:company_employee,official_email,' . $id,
    ],[
        'first_name.string' => 'Invalid first name',
        'personal_phone.unique' => 'This phone already exists',
        'official_email.email' => 'Invalid email format',
        'official_email.unique' => 'Email already exists',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors()
        ], 422);
    }

    $emp->update($request->all());

    return response()->json([
        'status' => true,
        'message' => 'Employee updated successfully',
        'data' => $emp
    ]);
}

    public function changePassword(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required|min:6',
        ],[
            'old_password.required' => 'Old password is required',
            'new_password.required' => 'New password is required',
            'new_password.min' => 'New password must be at least 6 characters',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }
    
        $emp = CompanyEmployee::find($id);
    
        if (!$emp) {
            return response()->json([
                'status' => false,
                'message' => 'Employee not found'
            ], 404);
        }
    
        if (!Hash::check($request->old_password, $emp->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Old password is incorrect'
            ], 401);
        }
    
        $emp->password = Hash::make($request->new_password);
        $emp->save();
    
        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully'
        ]);
    }
    
    public function employeeAreaWiseReport(Request $request)
    {
        $employees = CompanyEmployee::with('manager')->get();
    
        $response = [];
    
        foreach ($employees as $emp) {
    
            $states    = $emp->state ? array_map('trim', explode(',', $emp->state)) : [];
            $districts = $emp->district ? array_map('trim', explode(',', $emp->district)) : [];
    
            // 🔹 Assign type
            $assignType = !empty($states) ? 'STATE' : 'DISTRICT';
            $assignName = $assignType === 'STATE' ? $states : $districts;
    
            // 🔹 Fetch companies (shops)
           $companies = Company::query()
                ->where('role', 5) // ✅ ONLY role = 5 companies (shops)
                ->when($assignType === 'STATE', function ($q) use ($states) {
                    $q->whereIn('state', $states);
                })
                ->when($assignType === 'DISTRICT', function ($q) use ($districts) {
                    $q->whereIn('district', $districts);
                })
                ->get();
    
            // 🔹 Group area-wise
            $areas = $companies
                ->groupBy(['state', 'district'])
                ->map(function ($districts, $state) {
                    return collect($districts)->map(function ($items, $district) use ($state) {
                        return [
                            'state'    => $state,
                            'district' => $district,
                            'shops'    => $items->count()
                        ];
                    })->values();
                })
                ->flatten(1)
                ->values();
    
            $employeeData = [
                'employee_name' => trim($emp->first_name . ' ' . $emp->last_name),
                'position'      => $emp->position,
                'assign_type'   => $assignType,
                'assign_name'   => $assignName,
                'total_shops'   => $companies->count(),
                'areas'         => $areas
            ];
    
            // 🔹 Reporting hierarchy
            if ($emp->reports_to) {
                $manager = CompanyEmployee::find($emp->reports_to);
    
                if ($manager) {
                    $employeeData['report_to'] = trim($manager->first_name . ' ' . $manager->last_name);
                    $employeeData['report_to_state'] = $manager->state
                        ? explode(',', $manager->state)[0]
                        : null;
                }
            }
    
            $response[] = $employeeData;
        }
    
        return response()->json([
            'success' => true,
            'data'    => $response
        ]);
    }
    
    public function stateDistrictShopCount()
    {
        $data = Company::query()
            ->where('role', 5)
            ->whereNotNull('state')
            ->whereNotNull('district')
            ->select(
                'state',
                'district',
                DB::raw('COUNT(*) as shops')
            )
            ->groupBy('state', 'district')
            ->orderBy('state')
            ->orderBy('district')
            ->get();
    
        $totalShops = $data->sum('shops');
    
        return response()->json([
            'success'      => true,
            'total_shops' => $totalShops,
            'data'         => $data
        ]);
    }

    public function resetEmployeePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|string|exists:company_employee,employee_id',
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }
    
        $employee = CompanyEmployee::where('employee_id', $request->employee_id)->first();
    
        // 🔐 Generate random 6-digit password
        $newPassword = random_int(100000, 999999);
    
        // 🔒 Update password (hashed)
        $employee->update([
            'password' => Hash::make($newPassword)
        ]);
    
        // 📧 Send email
        if (!empty($employee->official_email)) {
            Mail::to($employee->official_email)
                ->send(new EmployeeResetPasswordMail($employee, $newPassword));
        }
    
        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully. New password sent to registered email.'
        ], 200);
    }

    public function setEmployeePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|string|exists:company_employee,employee_id',
            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed' // checks password_confirmation
            ],
        ], [
            'password.confirmed' => 'Password confirmation does not match'
        ]);
    
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
    
        $employee = CompanyEmployee::where('employee_id', $request->employee_id)->first();
    
    
        
        $employee->update([
            'password' => Hash::make($request->password),
            'password_changed_at' => now()
        ]);
    
        return response()->json([
            'status' => true,
            'message' => 'Password set successfully. You can now login with your new password.'
        ], 200);
    }
    
    
    public function employeeDashboard(Request $request)
    {
        $baseCompanyQuery = Company::query()
            ->where('role', 5)
            ->whereNotNull('state')
            ->whereNotNull('district');
    
        // ==========================
        // FILTERS
        // ==========================
    
        if ($request->filled('states')) {
            $states = array_map('trim', explode(',', $request->states));
            $baseCompanyQuery->whereIn('state', $states);
        }
    
        if ($request->filled('districts')) {
            $districts = array_map('trim', explode(',', $request->districts));
            $baseCompanyQuery->whereIn('district', $districts);
        }
    
        // ==========================
        // ASSIGNED RETAILERS
        // ==========================
    
        $assignedRetailers = (clone $baseCompanyQuery)->count();
    
        $retailerIds = (clone $baseCompanyQuery)->pluck('id');
    
        // ==========================
        // CONNECTED RETAILERS (7 DAYS)
        // ==========================
    
        $connectedRetailers = Company::whereIn('id', $retailerIds)
            ->whereNotNull('last_connected_date')
            ->where('last_connected_date', '>=', Carbon::now()->subDays(7))
            ->count();
    
        $notConnectedRetailers = $assignedRetailers - $connectedRetailers;
    
        // ==========================
        // USING OUR PRODUCT (7 DAYS)
        // ==========================
    
        $usingRetailers = WDevice::whereIn('retailer_id', $retailerIds)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->distinct('retailer_id')
            ->count('retailer_id');
    
        $notUsingRetailers = $assignedRetailers - $usingRetailers;
    
        // ==========================
        // THIS MONTH SALES
        // ==========================
    
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();
    
        $monthlySales = WDevice::whereIn('retailer_id', $retailerIds)
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum('product_price');
    
        // ==========================
        // OUTSTANDING AMOUNT
        // ==========================
    

        $outstandingAmount = WDevice::whereIn('retailer_id', $retailerIds)
            ->where('is_pay_later', 1)
            ->where(function ($q) {
                $q->whereNull('invoice_status')
                  ->orWhere('invoice_status', '!=', 'paid');
            })
            ->sum('product_price');

        // ==========================
        // RESPONSE
        // ==========================
    
        return response()->json([
            'success' => true,
    
            'assigned_retailers' => $assignedRetailers,
    
            'connected_retailers' => [
                'connected'     => $connectedRetailers,
                'not_connected' => $notConnectedRetailers
            ],
    
            'using_product' => [
                'using'     => $usingRetailers,
                'not_using' => $notUsingRetailers
            ],
    
            'sales' => [
                'this_month' => round($monthlySales, 2),
                'outstanding' => round($outstandingAmount, 2)
            ]
        ]);
    }
    
    public function retailerStatusSnapshot(Request $request)
    {
        // ======================
        // BASE QUERY (NEUTRAL)
        // ======================
    
        $baseQuery = Company::query()
            ->where('role', 5);
    
        // ======================
        // FILTERS
        // ======================
    
        if ($request->filled('company_id')) {
    
            // Company-only scope
            $baseQuery->where('company_id', $request->company_id);
    
        } else {
    
            // Location-based scope
            $baseQuery->whereNotNull('state')
                      ->whereNotNull('district');
    
            if ($request->filled('states')) {
                $states = array_map('trim', explode(',', $request->states));
                $baseQuery->whereIn('state', $states);
            }
    
            if ($request->filled('districts')) {
                $districts = array_map('trim', explode(',', $request->districts));
                $baseQuery->whereIn('district', $districts);
            }
        }
    
        // ======================
        // TOTAL RETAILERS
        // ======================
    
        $totalRetailers = (clone $baseQuery)->count();
    
        if ($totalRetailers === 0) {
            return response()->json([
                'success' => true,
                'data' => [
                    'active'    => ['count' => 0, 'total' => 0, 'percent' => 0],
                    'connected' => ['count' => 0, 'total' => 0, 'percent' => 0],
                    'using'     => ['count' => 0, 'total' => 0, 'percent' => 0],
                ]
            ]);
        }
    
        // ======================
        // RETAILER IDS
        // ======================
    
        $retailerIds = (clone $baseQuery)->pluck('id');
    
        // ======================
        // ACTIVE RETAILERS
        // ======================
    
        $activeCount = Company::whereIn('id', $retailerIds)
            ->where('status', 1)
            ->count();
    
        // ======================
        // CONNECTED (LAST 7 DAYS)
        // ======================
    
        $connectedCount = Company::whereIn('id', $retailerIds)
            ->whereNotNull('last_connected_date')
            ->where('last_connected_date', '>=', Carbon::now()->subDays(7))
            ->count();
    
        // ======================
        // USING PRODUCT (LAST 7 DAYS)
        // ======================
    
        $usingCount = WDevice::whereIn('retailer_id', $retailerIds)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->distinct('retailer_id')
            ->count('retailer_id');
    
        // ======================
        // PERCENTAGES
        // ======================
    
        $activePercent    = round(($activeCount / $totalRetailers) * 100);
        $connectedPercent = round(($connectedCount / $totalRetailers) * 100);
        $usingPercent     = round(($usingCount / $totalRetailers) * 100);
    
        // ======================
        // RESPONSE
        // ======================
    
        return response()->json([
            'success' => true,
            'data' => [
                'active' => [
                    'count'   => $activeCount,
                    'total'   => $totalRetailers,
                    'percent' => $activePercent
                ],
                'connected' => [
                    'count'   => $connectedCount,
                    'total'   => $totalRetailers,
                    'percent' => $connectedPercent
                ],
                'using' => [
                    'count'   => $usingCount,
                    'total'   => $totalRetailers,
                    'percent' => $usingPercent
                ]
            ]
        ]);
    }

public function salesBarChart(Request $request)
{
    $type = $request->get('type', 'day'); // day | month

    /*
    |--------------------------------------------------------------------------
    | FILTER RETAILERS
    |--------------------------------------------------------------------------
    */
    $companyQuery = Company::where('role', 5);

    if ($request->filled('states')) {
        $companyQuery->whereIn('state', explode(',', $request->states));
    }

    if ($request->filled('districts')) {
        $companyQuery->whereIn('district', explode(',', $request->districts));
    }

    $retailerIds = $companyQuery->pluck('id');

    /*
    |--------------------------------------------------------------------------
    | DATE RANGE LOGIC
    |--------------------------------------------------------------------------
    */
    if ($type === 'month') {

        // Month-wise → default last 12 months
        $fromDate = $request->filled('from_date')
            ? Carbon::parse($request->from_date)->startOfMonth()
            : Carbon::now()->subMonths(11)->startOfMonth();

        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfMonth()
            : Carbon::now()->endOfMonth();

    } else {

        // Day-wise → default current month
        $fromDate = $request->filled('from_date')
            ? Carbon::parse($request->from_date)->startOfDay()
            : Carbon::now()->startOfMonth();

        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->to_date)->endOfDay()
            : Carbon::now()->endOfMonth();
    }

    /*
    |--------------------------------------------------------------------------
    | SALES QUERY
    |--------------------------------------------------------------------------
    */
    $chartData = WDevice::whereIn('retailer_id', $retailerIds)
        ->whereBetween('created_at', [$fromDate, $toDate])
        ->select([
            DB::raw(
                $type === 'month'
                    ? "DATE_FORMAT(created_at,'%Y-%m') as period"
                    : "DATE(created_at) as period"
            ),
            DB::raw('SUM(product_price) as total_sales'),
            DB::raw("
                SUM(
                    CASE 
                        WHEN LOWER(invoice_status) = 'paid' 
                        THEN product_price 
                        ELSE 0 
                    END
                ) as paid_sales
            ")
        ])
        ->groupBy(DB::raw(
            $type === 'month'
                ? "DATE_FORMAT(created_at,'%Y-%m')"
                : "DATE(created_at)"
        ))
        ->orderBy('period')
        ->get();

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */
    return response()->json([
        'success' => true,
        'chart' => [
            'labels' => $chartData->pluck('period'),
            'datasets' => [
                [
                    'label' => 'Total Sales',
                    'data' => $chartData->pluck('total_sales'),
                ],
                [
                    'label' => 'Paid Sales',
                    'data' => $chartData->pluck('paid_sales'),
                ],
            ],
        ],
    ]);
}

    private function baseRetailerFilter(Request $request)
    {
        $query = Company::query()
            ->where('role', 5)
            ->whereNotNull('state')
            ->whereNotNull('district');
    
        if ($request->filled('states')) {
            $query->whereIn('state', explode(',', $request->states));
        }
    
        if ($request->filled('districts')) {
            $query->whereIn('district', explode(',', $request->districts));
        }
    
        return $query;
    }

    public function assignedRetailers(Request $request)
    {
        $data = $this->baseRetailerFilter($request)
            ->select('id','business_name','contact_person','contact_phone','state','district','status')
            ->orderBy('business_name')
            ->get();
    
        return response()->json([
            'success' => true,
            'count' => $data->count(),
            'data' => $data
        ]);
    }
    
    public function connectedRetailers(Request $request)
    {
        $data = $this->baseRetailerFilter($request)
            ->whereNotNull('last_connected_date')
            ->where('last_connected_date', '>=', Carbon::now()->subDays(7))
            ->select('id','business_name','contact_person','contact_phone','last_connected_date')
            ->orderByDesc('last_connected_date')
            ->get();
    
        return response()->json([
            'success' => true,
            'count' => $data->count(),
            'data' => $data
        ]);
    }
    public function usingProductRetailers(Request $request)
    {
        $retailerIds = $this->baseRetailerFilter($request)->pluck('id');
    
        $data = Company::whereIn('id', function ($query) use ($retailerIds) {
                $query->select('retailer_id')
                    ->from('w_devices')
                    ->whereIn('retailer_id', $retailerIds)
                    ->where('created_at', '>=', Carbon::now()->subDays(7))
                    ->groupBy('retailer_id');
            })
            ->select('id','business_name','contact_person','contact_phone')
            ->get();
    
        return response()->json([
            'success' => true,
            'count' => $data->count(),
            'data' => $data
        ]);
    }
}