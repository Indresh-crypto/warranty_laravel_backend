<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\Company;
use App\Models\WDevice;
use App\Models\WLead;
use App\Models\SubscribedPackage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
class AdminController extends Controller
{
  
public function login(Request $request)
{
    
    $validator = Validator::make($request->all(),[
        'username' => 'required_without:email',
        'email' => 'required_without:username',
        'password' => 'required'
    ],[
        'username.required_without' => 'Username or email is required',
        'email.required_without' => 'Email or username is required',
        'password.required' => 'Password is required'
    ]);

    if($validator->fails()){
        return response()->json([
            'status'=>false,
            'message'=>'Validation error',
            'errors'=>$validator->errors()
        ],422);
    }

    $admin = Admin::where('username',$request->username)
                ->orWhere('email',$request->email)
                ->first();

    if(!$admin || !Hash::check($request->password,$admin->password)){
        return response()->json([
            'status'=>false,
            'message'=>'Invalid login credentials'
        ],401);
    }

    if($admin->status == 0){
        return response()->json([
            'status' => false,
            'message' => 'Admin account disabled'
        ], 403);
    }

    return response()->json([
        'status'=>true,
        'message'=>'Login successful',
        'data'=>[
            'id'            => $admin->id,
            'name'          => $admin->name,
            'username'      => $admin->username,
            'email'         => $admin->email,
            'status'        => $admin->status,
            'type'  => 'admin'   
        ]
    ]);
}
    /**
     * Get Admin Profile
     */
    public function profile($id)
    {
        $admin = Admin::find($id);

        if(!$admin){
            return response()->json([
                'status'=>false,
                'message'=>'Admin not found'
            ],404);
        }

        return response()->json([
            'status'=>true,
            'message'=>'Admin profile',
            'data'=>$admin
        ]);
    }
    
    
    
    public function dashboardSummary(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | CACHE KEY
        |--------------------------------------------------------------------------
        */

        $cacheKey = 'dashboard_summary_v1';

        /*
        |--------------------------------------------------------------------------
        | CACHE (5 MINUTES)
        |--------------------------------------------------------------------------
        */

        $data = Cache::remember(

            $cacheKey,

            now()->addMinutes(5),

            function () {

                /*
                |--------------------------------------------------------------------------
                | DATES
                |--------------------------------------------------------------------------
                */

                $today = now()->toDateString();

                /*
                |--------------------------------------------------------------------------
                | TOTAL RETAILERS
                |--------------------------------------------------------------------------
                |
                | role = 5 => retailer
                |
                |--------------------------------------------------------------------------
                */

                $totalRetailers = Company::where(
                        'role',
                        5
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | ACTIVE RETAILERS
                |--------------------------------------------------------------------------
                |
                | Logged in / active recently
                |--------------------------------------------------------------------------
                */

                $activeRetailers = Company::where(
                        'role',
                        5
                    )
                    ->whereNotNull(
                        'last_connected_date'
                    )
                    ->whereDate(
                        'last_connected_date',
                        '>=',
                        now()->subDays(7)
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | CHANNEL PARTNERS
                |--------------------------------------------------------------------------
                */

                $totalChannelPartners = Company::whereIn(
                        'role',
                        [2, 3, 4]
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | WARRANTY SOLD
                |--------------------------------------------------------------------------
                */

                $totalWarrantySold = WDevice::count();

                /*
                |--------------------------------------------------------------------------
                | TOTAL SALES
                |--------------------------------------------------------------------------
                */

                $totalSales = WDevice::sum(
                    'product_price'
                );

                /*
                |--------------------------------------------------------------------------
                | TODAY SALES
                |--------------------------------------------------------------------------
                */

                $todaysSales = WDevice::whereDate(
                        'created_at',
                        $today
                    )
                    ->sum('product_price');

                /*
                |--------------------------------------------------------------------------
                | TOTAL ONBOARDED
                |--------------------------------------------------------------------------
                */

                $totalOnboarded = Company::where(
                        'role',
                        5
                    )
                    ->whereNotNull('created_at')
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | TODAY ONBOARDED
                |--------------------------------------------------------------------------
                */

                $todaysOnboarded = Company::where(
                        'role',
                        5
                    )
                    ->whereDate(
                        'created_at',
                        $today
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | TOTAL SUBSCRIPTIONS
                |--------------------------------------------------------------------------
                */

                $totalSubscription = SubscribedPackage::where(
                        'status',
                        1
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | TODAY SUBSCRIPTIONS
                |--------------------------------------------------------------------------
                */

                $todaysSubscription = SubscribedPackage::where(
                        'status',
                        1
                    )
                    ->whereDate(
                        'created_at',
                        $today
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | LEADS
                |--------------------------------------------------------------------------
                */

                $totalLeads = WLead::count();

                $todayLeads = WLead::whereDate(
                        'created_at',
                        $today
                    )
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | RESPONSE
                |--------------------------------------------------------------------------
                */

                return [

                    'total_retailers' =>

                        (int) $totalRetailers,

                    'active_retailers' =>

                        (int) $activeRetailers,

                    'total_channel_partners' =>

                        (int) $totalChannelPartners,

                    'total_warranty_sold' =>

                        (int) $totalWarrantySold,

                    'total_sales' =>

                        round($totalSales, 2),

                    'todays_sales' =>

                        round($todaysSales, 2),

                    'total_onboarded' =>

                        (int) $totalOnboarded,

                    'todays_onboarded' =>

                        (int) $todaysOnboarded,

                    'total_subscription' =>

                        (int) $totalSubscription,

                    'todays_subscription' =>

                        (int) $todaysSubscription,

                    'total_leads' =>

                        (int) $totalLeads,

                    'todays_leads' =>

                        (int) $todayLeads,
                ];
            }
        );

        return response()->json([

            'success' => true,

            'message' =>
                'Dashboard summary fetched successfully',

            'data' => $data
        ]);

    } catch (\Throwable $e) {

        \Log::error(

            'DASHBOARD SUMMARY FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return response()->json([

            'success' => false,

            'message' =>
                'Something went wrong'
        ], 500);
    }
}


public function warrantySales(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | DATE FILTERS
        |--------------------------------------------------------------------------
        */

        if (
            $request->from_date &&
            $request->to_date
        ) {

            $startDate = Carbon::parse(
                $request->from_date
            )->startOfDay();

            $endDate = Carbon::parse(
                $request->to_date
            )->endOfDay();

        } else {

            $days = (int) (
                $request->days ?? 30
            );

            if ($days <= 0) {
                $days = 30;
            }

            $startDate = now()
                ->subDays($days - 1)
                ->startOfDay();

            $endDate = now()
                ->endOfDay();
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL DAYS
        |--------------------------------------------------------------------------
        */

        $totalDays =
            $startDate->diffInDays($endDate) + 1;

        /*
        |--------------------------------------------------------------------------
        | CACHE KEY
        |--------------------------------------------------------------------------
        */

        $cacheKey =
            'dashboard_warranty_sales_' .
            md5(
                $startDate .
                '_' .
                $endDate
            );

        /*
        |--------------------------------------------------------------------------
        | CACHE
        |--------------------------------------------------------------------------
        */

        $data = Cache::remember(

            $cacheKey,

            now()->addMinutes(5),

            function () use (
                $startDate,
                $endDate,
                $totalDays
            ) {

                /*
                |--------------------------------------------------------------------------
                | QUERY
                |--------------------------------------------------------------------------
                */

                $sales = WDevice::selectRaw('
                        DATE(created_at) as date,
                        COUNT(id) as sales
                    ')
                    ->whereBetween(
                        'created_at',
                        [$startDate, $endDate]
                    )
                    ->groupBy('date')
                    ->orderBy('date', 'asc')
                    ->get()
                    ->keyBy('date');

                /*
                |--------------------------------------------------------------------------
                | FILL MISSING DATES
                |--------------------------------------------------------------------------
                */

                $result = [];

                for ($i = 0; $i < $totalDays; $i++) {

                    $date = $startDate
                        ->copy()
                        ->addDays($i)
                        ->format('Y-m-d');

                    $result[] = [

                        'date' => $date,

                        'sales' => (int) (
                            $sales[$date]->sales ?? 0
                        )
                    ];
                }

                return $result;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' =>
                'Warranty sales fetched successfully',

            'filters' => [

                'from_date' =>
                    $startDate
                        ->format('Y-m-d'),

                'to_date' =>
                    $endDate
                        ->format('Y-m-d')
            ],

            'data' => $data
        ]);

    } catch (\Throwable $e) {

        \Log::error(

            'WARRANTY SALES API FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return response()->json([

            'success' => false,

            'message' =>
                'Something went wrong'
        ], 500);
    }
}


public function leadsDashboard(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | DATE FILTERS
        |--------------------------------------------------------------------------
        */

        if (
            $request->start_date &&
            $request->end_date
        ) {

            $startDate = Carbon::parse(
                $request->start_date
            )->startOfDay();

            $endDate = Carbon::parse(
                $request->end_date
            )->endOfDay();

        } else {

            $days = (int) (
                $request->days ?? 30
            );

            if ($days <= 0) {
                $days = 30;
            }

            $startDate = now()
                ->subDays($days - 1)
                ->startOfDay();

            $endDate = now()
                ->endOfDay();
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL DAYS
        |--------------------------------------------------------------------------
        */

        $totalDays =
            $startDate->diffInDays($endDate) + 1;

        /*
        |--------------------------------------------------------------------------
        | CACHE KEY
        |--------------------------------------------------------------------------
        */

        $cacheKey =
            'dashboard_leads_' .
            md5(
                $startDate .
                '_' .
                $endDate
            );

        /*
        |--------------------------------------------------------------------------
        | CACHE
        |--------------------------------------------------------------------------
        */

        $response = Cache::remember(

            $cacheKey,

            now()->addMinutes(5),

            function () use (
                $startDate,
                $endDate,
                $totalDays
            ) {

                /*
                |--------------------------------------------------------------------------
                | FETCH LEADS
                |--------------------------------------------------------------------------
                */

                $leads = WLead::selectRaw('

                        DATE(created_at) as date,

                        COUNT(id) as created_count,

                        SUM(
                            CASE
                                WHEN status IN ("in process","in_progress")
                                THEN 1
                                ELSE 0
                            END
                        ) as in_progress_count,

                        SUM(
                            CASE
                                WHEN status = "won"
                                THEN 1
                                ELSE 0
                            END
                        ) as won_count,

                        SUM(
                            CASE
                                WHEN status IN (
                                    "lost",
                                    "reject",
                                    "rejected"
                                )
                                THEN 1
                                ELSE 0
                            END
                        ) as reject_count
                    ')

                    ->whereBetween(
                        'created_at',
                        [$startDate, $endDate]
                    )

                    ->groupBy('date')

                    ->orderBy('date', 'asc')

                    ->get()

                    ->keyBy('date');

                /*
                |--------------------------------------------------------------------------
                | FINAL DATA
                |--------------------------------------------------------------------------
                */

                $result = [];

                /*
                |--------------------------------------------------------------------------
                | OVERALL TOTALS
                |--------------------------------------------------------------------------
                */

                $totalCreated = 0;

                $totalInProgress = 0;

                $totalWon = 0;

                $totalReject = 0;

                /*
                |--------------------------------------------------------------------------
                | LOOP DAYS
                |--------------------------------------------------------------------------
                */

                for ($i = 0; $i < $totalDays; $i++) {

                    $date = $startDate
                        ->copy()
                        ->addDays($i)
                        ->format('Y-m-d');

                    $row = $leads[$date] ?? null;

                    $created = (int) (
                        $row->created_count ?? 0
                    );

                    $inProgress = (int) (
                        $row->in_progress_count ?? 0
                    );

                    $won = (int) (
                        $row->won_count ?? 0
                    );

                    $reject = (int) (
                        $row->reject_count ?? 0
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | PUSH DAILY DATA
                    |--------------------------------------------------------------------------
                    */

                    $result[] = [

                        'date' => $date,

                        'created' => $created,

                        'in_progress' => $inProgress,

                        'won' => $won,

                        'reject' => $reject,
                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | TOTALS
                    |--------------------------------------------------------------------------
                    */

                    $totalCreated += $created;

                    $totalInProgress += $inProgress;

                    $totalWon += $won;

                    $totalReject += $reject;
                }

                /*
                |--------------------------------------------------------------------------
                | CONVERSION RATE
                |--------------------------------------------------------------------------
                */

                $conversionRate = 0;

                if ($totalCreated > 0) {

                    $conversionRate = round(

                        ($totalWon / $totalCreated) * 100,

                        2
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | RETURN
                |--------------------------------------------------------------------------
                */

                return [

                    'daily' => $result,

                    'summary' => [

                        'created' =>
                            $totalCreated,

                        'in_progress' =>
                            $totalInProgress,

                        'won' =>
                            $totalWon,

                        'reject' =>
                            $totalReject,

                        'conversion_rate' =>
                            $conversionRate
                    ]
                ];
            }
        );

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' =>
                'Leads dashboard fetched successfully',

            'filters' => [

                'start_date' =>
                    $startDate
                        ->format('Y-m-d'),

                'end_date' =>
                    $endDate
                        ->format('Y-m-d')
            ],

            'data' =>
                $response['daily'],

            'summary' =>
                $response['summary']
        ]);

    } catch (\Throwable $e) {

        \Log::error(

            'LEADS DASHBOARD API FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return response()->json([

            'success' => false,

            'message' =>
                'Something went wrong'
        ], 500);
    }
}

public function retailerStatusDashboard(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | DATE FILTERS
        |--------------------------------------------------------------------------
        */

        if (
            $request->from_date &&
            $request->to_date
        ) {

            $startDate = Carbon::parse(
                $request->from_date
            )->startOfDay();

            $endDate = Carbon::parse(
                $request->to_date
            )->endOfDay();

        } else {

            $days = (int) (
                $request->days ?? 30
            );

            if ($days <= 0) {
                $days = 30;
            }

            $startDate = now()
                ->subDays($days - 1)
                ->startOfDay();

            $endDate = now()
                ->endOfDay();
        }

        /*
        |--------------------------------------------------------------------------
        | TOTAL DAYS
        |--------------------------------------------------------------------------
        */

        $totalDays =
            $startDate->diffInDays($endDate) + 1;

        /*
        |--------------------------------------------------------------------------
        | CACHE KEY
        |--------------------------------------------------------------------------
        */

        $cacheKey =
            'dashboard_retailer_status_' .
            md5(
                $startDate .
                '_' .
                $endDate
            );

        /*
        |--------------------------------------------------------------------------
        | CACHE
        |--------------------------------------------------------------------------
        */

        $data = Cache::remember(

            $cacheKey,

            now()->addMinutes(5),

            function () use (
                $startDate,
                $endDate,
                $totalDays
            ) {

                /*
                |--------------------------------------------------------------------------
                | QUERY
                |--------------------------------------------------------------------------
                |
                | flag ENUM:
                | working
                | inactive
                | activation_pending
                |
                |--------------------------------------------------------------------------
                */

                $retailers = Company::selectRaw('
                        DATE(created_at) as date,

                        SUM(
                            CASE
                                WHEN flag = "working"
                                THEN 1
                                ELSE 0
                            END
                        ) as using_count,

                        SUM(
                            CASE
                                WHEN flag = "inactive"
                                THEN 1
                                ELSE 0
                            END
                        ) as not_using_count,

                        SUM(
                            CASE
                                WHEN flag = "activation_pending"
                                THEN 1
                                ELSE 0
                            END
                        ) as pending_count
                    ')
                    ->where('role', 5)

                    ->whereBetween(
                        'created_at',
                        [$startDate, $endDate]
                    )

                    ->groupBy('date')

                    ->orderBy('date', 'asc')

                    ->get()

                    ->keyBy('date');

                /*
                |--------------------------------------------------------------------------
                | FILL MISSING DATES
                |--------------------------------------------------------------------------
                */

                $result = [];

                for ($i = 0; $i < $totalDays; $i++) {

                    $date = $startDate
                        ->copy()
                        ->addDays($i)
                        ->format('Y-m-d');

                    $row =
                        $retailers[$date]
                        ?? null;

                    $result[] = [

                        'date' => $date,

                        'using' => (int) (
                            $row->using_count ?? 0
                        ),

                        'not_using' => (int) (
                            $row->not_using_count ?? 0
                        ),

                        'pending' => (int) (
                            $row->pending_count ?? 0
                        ),
                    ];
                }

                return $result;
            }
        );

        /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' =>
                'Retailer status dashboard fetched successfully',

            'filters' => [

                'from_date' =>
                    $startDate
                        ->format('Y-m-d'),

                'to_date' =>
                    $endDate
                        ->format('Y-m-d')
            ],

            'data' => $data
        ]);

    } catch (\Throwable $e) {

        \Log::error(

            'RETAILER STATUS DASHBOARD FAILED',

            [

                'message' =>
                    $e->getMessage(),

                'line' =>
                    $e->getLine(),

                'file' =>
                    $e->getFile()
            ]
        );

        return response()->json([

            'success' => false,

            'message' =>
                'Something went wrong'

        ], 500);
    }
}

}