<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewayKey extends Model
{
    protected $fillable = [
        'key_id',
        'key_secret'
    ];

}