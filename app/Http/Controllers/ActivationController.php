<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleChangeRequest;
use App\Models\KeyBalance;
use App\Models\KeyAssignLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use DB;
class ActivationController extends Controller
{


public function syncCustomerActivations()
{
    $results = DB::table('ama_customers')
        ->select(
            'retailer_id as org_id',
            DB::raw('DATE(created_on) as added_date'),
            DB::raw('COUNT(*) as total')
        )
        ->whereNotNull('retailer_id')
        ->whereNotNull('created_on')
        ->groupBy('retailer_id', DB::raw('DATE(created_on)'))
        ->get();

    if ($results->isEmpty()) {
        \Log::warning("No activations to sync.");
        return "No data found.";
    }

    foreach ($results as $row) {
        DB::table('activations')->updateOrInsert(
            [
                'org_id' => $row->org_id,
                'added_date' => $row->added_date,
            ],
            [
                'total' => $row->total,
                'updated_at' => now(),
                // 'created_at' => now() // optional; avoid overriding existing
            ]
        );
    }

    \Log::info("Synced " . $results->count() . " activation records.");
    return "All historical activations synced successfully.";
}

public function getActivationsByDateRange(Request $request)
{
    // Validate input
    $request->validate([
        'from_date' => 'required|date',
        'to_date'   => 'required|date|after_or_equal:from_date',
        'org_id'    => 'nullable|integer',
    ]);

    $from = date('Y-m-d 00:00:00', strtotime($request->from_date));
    $to   = date('Y-m-d 23:59:59', strtotime($request->to_date));

    $query = DB::table('activations')
        ->whereBetween('added_date', [$from, $to]);

    // ✅ Add org_id filter only if provided
    if ($request->filled('org_id')) {
        $query->where('org_id', $request->org_id);
    }

    $results = $query->orderBy('added_date', 'asc')->get();

    return response()->json([
        'status' => true,
        'data'   => $results,
    ]);
}
public function getActivationsByYear(Request $request)
{
    // ✅ Validate input
    $request->validate([
        'year'   => 'required|digits:4',
        'org_id' => 'nullable|integer',
    ]);

    $year = (int) $request->year;

    // ✅ Define full-year date range (same pattern as date-range function)
    $from = date('Y-m-d 00:00:00', strtotime("$year-01-01"));
    $to   = date('Y-m-d 23:59:59', strtotime("$year-12-31"));

    // ✅ Build query
    $query = DB::table('activations')
        ->whereBetween('added_date', [$from, $to]);

    // ✅ Add org_id filter if provided
    if ($request->filled('org_id')) {
        $query->where('org_id', $request->org_id);
    }

    // ✅ Group by month to get monthly totals
    $results = $query
        ->selectRaw('MONTH(added_date) as month, COUNT(*) as total_activations')
        ->groupBy(DB::raw('MONTH(added_date)'))
        ->orderBy(DB::raw('MONTH(added_date)'))
        ->get();

    // ✅ Format results with month names (Jan–Dec)
    $formatted = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthData = $results->firstWhere('month', $m);
        $formatted[] = [
            'month_number' => $m,
            'month_name'   => date('F', mktime(0, 0, 0, $m, 1)),
            'total_activations' => $monthData->total_activations ?? 0,
        ];
    }

    // ✅ Return response
    return response()->json([
        'status' => true,
        'year'   => $year,
        'data'   => $formatted,
    ]);
}
}