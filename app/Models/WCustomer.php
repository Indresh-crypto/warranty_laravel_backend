<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WCustomer extends Model
{
    use HasFactory;

    protected $table = 'w_customers';

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'state',
        'city',
        'pincode',
        'location',
        'retailer_id',
        'created_at',
        'updated_at',
        'created_by',
        'address1',
        'address2',
        'company_id',
        'agent_id',
        'c_code',
        'otp_expires_at',
        'is_email_verified',
        'otp'

    ];

    // ✅ Customer has many addresses
    public function addresses()
    {
        return $this->hasMany(WCustomerAddress::class, 'w_customer_id');
    }
 
    public function devices()
    {
        return $this->hasMany(WDevice::class, 'w_customer_id', 'id');
    }
    public function retailer()
    {
        return $this->belongsTo(Company::class, 'retailer_id');
    }

}