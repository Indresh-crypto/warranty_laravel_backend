<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZohoItem extends Model
{
    protected $fillable = [
        'name',
        'zoho_item_id',
        'description',
        'rate',
        'product_type',
        'product_id',
        'sac_code'
    ];
}