<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PriceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'warranty_product_id',	
        'min_price',	
        'max_price',	
        'company_payout',
        'emp_payout',	
        'retailer_payout',	
        'other_payout',
        'company_id',
        'product_price',
        'is_fixed',
        'is_percent',
        'product_type'
    ];

/*
    public function warrantyProduct()
    {
        return $this->belongsTo(WarrantyProduct::class);
    }
    */
    public function product()
    {
        return $this->belongsTo(WarrantyProduct::class, 'warranty_product_id');
    }
    
    public function priceTemplates()
    {
        return $this->hasMany(PriceTemplate::class, 'warranty_product_id');
    }
    
    public function warrantyProduct()
    {
        return $this->belongsTo(WarrantyProduct::class, 'warranty_product_id');
    }
    
    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'category_product',
            'warranty_product_id',
            'category_id'
        );
    }
    
    public function subscribedPackages()
    {
        return $this->hasMany(SubscribedPackage::class, 'package_id');
    }
}
