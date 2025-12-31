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
        'issue_description',
        'status',
        'otp'
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

}
