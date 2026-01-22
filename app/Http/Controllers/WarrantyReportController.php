<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Company;
use App\Models\WarrantyProduct;
use App\Models\UploadFile;
use App\Models\CompanyProduct;
use App\Models\WarrantyClaim;

use App\Models\PriceTemplate;
use App\Models\WCustomer;
use App\Models\Companies;
use App\Models\WDevice;
use App\Models\Wclaim;
use App\Models\ZohoInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; 
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\WarrantyProductCoverage;
use DB;

class WarrantyReportController extends Controller
{
    
    public function salesCreditSummary(Request $request)
    {
    $validator = Validator::make($request->all(), [
        'company_id'  => 'nullable|integer|exists:companies,id',
        'retailer_id' => 'nullable|integer|exists:companies,id',
        'agent_id'    => 'nullable|integer|exists:companies,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    $query = WDevice::query();

    /* ================= FILTERS ================= */
    $query->when($request->filled('company_id'), function ($q) use ($request) {
        $q->where('company_id', $request->company_id);
    });

    $query->when($request->filled('retailer_id'), function ($q) use ($request) {
        $q->where('retailer_id', $request->retailer_id);
    });

    $query->when($request->filled('agent_id'), function ($q) use ($request) {
        $q->where('agent_id', $request->agent_id);
    });

    /* ================= AGGREGATES ================= */
    $summary = $query->selectRaw("
        COUNT(*) AS total_sold,

        COALESCE(
            SUM(product_mrp),
        0) AS total_revenue,

        COALESCE(
            SUM(
                CASE 
                    WHEN credit_note IS NOT NULL 
                     AND credit_note != '' 
                    THEN product_price
                    ELSE 0
                END
            ),
        0) AS credit_note_amount,

        COALESCE(
            SUM(
                CASE 
                    WHEN credit_note IS NOT NULL 
                     AND credit_note != '' 
                    THEN 1
                    ELSE 0
                END
            ),
        0) AS credit_note_count
    ")->first();

    /* ================= RESPONSE ================= */
    return response()->json([
        'status' => true,
        'data' => [
            'total_sold' => (int) $summary->total_sold,
            'revenue' => (float) $summary->total_revenue,

            'credit_notes' => [
                'count'  => (int) $summary->credit_note_count,
                'amount' => (float) $summary->credit_note_amount,
            ],
        ]
    ], 200);
}


    public function revenueTrend(Request $request)
    {
        $query = WDevice::query()
            ->join('w_customers', 'w_customers.id', '=', 'w_devices.w_customer_id');
    
        /* ================= FILTERS ================= */
        $query->when($request->company_id, fn($q) =>
            $q->where('w_devices.company_id', $request->company_id)
        );
    
        $query->when($request->agent_id, fn($q) =>
            $q->where('w_devices.agent_id', $request->agent_id)
        );
    
        $query->when($request->retailer_id, fn($q) =>
            $q->where('w_devices.retailer_id', $request->retailer_id)
        );
    
        $query->when($request->from_date && $request->to_date, fn($q) =>
            $q->whereBetween('w_devices.created_at', [
                $request->from_date . ' 00:00:00',
                $request->to_date . ' 23:59:59'
            ])
        );
    
        $query->when($request->state, fn($q) =>
            $q->where('w_customers.state', 'like', "%{$request->state}%")
        );
    
        $query->when($request->district, fn($q) =>
            $q->where('w_customers.city', 'like', "%{$request->district}%")
        );
    
        $query->when($request->brand_name, fn($q) =>
            $q->where('w_devices.brand_name', 'like', "%{$request->brand_name}%")
        );
    
        $query->when($request->category_name, fn($q) =>
            $q->where('w_devices.category_name', 'like', "%{$request->category_name}%")
        );
    
        $query->when($request->product_name, fn($q) =>
            $q->where('w_devices.product_name', 'like', "%{$request->product_name}%")
        );
    
        /* ================= AGGREGATE ================= */
        $data = $query
            ->selectRaw("
                DATE(w_devices.created_at) as date,
                COALESCE(SUM(w_devices.product_mrp), 0) as revenue
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    
        return response()->json([
            'status' => true,
            'data' => $data
        ]);
    }
    
    
    public function productRevenueShare(Request $request)
    {
    $query = WDevice::query()
        ->join('w_customers', 'w_customers.id', '=', 'w_devices.w_customer_id');

    /* ================= FILTERS ================= */
    $query->when($request->company_id, fn($q) =>
        $q->where('w_devices.company_id', $request->company_id)
    );

    $query->when($request->agent_id, fn($q) =>
        $q->where('w_devices.agent_id', $request->agent_id)
    );

    $query->when($request->retailer_id, fn($q) =>
        $q->where('w_devices.retailer_id', $request->retailer_id)
    );

    $query->when($request->from_date && $request->to_date, fn($q) =>
        $q->whereBetween('w_devices.created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ])
    );

    $query->when($request->state, fn($q) =>
        $q->where('w_customers.state', 'like', "%{$request->state}%")
    );

    $query->when($request->district, fn($q) =>
        $q->where('w_customers.city', 'like', "%{$request->district}%")
    );

    $query->when($request->brand_name, fn($q) =>
        $q->where('w_devices.brand_name', 'like', "%{$request->brand_name}%")
    );

    $query->when($request->category_name, fn($q) =>
        $q->where('w_devices.category_name', 'like', "%{$request->category_name}%")
    );

    /* ================= AGGREGATE ================= */
    $data = $query
        ->selectRaw("
            w_devices.product_name,
            COALESCE(SUM(w_devices.product_mrp), 0) as revenue
        ")
        ->groupBy('w_devices.product_name')
        ->orderByDesc('revenue')
        ->get();

    return response()->json([
        'status' => true,
        'data' => $data,
        'top_product' => $data->first(),
        'low_product' => $data->last()
    ]);
}

public function geographyRevenue(Request $request)
{
    $request->validate([
        'type' => 'required|in:state,district',
    ]);

    $query = WDevice::query()
        ->join('w_customers', 'w_customers.id', '=', 'w_devices.w_customer_id');

    /* ================= GLOBAL FILTERS ================= */
    $query->when($request->company_id, fn ($q) =>
        $q->where('w_devices.company_id', $request->company_id)
    );

    $query->when($request->agent_id, fn ($q) =>
        $q->where('w_devices.agent_id', $request->agent_id)
    );

    $query->when($request->retailer_id, fn ($q) =>
        $q->where('w_devices.retailer_id', $request->retailer_id)
    );

    $query->when($request->from_date && $request->to_date, fn ($q) =>
        $q->whereBetween('w_devices.created_at', [
            $request->from_date . ' 00:00:00',
            $request->to_date . ' 23:59:59'
        ])
    );

    $query->when($request->brand_name, fn ($q) =>
        $q->where('w_devices.brand_name', 'like', "%{$request->brand_name}%")
    );

    $query->when($request->category_name, fn ($q) =>
        $q->where('w_devices.category_name', 'like', "%{$request->category_name}%")
    );

    $query->when($request->product_name, fn ($q) =>
        $q->where('w_devices.product_name', 'like', "%{$request->product_name}%")
    );

    /* ================= STATE / DISTRICT SWITCH ================= */
    if ($request->type === 'state') {

        $data = $query
            ->selectRaw("
                UPPER(w_customers.state) as label,
                COALESCE(SUM(w_devices.product_mrp), 0) as revenue
            ")
            ->groupBy('w_customers.state')
            ->orderByDesc('revenue')
            ->get();

    } else {
        // district-wise
        $query->when($request->state, fn ($q) =>
            $q->where('w_customers.state', $request->state)
        );

        $data = $query
            ->selectRaw("
                UPPER(w_customers.city) as label,
                COALESCE(SUM(w_devices.product_mrp), 0) as revenue
            ")
            ->groupBy('w_customers.city')
            ->orderByDesc('revenue')
            ->get();
    }

    /* ================= RESPONSE FORMAT ================= */
    return response()->json([
        'status' => true,
        'type' => $request->type,
        'chart' => [
            'labels' => $data->pluck('label'),
            'values' => $data->pluck('revenue'),
        ],
        'tiles' => $data->map(fn ($row) => [
            'name' => $row->label,
            'revenue' => (float) $row->revenue
        ]),
        'top' => $data->first(),
    ]);
}
}
