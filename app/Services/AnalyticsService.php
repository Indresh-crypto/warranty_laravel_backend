<?php

namespace App\Services;

use App\Models\DailySale;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AnalyticsService
{
    public function dashboard($filters = [])
    {
        $retailerId = $filters['retailer_id'] ?? null;

        /*
        |--------------------------------------------------------------------------
        | RANGE OR DATE RESOLUTION (PRIORITY LOGIC)
        |--------------------------------------------------------------------------
        */
        [$rangeStart, $rangeEnd] = $this->resolveRange($filters);

        $startDate = !empty($filters['start_date'])
            ? Carbon::parse($filters['start_date'])->startOfDay()
            : $rangeStart;

        $endDate = !empty($filters['end_date'])
            ? Carbon::parse($filters['end_date'])->endOfDay()
            : $rangeEnd;

        $hasCustomRange = $startDate && $endDate;

        /*
        |--------------------------------------------------------------------------
        | BASE QUERY (SALES)
        |--------------------------------------------------------------------------
        */
        $baseQuery = DailySale::query()
            ->when($retailerId, fn($q) => $q->where('retailer_id', $retailerId));

        /*
        |--------------------------------------------------------------------------
        | RETAILER STATS (FILTER APPLIED)
        |--------------------------------------------------------------------------
        */
        $retailerQuery = Company::where('role', 5);

        if ($hasCustomRange) {
            $retailerQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        $retailerStats = $retailerQuery->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN DATE(created_at) = CURDATE() - INTERVAL 1 DAY THEN 1 ELSE 0 END) as yesterday,
            SUM(CASE 
                WHEN MONTH(created_at) = MONTH(CURDATE()) 
                AND YEAR(created_at) = YEAR(CURDATE()) 
                THEN 1 ELSE 0 
            END) as month
        ")->first();

        $totalRetailers  = (int) ($retailerStats->total ?? 0);
        $activeRetailers = (int) ($retailerStats->active ?? 0);

        $activePercent = $totalRetailers > 0
            ? round(($activeRetailers / $totalRetailers) * 100, 2)
            : 0;

        $retailerGrowth = $this->growth(
            (int) ($retailerStats->today ?? 0),
            (int) ($retailerStats->yesterday ?? 0)
        );

        /*
        |--------------------------------------------------------------------------
        | SALES STATS
        |--------------------------------------------------------------------------
        */
        if ($hasCustomRange) {

            $today = $this->getRangeStats(clone $baseQuery, $startDate, $endDate);

            $yesterday = ['sales' => 0, 'amount' => 0];
            $week      = $today;
            $month     = $today;

            $todayGrowth = 0;
            $weekGrowth  = 0;

        } else {

            $today     = $this->getStats(clone $baseQuery, Carbon::today());
            $yesterday = $this->getStats(clone $baseQuery, Carbon::yesterday());

            $week = $this->getRangeStats(
                clone $baseQuery,
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek()
            );

            $lastWeek = $this->getRangeStats(
                clone $baseQuery,
                Carbon::now()->subWeek()->startOfWeek(),
                Carbon::now()->subWeek()->endOfWeek()
            );

            $month = $this->getMonthStats(clone $baseQuery);

            $todayGrowth = $this->growth($today['sales'], $yesterday['sales']);
            $weekGrowth  = $this->growth($week['sales'], $lastWeek['sales']);
        }

        /*
        |--------------------------------------------------------------------------
        | CUSTOM RANGE (FOR API RESPONSE)
        |--------------------------------------------------------------------------
        */
        $customRange = null;

        if ($hasCustomRange) {
            $customRange = $this->getRangeStats(
                DailySale::query()
                    ->when($retailerId, fn($q) => $q->where('retailer_id', $retailerId)),
                $startDate,
                $endDate
            );
        }

        /*
        |--------------------------------------------------------------------------
        | TOP PRODUCT (FILTER APPLIED)
        |--------------------------------------------------------------------------
        */
        $topProductQuery = DailySale::query()
            ->when($retailerId, fn($q) => $q->where('retailer_id', $retailerId));

        if ($hasCustomRange) {
            $topProductQuery->whereBetween('date', [$startDate, $endDate]);
        }

        $topProduct = $topProductQuery
            ->join('w_products', 'daily_sales.product_id', '=', 'w_products.id')
            ->select(
                'daily_sales.product_id',
                'w_products.name as product_name',
                DB::raw('SUM(daily_sales.total_sales) as total_sales')
            )
            ->groupBy('daily_sales.product_id', 'w_products.name')
            ->orderByDesc('total_sales')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | FINAL RESPONSE (UNCHANGED STRUCTURE)
        |--------------------------------------------------------------------------
        */
        return [
            'today'     => $today,
            'yesterday' => $yesterday,
            'week'      => $week,
            'month'     => $month,

            'custom_range' => $customRange,

            'growth' => [
                'today_vs_yesterday' => $todayGrowth,
                'week_vs_last_week'  => $weekGrowth
            ],

            'top_product' => $topProduct ? [
                'product_id'   => $topProduct->product_id,
                'product_name' => $topProduct->product_name,
                'total_sales'  => (int) $topProduct->total_sales
            ] : null,

            'retailers' => [
                'total'          => $totalRetailers,
                'active'         => $activeRetailers,
                'active_percent' => $activePercent,
                'today'          => (int) ($retailerStats->today ?? 0),
                'month'          => (int) ($retailerStats->month ?? 0),
                'growth'         => $retailerGrowth
            ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | RANGE HANDLER
    |--------------------------------------------------------------------------
    */
    private function resolveRange($filters)
    {
        if (empty($filters['range'])) {
            return [null, null];
        }

        $now = now();

        switch ($filters['range']) {

            case 'today':
                return [$now->startOfDay(), $now->endOfDay()];

            case 'yesterday':
                return [
                    $now->copy()->subDay()->startOfDay(),
                    $now->copy()->subDay()->endOfDay()
                ];

            case 'week':
                return [$now->startOfWeek(), $now->endOfWeek()];

            case 'last_week':
                return [
                    $now->copy()->subWeek()->startOfWeek(),
                    $now->copy()->subWeek()->endOfWeek()
                ];

            case 'month':
                return [$now->startOfMonth(), $now->endOfMonth()];

            case 'last_month':
                return [
                    $now->copy()->subMonth()->startOfMonth(),
                    $now->copy()->subMonth()->endOfMonth()
                ];

            case '7_days':
                return [
                    $now->copy()->subDays(6)->startOfDay(),
                    $now->endOfDay()
                ];

            case '30_days':
                return [
                    $now->copy()->subDays(29)->startOfDay(),
                    $now->endOfDay()
                ];

            default:
                return [null, null];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    private function getStats($query, $date)
    {
        $data = $query
            ->whereDate('date', $date)
            ->selectRaw('COALESCE(SUM(total_sales),0) as sales, COALESCE(SUM(total_amount),0) as amount')
            ->first();

        return [
            'sales'  => (int) $data->sales,
            'amount' => (float) $data->amount
        ];
    }

    private function getRangeStats($query, $start, $end)
    {
        $data = $query
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('COALESCE(SUM(total_sales),0) as sales, COALESCE(SUM(total_amount),0) as amount')
            ->first();

        return [
            'sales'  => (int) $data->sales,
            'amount' => (float) $data->amount
        ];
    }

    private function getMonthStats($query)
    {
        $data = $query
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->selectRaw('COALESCE(SUM(total_sales),0) as sales, COALESCE(SUM(total_amount),0) as amount')
            ->first();

        return [
            'sales'  => (int) $data->sales,
            'amount' => (float) $data->amount
        ];
    }

    private function growth($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}