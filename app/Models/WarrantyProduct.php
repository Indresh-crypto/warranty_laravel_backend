<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WarrantyProduct extends Model
{
    use HasFactory;

    protected $table = 'w_products';

    protected $fillable = [
        'name',
        'image',
        'zoho_id',
        'hsn_code',
        'categories',
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
        'exclustions'
    ];

   
   public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'category_product',       // pivot table name
            'warranty_product_id',    // FK for WarrantyProduct (this model)
            'category_id'             // FK for Category
        );
    }

    public function priceTemplates()
    {
        return $this->hasMany(PriceTemplate::class);
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
    
}