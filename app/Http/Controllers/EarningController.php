<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WDevice;
use Illuminate\Support\Facades\DB;

class EarningController extends Controller
{
    public function index(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | 1️⃣ IDENTIFY CONTEXT + VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($request->retailer_id) {

            if (!$request->company_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'company_id is required for retailer dashboard'
                ], 422);
            }

            $filterColumn = 'retailer_id';
            $filterValue  = $request->retailer_id;
            $payoutColumn = 'retailer_payout';
            $context      = 'retailer';

        } elseif ($request->agent_id) {

            if (!$request->company_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'company_id is required for agent dashboard'
                ], 422);
            }

            $filterColumn = 'agent_id';
            $filterValue  = $request->agent_id;
            $payoutColumn = 'other_payout';
            $context      = 'agent';


        }
        
        elseif ($request->promoter_id) {

            $filterColumn = 'promoter_id';
            $filterValue  = $request->promoter_id;
            $payoutColumn = 'employee_payout';
            $context      = 'employee';

        
        
        } 
        elseif ($request->company_id) {

            $filterColumn = 'company_id';
            $filterValue  = $request->company_id;
            $payoutColumn = 'company_payout';
            $context      = 'company';


        } else {
            return response()->json([
                'status' => false,
                'message' => 'retailer_id or agent_id or company_id is required'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | 2️⃣ BASE QUERY (company scoped)
        |--------------------------------------------------------------------------
        */
        $baseQuery = WDevice::query()
            ->where($filterColumn, $filterValue);

        // 🔒 Extra safety: retailer/agent must also match company
        if ($context !== 'company') {
            $baseQuery->where('company_id', $request->company_id);
        }

        /*
        |--------------------------------------------------------------------------
        | 3️⃣ SUMMARY COUNTS + PAYOUTS
        |--------------------------------------------------------------------------
        */
        $summary = (clone $baseQuery)
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM($payoutColumn),0) as total_payout,

                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as active_count,
                COALESCE(SUM(CASE WHEN is_approved = 1 THEN $payoutColumn END),0) as active_payout,

                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as provisioning_count,
                COALESCE(SUM(CASE WHEN is_approved = 0 THEN $payoutColumn END),0) as provisioning_payout,

                SUM(CASE WHEN is_approved = 2 THEN 1 ELSE 0 END) as rejected_count,
                COALESCE(SUM(CASE WHEN is_approved = 2 THEN $payoutColumn END),0) as rejected_payout
            ")
            ->first();

        /*
        |--------------------------------------------------------------------------
        | 4️⃣ STATUS CHART
        |--------------------------------------------------------------------------
        */
        $total = max($summary->total_count, 1);

        $statusChart = [
            [
                'label' => 'Active',
                'status' => 1,
                'count' => (int) $summary->active_count,
                'percentage' => round(($summary->active_count / $total) * 100, 2),
            ],
            [
                'label' => 'Provisioning',
                'status' => 0,
                'count' => (int) $summary->provisioning_count,
                'percentage' => round(($summary->provisioning_count / $total) * 100, 2),
            ],
            [
                'label' => 'Rejected',
                'status' => 3,
                'count' => (int) $summary->rejected_count,
                'percentage' => round(($summary->rejected_count / $total) * 100, 2),
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | 5️⃣ PRODUCT-WISE EARNINGS
        |--------------------------------------------------------------------------
        */
        $productWise = (clone $baseQuery)
            ->select(
                'product_id',
                'product_name',
                DB::raw('COUNT(*) as warranty_count'),
                DB::raw('COALESCE(SUM(product_price),0) as total_product_price'),
                DB::raw("COALESCE(SUM($payoutColumn),0) as total_payout")
            )
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('total_payout')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | FINAL RESPONSE
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'status' => true,
            'context' => $context,
            'company_id' => $request->company_id,
            'data' => [
              'summary' => [
                    'total' => [
                        'count' => (int) $summary->total_count,
                        'payout' => round((float) $summary->total_payout, 2),
                    ],
                    'active' => [
                        'count' => (int) $summary->active_count,
                        'payout' => round((float) $summary->active_payout, 2),
                    ],
                    'provisioning' => [
                        'count' => (int) $summary->provisioning_count,
                        'payout' => round((float) $summary->provisioning_payout, 2),
                    ],
                    'rejected' => [
                        'count' => (int) $summary->rejected_count,
                        'payout' => round((float) $summary->rejected_payout, 2),
                    ],
                ],
                'status_chart' => $statusChart,
                'product_wise_earnings' => $productWise
            ]
        ]);
    }
}