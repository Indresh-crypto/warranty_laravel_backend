<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class WarrantyClaimAssignment extends Model
{
    protected $fillable = [
        'warranty_claim_id',
        'employee_id',
        'pickup_otp',
        'delivery_otp',
        'assigned_by',
        'pickup_verified'
    ];
    
     public function claim()
    {
        return $this->belongsTo(WarrantyClaim::class, 'warranty_claim_id');
    }
}
