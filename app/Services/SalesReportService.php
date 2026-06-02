<?php
namespace App\Services;

use App\Models\DailySale;
use Illuminate\Support\Facades\DB;

class SalesReportService
{
    public function getReport(array $filters)
    {
        $query = DailySale::query();

        //  Date Range
        if (!empty($filters['from']) && !empty($filters['to'])) {
            $query->whereBetween('date', [$filters['from'], $filters['to']]);
        }

        //  Filters
        if (!empty($filters['retailer_id'])) {
            $query->where('retailer_id', $filters['retailer_id']);
        }

        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        // 🔥 Group By (dynamic)
        $groupBy = $filters['group_by'] ?? 'date';

        switch ($groupBy) {
            case 'retailer':
                $query->select('retailer_id',
                    DB::raw('SUM(total_sales) as total_sales'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy('retailer_id');
                break;

            case 'product':
                $query->select('product_id',
                    DB::raw('SUM(total_sales) as total_sales'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy('product_id');
                break;

            case 'category':
                $query->select('category_id',
                    DB::raw('SUM(total_sales) as total_sales'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy('category_id');
                break;

            case 'company':
                $query->select('company_id',
                    DB::raw('SUM(total_sales) as total_sales'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy('company_id');
                break;

            default:
                $query->select('date',
                    DB::raw('SUM(total_sales) as total_sales'),
                    DB::raw('SUM(total_amount) as total_amount')
                )->groupBy('date');
        }

        return $query->orderByDesc('date')->get();
    }

    // 🔥 Comparison (today vs yesterday)
    public function compareToday()
    {
        return [
            'today' => DailySale::whereDate('date', today())->sum('total_sales'),
            'yesterday' => DailySale::whereDate('date', now()->subDay())->sum('total_sales')
        ];
    }
}