<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WDevice;
use Carbon\Carbon;

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
}