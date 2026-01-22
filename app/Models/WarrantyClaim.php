<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class WarrantyClaim extends Model
{
    protected $fillable = [
        'w_customer_id',
        'w_device_id',
        'company_id',
        'claim_type',
        'drop_retailer_id',
        'pickup_address_id',
        'issue_description',
        'status',
        'otp',
        'reason_id',
        'inspection_report',
        'estimate_amount',
        'payable_amount',
        'payment_link',
        'payment_status',
        'inspection_remark',
        'claim_code',
        'agent_id'
    ];

    public function photos()
    {
        return $this->hasMany(WarrantyClaimPhoto::class);
    }

    public function customer()
    {
        return $this->belongsTo(WCustomer::class, 'w_customer_id');
    }

    public function device()
    {
        return $this->belongsTo(WDevice::class, 'w_device_id');
    }
    public function pickupAddress()
    {
        return $this->belongsTo(WCustomerAddress::class, 'pickup_address_id');
    }
    
    public function dropRetailer()
    {
        return $this->belongsTo(Company::class, 'drop_retailer_id');
    }
    public function assignment()
    {
        return $this->hasOne(WarrantyClaimAssignment::class, 'warranty_claim_id');
    }

    public function coverages()
    {
        return $this->hasMany(WarrantyClaimCoverage::class, 'warranty_claim_id');
    }
    public function reason()
    {
        return $this->belongsTo(ClaimReason::class, 'reason_id');
    }

}
