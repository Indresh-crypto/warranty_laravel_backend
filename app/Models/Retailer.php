<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Retailer extends Model
{
    protected $table = 'retailers';

    protected $fillable = [
        'company_id',
        'retailer_code',
        'business_name',
        'owner_name',
        'email',
        'phone',
        'password',

        'address_line1',
        'address_line2',
        'state',
        'district',
        'city',
        'pincode',

        'bank_name',
        'account_no',
        'ifsc_code',
        'branch_name',
        'gst_number',
        'pan_number',
        'business_type',
        'trade_name',
        'status',
        'is_verified'
    ];

    protected $hidden = ['password'];
}