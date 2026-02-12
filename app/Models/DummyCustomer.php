<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DummyCustomer extends Model
{
    protected $table = 'dummy_customers';

    protected $fillable = [
        'name',
        'address',
        'mobile',
        'imei1',
        'imei2',
        'brand',
        'fcm_token',
        'is_mapped',
        'brand',
        'model',
        'fcm_token',
        'last_sync',
        'last_report',
        'lat',
        'lng',
        'retailer_id',
        'retailer_code'
    ];
}