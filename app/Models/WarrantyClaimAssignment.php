<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class WarrantyClaimAssignment extends Model
{
    protected $fillable = [
        'warranty_claim_id',
        'employee_id',
        'pickup_otp',
        'delivery_otp'
    ];
}
