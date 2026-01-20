<?php

namespace App\Services;

use App\Models\WarrantyProduct;
use App\Models\PriceTemplate;
use Illuminate\Support\Facades\Log;

class WarrantyPricingService
{
    public static function calculate(
        int $product_id,
        int $company_id,
        float $devicePrice
    ) {

        Log::info('PRICING_CALCULATION_STARTED', [
            'product_id' => $product_id,
            'company_id' => $company_id,
            'device_price' => $devicePrice
        ]);

        // ============================
        // LOAD PRODUCT
        // ============================

        $product = WarrantyProduct::find($product_id);

        if (!$product) {

            Log::error('PRICING_PRODUCT_NOT_FOUND', [
                'product_id' => $product_id
            ]);

            throw new \Exception('Warranty product not found');
        }

        $isPercent = (int)$product->is_percent === 1;

        Log::info('PRICING_PRODUCT_LOADED', [
            'is_percent' => $isPercent,
            'product_mrp' => $product->mrp
        ]);

        // ============================
        // LOAD PRICE TEMPLATE
        // ============================

        $query = PriceTemplate::where('company_id', $company_id)
            ->where('warranty_product_id', $product_id)
            ->where('min_price', '<=', $devicePrice)
            ->where('max_price', '>=', $devicePrice);

        Log::info('PRICING_TEMPLATE_QUERY', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings()
        ]);

        $template = $query->first();

        if (!$template) {

            // Dump ALL templates for debugging
            $available = PriceTemplate::where('company_id', $company_id)
                ->where('warranty_product_id', $product_id)
                ->get()
                ->toArray();

            Log::error('PRICING_TEMPLATE_NOT_FOUND', [
                'device_price' => $devicePrice,
                'available_templates' => $available
            ]);

            throw new \Exception('Price template not found for given range');
        }

        Log::info('PRICING_TEMPLATE_MATCHED', [
            'template_id' => $template->id,
            'min_price' => $template->min_price,
            'max_price' => $template->max_price
        ]);

        // ============================
        // PRODUCT PRICE
        // ============================

        $productPrice = $isPercent
            ? ($product->mrp / 100) * $devicePrice
            : $product->mrp;

        // ============================
        // PAYOUTS
        // ============================

        $retailerPayout = $isPercent
            ? ($template->retailer_payout / 100) * $devicePrice
            : $template->retailer_payout;

        $employeePayout = $isPercent
            ? ($template->emp_payout / 100) * $devicePrice
            : $template->emp_payout;

        $otherPayout = $isPercent
            ? ($template->other_payout / 100) * $devicePrice
            : $template->other_payout;

        $companyPayout = $isPercent
            ? ($template->company_payout / 100) * $devicePrice
            : $template->company_payout;

        $totalPayout = $retailerPayout + $employeePayout + $otherPayout + $companyPayout;

        $retailerPending = max(
            0,
            $productPrice - ($retailerPayout + $employeePayout)
        );

        Log::info('PRICING_CALCULATED', [
            'product_price' => $productPrice,
            'total_payout' => $totalPayout
        ]);

        return [

            'product_price' => round($retailerPending, 2),

            'retailer_payout' => round($retailerPayout, 2),
            'employee_payout' => round($employeePayout, 2),
            'other_payout' => round($otherPayout, 2),
            'company_payout' => round($companyPayout, 2),

            'total_payout' => round($totalPayout, 2),
            'retailer_pending' => round($retailerPending, 2),

            'pricing_mode' => $isPercent ? 'PERCENT' : 'FIXED',
            'template_id' => $template->id
        ];
    }
}