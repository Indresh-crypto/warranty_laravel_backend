<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory;

    protected $table = "category";

    public $timestamps= false;
    protected $fillable = [
       'name', 'image','status', 'description'
    ];

    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'brand_category');
    }
    
 
    
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            WarrantyProduct::class,
            'category_product',
            'category_id',            // FK for Category
            'warranty_product_id'     // FK for WarrantyProduct
        );
    }
}
