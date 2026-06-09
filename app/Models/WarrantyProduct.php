<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WarrantyProduct extends Model
{
    use HasFactory;

    // FIX: correct table name
    protected $table = 'w_products';

    protected $fillable = [
        'name',
        'image',
        'zoho_id',
        'hsn_code',
        'validity',
        'claims',
        'features',
        'min_value',
        'max_value',
        'is_fixed',
        'is_percent',
        'is_regular',
        'is_offer',
        'mrp',
        'status',
        'margin',
        'coverage',
        'exclusions',
        'product_type',
        'enroll_max',
        'sub_val_days',
        'sold_count',
        'retailer_benifits',
        'per_device_price',
        'per_device_product_mrp',
        'title',
        'discount_price',
        'show_popup',
        'sub_title'
    ];

    /*
    |--------------------------------------------------------------------------
    | CASTS (IMPORTANT FOR PERFORMANCE + LOGIC)
    |--------------------------------------------------------------------------
    */
    protected $casts = [

        'mrp'          => 'float',
        'margin'       => 'float',
        'min_value'    => 'float',
        'max_value'    => 'float',

        'validity'     => 'integer',
        'claims'       => 'integer',
        'sold_count'   => 'integer'
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'category_product',
            'warranty_product_id',
            'category_id'
        );
    }

    public function devices()
    {
        return $this->hasMany(WDevice::class, 'product_id');
    }

    public function companyProducts()
    {
        return $this->hasMany(CompanyProduct::class, 'product_id');
    }

    public function coverages()
    {
        return $this->hasMany(
            WarrantyProductCoverage::class,
            'warranty_product_id'
        );
    }

    public function priceTemplates()
    {
        return $this->hasMany(PriceTemplate::class);
    }

    public function subscribedPackages()
    {
        return $this->hasMany(SubscribedPackage::class, 'package_id');
    }

    /*
    |--------------------------------------------------------------------------
    | ANALYTICS HELPERS (VERY USEFUL)
    |--------------------------------------------------------------------------
    */

    // Total sales count (fast if cached column exists)
    public function getTotalSalesAttribute()
    {
        return $this->sold_count ?? 0;
    }

    // Total revenue from devices
    public function getTotalRevenueAttribute()
    {
        return $this->devices()->sum('product_price');
    }
}