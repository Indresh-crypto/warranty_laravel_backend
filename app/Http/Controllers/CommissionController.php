<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WDevice;
use Carbon\Carbon;
use DB;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;

class CommissionController extends Controller
{
     public function dashboard(Request $request)
    {
        $agentId   = $request->agent_id;
        $companyId = $request->company_id;
    
        // ---------------------------
        // Validation
        // ---------------------------
    
        if (!$agentId && !$companyId) {
            return response()->json([
                'status' => false,
                'message' => 'agent_id or company_id is required'
            ], 422);
        }
    
        // ---------------------------
        // Base Query
        // ---------------------------
    
        $baseQuery = WDevice::query();
    
        if ($agentId) {
            $baseQuery->where('agent_id', $agentId);
            $payoutColumn = 'other_payout';   // Agent payout
        }
    
        if ($companyId) {
            $baseQuery->where('company_id', $companyId);
            $payoutColumn = 'company_payout'; // Company payout
        }
    
        // ---------------------------
        // Dynamic Current Cycle Logic
        // ---------------------------
    
        $today = Carbon::now();
    
        if ($today->day <= 15) {
            // 1 to 15
            $startDate = $today->copy()->startOfMonth();
            $endDate   = $today->copy()->startOfMonth()->addDays(14);
        } else {
            // 16 to month end
            $startDate = $today->copy()->startOfMonth()->addDays(15);
            $endDate   = $today->copy()->endOfMonth();
        }
    
        // ---------------------------
        // Expected Commission (Current Cycle)
        // ---------------------------
    
        $expectedCommission = (clone $baseQuery)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->where('invoice_status', '!=', 'paid')
            ->whereNull('credit_note')
            ->sum($payoutColumn);
    
        // ---------------------------
        // All Time Paid Commission
        // ---------------------------
    
        $allTimeCommission = (clone $baseQuery)
            ->where('invoice_status', 'paid')
            ->sum($payoutColumn);
    
        // ---------------------------
        // Commission Rows Count
        // ---------------------------
    
        $commissionCount = (clone $baseQuery)
            ->where($payoutColumn, '>', 0)
            ->count();
    
        // ---------------------------
        // Average Payout Per Row
        // ---------------------------
    
        $avgPayout = (clone $baseQuery)
            ->whereNotNull($payoutColumn)
            ->avg($payoutColumn);
    
        // ---------------------------
        // Response
        // ---------------------------
    
        return response()->json([
            'status' => true,
            'data' => [
                'cycle_start_date' => $startDate->format('Y-m-d'),
                'cycle_end_date'   => $endDate->format('Y-m-d'),
    
                'expected_commission_current_cycle' => round($expectedCommission, 2),
                'all_time_commission_paid'          => round($allTimeCommission, 2),
    
                'total_commission_rows'             => $commissionCount,
                'average_payout_per_row'            => round($avgPayout, 2),
            ]
        ]);
    }
    
public function currentMonthPayouts(Request $request)
{
    $entityId = $request->entity_id;
    $role     = $request->role;

    if (!$entityId || !$role) {
        return response()->json([
            'status' => false,
            'message' => 'entity_id and role are required'
        ], 422);
    }

    $query = DB::table('payouts as p')
        ->leftJoin('companies as c', 'c.id', '=', 'p.entity_id')
        ->select(
            'p.id',
            'p.payout_code',
            'p.role',

            DB::raw("
                CASE 
                    WHEN p.role = 2 THEN 'MCP'
                    WHEN p.role = 4 THEN 'CP'
                    WHEN p.role = 5 THEN 'ARP'
                    WHEN p.role = 6 THEN 'PRO'
                    ELSE 'GEN'
                END as role_label
            "),

            DB::raw("COALESCE(c.business_name, 'Unknown') as entity_name"),

            'p.entity_id',
            'p.total_devices',
            'p.total_amount',
            'p.payout_amount',
            'p.status',
            'p.start_date',
            'p.end_date'
        )

        // ✅ CURRENT MONTH FILTER
        ->whereMonth('p.start_date', now()->month)
        ->whereYear('p.start_date', now()->year);

    // 🔥 ROLE FILTER
    if ($role != 1) {
        $query->where('p.role', $role)
              ->where('p.entity_id', $entityId);
    }

    // 🎯 ADMIN FILTER
    if ($role == 1 && $request->role_filter && $request->role_filter != 'all') {
        $query->where('p.role', $request->role_filter);
    }

    // 🔍 SEARCH
    if ($request->search) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('c.business_name', 'LIKE', "%$search%")
              ->orWhere('p.payout_code', 'LIKE', "%$search%");
        });
    }

    // 📊 STATUS FILTER
    if ($request->status) {
        $query->where('p.status', $request->status);
    }

    // 🔥 SUMMARY (CURRENT MONTH ONLY)
    $totalUnbilled = (clone $query)
        ->where('p.status', 'pending')
        ->sum('p.payout_amount');

    $perPage = min($request->get('per_page', 10), 50);

    $data = $query
        ->orderByDesc('p.start_date')
        ->paginate($perPage);

    return response()->json([
        'data' => $data,
        'summary' => [
            'total_unbilled_amount' => (float) round($totalUnbilled, 2)
        ]
    ]);
}
public function payoutProductDetails(Request $request)
{
    $payoutId = $request->payout_id;

    if (!$payoutId) {
        return response()->json([
            'status' => false,
            'message' => 'payout_id is required'
        ], 422);
    }

    // ======================================
    // 🔥 GET PAYOUT (SOURCE OF TRUTH)
    // ======================================
    $payout = DB::table('payouts')->where('id', $payoutId)->first();

    if (!$payout) {
        return response()->json([
            'status' => false,
            'message' => 'Payout not found'
        ], 404);
    }

    $start     = Carbon::parse($payout->start_date)->startOfDay();
    $end       = Carbon::parse($payout->end_date)->endOfDay();
    $entityId  = $payout->entity_id;
    $role      = $payout->role; // ✅ FIXED (use DB role)

    // ======================================
    // 🔥 COLUMN MAPPING
    // ======================================
    $payoutColumn = match ((int)$role) {
        5 => 'retailer_payout',
        4 => 'other_payout',
        2 => 'company_payout',
        6 => 'employee_payout',
        default => 'retailer_payout'
    };

    $entityColumn = match ((int)$role) {
        5 => 'retailer_id',
        4 => 'agent_id',
        2 => 'company_id',
        6 => 'promoter_id',
        default => 'retailer_id'
    };

    // ======================================
    // 🔥 PRODUCT-WISE DATA (CYCLE BASED)
    // ======================================
    $products = DB::table('w_devices as d')
        ->select(
            DB::raw("
                CASE 
                    WHEN d.product_name LIKE '%Warranty%' THEN 'Extended Warranty'
                    WHEN d.product_name LIKE '%Screen%' THEN 'Screen Damage'
                    ELSE 'Total Protection'
                END as product_name
            "),
            DB::raw('COUNT(*) as units'),
            DB::raw("SUM(d.$payoutColumn) as commission"),
            DB::raw("AVG(d.$payoutColumn) as rate")
        )
        ->whereBetween('d.created_at', [$start, $end]) // ✅ FIXED date issue
        ->where("d.$entityColumn", $entityId)
        ->groupBy(DB::raw("
            CASE 
                WHEN d.product_name LIKE '%Warranty%' THEN 'Extended Warranty'
                WHEN d.product_name LIKE '%Screen%' THEN 'Screen Damage'
                ELSE 'Total Protection'
            END
        "))
        ->get();

    // ======================================
    // 🔥 FORMAT + TOTALS
    // ======================================
    $totalCommission = 0;
    $totalUnits = 0;

    $products = $products->map(function ($item) use (&$totalCommission, &$totalUnits) {

        $item->commission = round((float)$item->commission, 2);
        $item->rate       = round((float)$item->rate, 2);
        $item->units      = (int)$item->units;

        $totalCommission += $item->commission;
        $totalUnits      += $item->units;

        return $item;
    });

    // ======================================
    // 🔥 CONTRIBUTION %
    // ======================================
    $products = $products->map(function ($item) use ($totalCommission) {

        $item->contribution = $totalCommission > 0
            ? round(($item->commission / $totalCommission) * 100, 2)
            : 0;

        return $item;
    });

    // ======================================
    // 🔥 FINAL RESPONSE
    // ======================================
   return response()->json([
    'summary' => [
        'payout_id'        => $payout->id,
        'payout_code'      => $payout->payout_code,
        'status'           => $payout->status,
        'entity_id'        => $entityId,
        'role'             => $role,

        'total_commission' => round($totalCommission, 2),
        'total_units'      => $totalUnits,

        'cycle' => [
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString()
        ]
    ],
    'products' => $products
]);
}
public function mcpChildPayouts(Request $request)
{
    $entityId = $request->entity_id; // MCP ID
    $role     = $request->role;

    if (!$entityId || $role != 2) {
        return response()->json([
            'status' => false,
            'message' => 'Only MCP allowed'
        ], 422);
    }

    $start = now()->startOfMonth()->toDateString();

    // =========================================
    // 🔥 BASE QUERY (FROM COMPANIES = CP)
    // =========================================
    $query = DB::table('companies as c')
        ->leftJoin('payouts as p', function ($join) use ($start) {
            $join->on('p.entity_id', '=', 'c.id')
                 ->where('p.role', 4) // CP payouts
                 ->where('p.start_date', $start);
        })
        ->select(
            'c.id as cp_id',
            'c.business_name as cp_name',
            'c.company_code as cp_code',

            DB::raw('COALESCE(p.total_devices, 0) as total_devices'),
            DB::raw('COALESCE(p.total_amount, 0) as total_amount'),
            DB::raw('COALESCE(p.payout_amount, 0) as payout_amount'),
            DB::raw('COALESCE(p.status, "pending") as status'),
            'p.payout_code'
        )
        ->where('c.company_id', $entityId) // MCP relation
        ->where('c.role', 4); // CP

    // =========================================
    // 🔍 SEARCH
    // =========================================
    if ($request->search) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('c.business_name', 'LIKE', "%$search%")
              ->orWhere('c.company_code', 'LIKE', "%$search%");
        });
    }

    // =========================================
    // 📊 STATUS FILTER
    // =========================================
    if ($request->status) {
        $query->where('p.status', $request->status);
    }

    // =========================================
    // 🔥 SUMMARY
    // =========================================
    $totalPayout = (clone $query)->sum('payout_amount');
    $totalCp     = (clone $query)->count();

    // =========================================
    // 🔥 PAGINATION
    // =========================================
    $perPage = min($request->get('per_page', 10), 50);

    $data = $query
        ->orderByDesc('payout_amount')
        ->paginate($perPage);

    return response()->json([
        'data' => $data,
        'summary' => [
            'total_cp' => $totalCp,
            'total_payout' => (float) round($totalPayout, 2)
        ]
    ]);
}

public function payoutRetailerDetails(Request $request)
{
    $payoutId = $request->payout_id;
    $entityId = $request->entity_id; // retailer_id

    if (!$payoutId || !$entityId) {
        return response()->json([
            'status' => false,
            'message' => 'payout_id and entity_id are required'
        ], 422);
    }

    // ======================================
    // 🔥 GET PAYOUT
    // ======================================
    $payout = DB::table('payouts')->where('id', $payoutId)->first();

    if (!$payout) {
        return response()->json([
            'status' => false,
            'message' => 'Payout not found'
        ], 404);
    }

    $start = Carbon::parse($payout->start_date)->startOfDay();
    $end   = Carbon::parse($payout->end_date)->endOfDay();

    // ======================================
    // 🔥 ROLE → COLUMN MAPPING
    // ======================================
    $role = $payout->role;

    $payoutColumn = match ((int)$role) {
        5 => 'retailer_payout',
        4 => 'other_payout',
        2 => 'company_payout',
        6 => 'employee_payout',
        default => 'retailer_payout'
    };

    // ======================================
    // 🔥 MAIN DATA QUERY
    // ======================================
    $query = DB::table('w_devices as d')
        ->select(
            'd.id',
            'd.w_code as warranty_code', // ✅ IMPORTANT
            'd.product_name',

            DB::raw("
                CASE 
                    WHEN d.product_name LIKE '%Warranty%' THEN 'Extended Warranty'
                    WHEN d.product_name LIKE '%Screen%' THEN 'Screen Damage'
                    ELSE 'Total Protection'
                END as product_type
            "),

            'd.created_at as date',
            DB::raw("d.$payoutColumn as payout_amount")
        )
        ->whereBetween('d.created_at', [$start, $end])
        ->where('d.retailer_id', $entityId);

    // ======================================
    // 🔍 SEARCH
    // ======================================
    if ($request->search) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('d.w_code', 'LIKE', "%$search%")
              ->orWhere('d.product_name', 'LIKE', "%$search%");
        });
    }

    // ======================================
    // 🔥 PAGINATION
    // ======================================
    $perPage = min($request->get('per_page', 10), 50);

    $data = $query
        ->orderByDesc('d.created_at')
        ->paginate($perPage);

    // ======================================
    // 🔥 SUMMARY
    // ======================================
    $totalPayout = (clone $query)->sum(DB::raw("d.$payoutColumn"));
    $totalUnits  = (clone $query)->count();

    // ======================================
    // 🔥 FINAL RESPONSE
    // ======================================
    return response()->json([
        'summary' => [
            'payout_id'     => $payout->id,
            'payout_code'   => $payout->payout_code,
            'entity_id'     => $entityId,
            'total_units'   => $totalUnits,
            'total_payout'  => (float) round($totalPayout, 2),
            'cycle' => [
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString()
            ]
        ],
        'data' => $data
    ]);
}

public function unbilledPayouts(Request $request)
{
    $role       = $request->role;
    $entityId   = $request->entity_id;
    $roleFilter = $request->role_filter;

    $query = DB::table('payouts as p')
        ->leftJoin('companies as c', 'c.id', '=', 'p.entity_id')

        ->leftJoin(DB::raw("
            (
                SELECT pr1.*
                FROM payout_requests pr1
                INNER JOIN (
                    SELECT payout_id, MAX(id) as max_id
                    FROM payout_requests
                    GROUP BY payout_id
                ) pr2 
                ON pr1.id = pr2.max_id
            ) as pr
        "), 'pr.payout_id', '=', 'p.id')

        ->select(
            'p.id',
            'p.payout_code',
            'p.role',

            DB::raw("
                CASE 
                    WHEN p.role = 2 THEN 'MCP'
                    WHEN p.role = 4 THEN 'CP'
                    WHEN p.role = 6 THEN 'Promoter'
                    ELSE 'GEN'
                END as role_label
            "),

            DB::raw("COALESCE(c.business_name, 'Unknown') as entity_name"),
            'p.entity_id',

            'p.total_devices',
            'p.total_amount',
            'p.payout_amount',

            'p.start_date',
            'p.end_date',
            'p.status',

            'pr.id as request_id',
            'pr.invoice_number',
            'pr.remark',
            'pr.status as request_status',
            'pr.otp_verified',
            'pr.transaction_id',
            'pr.requested_at',
            'pr.approved_at',
            'pr.transferred_at'
        )

        // 🔥 NO DATE FILTER → ALL MONTHS INCLUDED
        ->where('p.status', 'pending')
        ->whereMonth('p.start_date', now()->subMonth()->month)
        ->whereYear('p.start_date', now()->subMonth()->year)
        ->whereIn('p.role', [2, 4, 6]);

    if ($role != 1) {
        $query->where('p.entity_id', $entityId)
              ->where('p.role', $role);
    }

    if ($role == 1 && $roleFilter && $roleFilter != 'all') {
        $query->where('p.role', (int)$roleFilter);
    }

    if ($request->search) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('c.business_name', 'LIKE', "%$search%")
              ->orWhere('p.payout_code', 'LIKE', "%$search%");
        });
    }

    $totalUnbilled = (clone $query)->sum('p.payout_amount');
    $totalCount    = (clone $query)->count();

    $monthlyUnbilled = $totalUnbilled;

    $avgPayout = $totalCount > 0
        ? round($totalUnbilled / $totalCount, 2)
        : 0;

    $perPage = min($request->get('per_page', 10), 50);

    $data = $query->orderByDesc('p.start_date')->paginate($perPage);

    $data->getCollection()->transform(function ($item) {

        $item->payout_request = [
            'request_id'     => $item->request_id,
            'invoice_number' => $item->invoice_number,
            'remark'         => $item->remark,
            'status'         => $item->request_status,
            'otp_verified'   => $item->otp_verified,
            'transaction_id' => $item->transaction_id,
            'requested_at'   => $item->requested_at,
            'approved_at'    => $item->approved_at,
            'transferred_at' => $item->transferred_at,
        ];

        unset(
            $item->request_id,
            $item->invoice_number,
            $item->remark,
            $item->request_status,
            $item->otp_verified,
            $item->transaction_id,
            $item->requested_at,
            $item->approved_at,
            $item->transferred_at
        );

        return $item;
    });

    return response()->json([
        'summary' => [
            'total_unbilled'   => (float) round($totalUnbilled, 2),
            'total_records'    => $totalCount,
            'monthly_unbilled' => (float) round($monthlyUnbilled, 2),
            'average_payout'   => $avgPayout
        ],
        'data' => $data
    ]);
}
//
public function payoutStatement(Request $request)
{
    $payoutId = $request->payout_id;

    if (!$payoutId) {
        return response()->json([
            'status' => false,
            'message' => 'payout_id required'
        ], 422);
    }

    // =========================================
    // 🔥 GET PAYOUT
    // =========================================
    $payout = DB::table('payouts')->where('id', $payoutId)->first();

    if (!$payout) {
        return response()->json(['status' => false, 'message' => 'Not found'], 404);
    }

    $start = Carbon::parse($payout->start_date)->startOfDay();
    $end   = Carbon::parse($payout->end_date)->endOfDay();

    $entity = DB::table('companies')->where('id', $payout->entity_id)->first();

    // =========================================
    // 🔥 ROLE MAPPING
    // =========================================
    $role = $payout->role;

    $payoutColumn = match ($role) {
        5 => 'retailer_payout',
        4 => 'other_payout',
        2 => 'company_payout',
        6 => 'employee_payout',
    };

    $entityColumn = match ($role) {
        5 => 'retailer_id',
        4 => 'agent_id',
        2 => 'company_id',
        6 => 'promoter_id',
    };

    // =========================================
    // 🔥 PRODUCT DATA
    // =========================================
    $products = DB::table('w_devices as d')
    ->select(
        DB::raw("
            CASE 
                WHEN d.product_name LIKE '%Warranty%' THEN 'Extended Warranty'
                WHEN d.product_name LIKE '%Screen%' THEN 'Screen Damage'
                ELSE 'Total Protection'
            END as product_name
        "),
        DB::raw('COUNT(*) as qty'),

        // 🔥 payout
        DB::raw("ROUND(AVG(d.$payoutColumn),2) as rate"),
        DB::raw("ROUND(SUM(d.$payoutColumn),2) as amount"),

        // 🔥 sales values
        DB::raw("ROUND(SUM(d.product_price),2) as total_price"),
        DB::raw("ROUND(AVG(d.product_price),2) as avg_price"),

        // 🔥 MRP values
        DB::raw("ROUND(SUM(d.product_mrp),2) as total_mrp"),
        DB::raw("ROUND(AVG(d.product_mrp),2) as avg_mrp")
    )
    ->whereBetween('d.created_at', [$start, $end])
    ->where("d.$entityColumn", $entity->id)
    ->groupBy(DB::raw("
        CASE 
            WHEN d.product_name LIKE '%Warranty%' THEN 'Extended Warranty'
            WHEN d.product_name LIKE '%Screen%' THEN 'Screen Damage'
            ELSE 'Total Protection'
        END
    "))
    ->get();

    // =========================================
    // 🔥 TOTAL COMMISSION
    // =========================================
    $totalCommission = $products->sum('amount');

    // =========================================
    // 🔥 GST + TDS LOGIC
    // =========================================
    $gstVerified = $entity->gst_verified ?? 0;
    $gstType     = strtolower($entity->business_type ?? '');
    
    $isRegular = in_array($gstType, ['regular', 'registered']);

    $gstNumber   = $entity->gst ?? '';

    $cgst = 0;
    $sgst = 0;
    $igst = 0;

    if ($gstVerified && $isRegular) {
        
     
        if (str_starts_with($gstNumber, '27')) {
            // Maharashtra → CGST + SGST
            $cgst = $totalCommission * 0.09;
            $sgst = $totalCommission * 0.09;
        } else {
            // Other state → IGST
            $igst = $totalCommission * 0.18;
        }
    }

    // =========================================
    //  TDS (Assume 5%)
    // =========================================
    $tds = $totalCommission * 0.05;

    // =========================================
    //  FINAL PAYABLE
    // =========================================
    $totalWithGST = $totalCommission + $cgst + $sgst + $igst;
    $netPayable   = $totalWithGST - $tds;

    // =========================================
    //  RESPONSE
    // =========================================
    return response()->json([
        'statement' => [
            'statement_id' => 'STMT-' . $payout->id,
            'payout_code'  => $payout->payout_code,

            'entity' => [
                'name' => $entity->business_name,
                'gst'  => $entity->gst,
                'type' => $entity->business_type,
              'bank' => [
                    'bank_name'    => $entity->bank_name ?? null,
                    'branch_name'    => $entity->branch_name ?? null,
                    'account_no'   => $entity->account_no ?? null,
                    'ifsc_code'    => $entity->ifsc_code ?? null,
                    'account_type' => $entity->account_type ?? null,
                ],
            ],

            'company' => [
                'name' => 'GoElectronix Solutions Pvt Ltd',
                'address' => 'Navi Mumbai'
            ],

            'cycle' => [
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString()
            ],

            'products' => $products,

            'summary' => [
                'subtotal' => round($totalCommission, 2),
                'cgst'     => round($cgst, 2),
                'sgst'     => round($sgst, 2),
                'igst'     => round($igst, 2),
                'tds'      => round($tds, 2),
                'total'    => round($netPayable, 2)
            ]
        ]
    ]);
}
public function billedPayouts(Request $request)
{
    $roleFilter = $request->role_filter ?? 'all';

    $query = DB::table('payouts as p')
        ->leftJoin('companies as c', 'c.id', '=', 'p.entity_id')
        ->select(
            'p.id',
            'p.payout_code',

            DB::raw("
                CASE 
                    WHEN p.role = 2 THEN 'MCP'
                    WHEN p.role = 4 THEN 'CP'
                    WHEN p.role = 5 THEN 'ARP'
                    WHEN p.role = 6 THEN 'PRO'
                    ELSE 'GEN'
                END as role_label
            "),

            'c.business_name as entity_name',
            'p.payout_amount as amount',

            // 🔥 Bank + TXN
            DB::raw("CONCAT(c.bank_name, ' - ****', RIGHT(c.account_no,4)) as bank_details"),
            'p.transaction_id',
            'p.transferred_at as date',

            'p.status'
        )
        ->where('p.status', 'transferred'); // billed only

    // =========================================
    // 🔥 ROLE FILTER (TAB BASED)
    // =========================================
    if ($roleFilter != 'all') {
        $query->where('p.role', (int)$roleFilter);
    }

    // =========================================
    // 🔍 SEARCH
    // =========================================
    if ($request->search) {
        $search = $request->search;

        $query->where(function ($q) use ($search) {
            $q->where('c.business_name', 'LIKE', "%$search%")
              ->orWhere('p.payout_code', 'LIKE', "%$search%");
        });
    }

    // =========================================
    // 🔥 PAGINATION
    // =========================================
    $perPage = min($request->get('per_page', 10), 50);

    $data = $query
        ->orderByDesc('p.transferred_at')
        ->paginate($perPage);

    // =========================================
    // 🔥 SUMMARY
    // =========================================
    $totalBilled = (clone $query)->sum('p.payout_amount');

    return response()->json([
        'data' => $data,
        'summary' => [
            'total_billed_amount' => (float) round($totalBilled, 2)
        ]
    ]);
}
 public function requestPayoutTransfer(Request $request)
{

    /*

    |--------------------------------------------------------------------------

    | VALIDATION (USING VALIDATOR CLASS)

    |--------------------------------------------------------------------------

    */

    $validator = Validator::make($request->all(), [

        'payout_id'     => 'required|exists:payouts,id',

        'entity_id'     => 'required',

        'role'          => 'required',

        'invoice_number'=> 'required',

        'email'         => 'required|email'

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

    | GENERATE OTP

    |--------------------------------------------------------------------------

    */

    $otp = rand(100000, 999999);

    DB::beginTransaction();

    try {

        /*

        |--------------------------------------------------------------------------

        | SAVE REQUEST

        |--------------------------------------------------------------------------

        */

        $reqId = DB::table('payout_requests')->insertGetId([

            'payout_id'     => $request->payout_id,

            'entity_id'     => $request->entity_id,

            'role'          => $request->role,

            'invoice_number'=> $request->invoice_number,

            'remark'        => $request->remark,

            'otp'           => $otp,

            'requested_at'  => now(),

            'created_at'    => now(),

            'updated_at'    => now()

        ]);

        /*

        |--------------------------------------------------------------------------

        | SEND EMAIL OTP

        |--------------------------------------------------------------------------

        */

       // ==========================================
            // 🔥 GET COMPANY NAME
            // ==========================================
            $company = DB::table('companies')
                ->where('id', $request->entity_id)
                ->first();
            
            $name = $company->business_name ?? 'User';
            
            // ==========================================
            // 🔥 HTML EMAIL TEMPLATE
            // ==========================================
            $html = view('emails.payout-otp', [
                'name' => $name,
                'otp'  => $otp
            ])->render();

// ==========================================
// 🔥 SEND MAIL
// ==========================================
Mail::html($html, function ($mail) use ($request) {

    $mail->to($request->email)
         ->subject('Payout OTP Verification');

});

        /*

        |--------------------------------------------------------------------------

        | LOG ENTRY

        |--------------------------------------------------------------------------

        */

        DB::table('payout_logs')->insert([

            'payout_id'  => $request->payout_id,

            'request_id' => $reqId,

            'action'     => 'requested',

            'message'    => 'User requested payout transfer',

            'created_by' => $request->entity_id,

            'created_at' => now()

        ]);

        DB::commit();

        /*

        |--------------------------------------------------------------------------

        | RESPONSE (UNCHANGED)

        |--------------------------------------------------------------------------

        */

        return response()->json([

            'status'     => true,

            'request_id' => $reqId,

            'message'    => 'OTP sent'

        ]);

    } catch (\Throwable $e) {

        DB::rollBack();

        return response()->json([

            'status'  => false,

            'message' => 'Something went wrong',

            'error'   => $e->getMessage()

        ], 500);

    }

}
public function verifyPayoutOtp(Request $request)
{
    $req = DB::table('payout_requests')->where('id', $request->request_id)->first();

    if (!$req || $req->otp != $request->otp) {
        return response()->json(['status' => false, 'message' => 'Invalid OTP'], 400);
    }

    DB::table('payout_requests')->where('id', $req->id)->update([
        'otp_verified' => 1,
        'status' => 'verified',
        'updated_at' => now()
    ]);

    DB::table('payout_logs')->insert([
        'payout_id' => $req->payout_id,
        'request_id' => $req->id,
        'action' => 'otp_verified',
        'message' => 'OTP verified',
        'created_by' => $req->entity_id
    ]);

    return response()->json(['status' => true, 'message' => 'Verified']);
}

public function approvePayout(Request $request)
{
    $req = DB::table('payout_requests')->where('id', $request->request_id)->first();

    if (!$req || !$req->otp_verified) {
        return response()->json(['status' => false, 'message' => 'Invalid request'], 400);
    }

    DB::table('payout_requests')->where('id', $req->id)->update([
        'status' => 'approved',
        'approved_at' => now()
    ]);

    DB::table('payout_logs')->insert([
        'payout_id' => $req->payout_id,
        'request_id' => $req->id,
        'action' => 'approved',
        'message' => 'Admin approved payout',
        'created_by' => 1
    ]);

    return response()->json(['status' => true]);
}
public function markTransferred(Request $request)
{
    $request->validate([
        'request_id' => 'required|exists:payout_requests,id',
        'transaction_id' => 'required|string'
    ]);

    $req = DB::table('payout_requests')->where('id', $request->request_id)->first();

    if (!$req) {
        return response()->json(['status' => false, 'message' => 'Request not found'], 404);
    }

    if ($req->status != 'approved') {
        return response()->json(['status' => false, 'message' => 'Not approved'], 400);
    }

    // 🔥 CHECK payout exists
    $payout = DB::table('payouts')->where('id', $req->payout_id)->first();

    if (!$payout) {
        return response()->json([
            'status' => false,
            'message' => 'Payout not found'
        ], 404);
    }

    DB::beginTransaction();

    try {

        // ✅ update request
        DB::table('payout_requests')->where('id', $req->id)->update([
            'status' => 'transferred',
            'transaction_id' => $request->transaction_id,
            'transferred_at' => now(),
            'updated_at' => now()
        ]);

        // ✅ update payout
        DB::table('payouts')->where('id', $req->payout_id)->update([
            'status' => 'transferred',
            'transaction_id' => $request->transaction_id,
            'transferred_at' => now(),
            'updated_at' => now()
        ]);

        // ✅ logs
        DB::table('payout_logs')->insert([
            'payout_id' => $req->payout_id,
            'request_id' => $req->id,
            'action' => 'transferred',
            'message' => 'Amount transferred successfully',
            'created_by' => 1,
            'created_at' => now()
        ]);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Payout transferred successfully'
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'error' => $e->getMessage() // 🔥 NOW YOU SEE REAL ERROR
        ], 500);
    }
}
}