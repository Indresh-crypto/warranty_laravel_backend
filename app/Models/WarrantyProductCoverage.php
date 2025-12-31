<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarrantyProductCoverage extends Model
{
    use HasFactory;

    protected $table = 'w_product_coverages';

    protected $fillable = [
        'warranty_product_id',
        'title',
        'description',
        'status'
    ];

    public function product()
    {
        return $this->belongsTo(WarrantyProduct::class, 'warranty_product_id');
    }
}
