<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class WCustomerAddress extends Model
{
    protected $table = 'w_customer_addresses';

    protected $fillable = [
        'w_customer_id',
        'name',
        'mobile',
        'address1',
        'address2',
        'city',
        'state',
        'pincode',
        'lat',
        'lng'
    ];
}