<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySale extends Model
{
    protected $table = 'daily_sales';

    protected $fillable = [
        'date',
        'retailer_id',
        'product_id',
        'category_id',
        'company_id',
        'total_sales',
        'total_amount'
    ];
}