<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $table = 'price_lists';

    protected $fillable = [
        'model_id',
        'brand_id',
        'model_name',
        'brand_name',
        'package_name',
        'price',
        'mop',
        'claims',
        'validity_days'
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}