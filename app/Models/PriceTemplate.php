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
        'is_percent'
    ];

    public function warrantyProduct()
    {
        return $this->belongsTo(WarrantyProduct::class);
    }
    public function product()
{
    return $this->belongsTo(WarrantyProduct::class, 'warranty_product_id');
}
}
